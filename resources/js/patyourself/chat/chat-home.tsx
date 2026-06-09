import { useCallback, useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';
import type {
    ChatMessage,
    IntentionData,
    LogOutcome,
} from '@/patyourself/types';
import { ActionCard } from './action-card';
import { httpCoachClient } from './coach-client';
import type { CoachClient, CoachTurn } from './coach-client';

let counter = 0;
const nextId = (): string => `m${++counter}`;

const FALLBACK_REPLY =
    "I couldn't reach the coach just now — give that another try in a moment.";

/**
 * The chat thread. Seeds a greeting plus one inline action card per active loop,
 * then drives the live loop: a sent message posts to the coach (POST /chat) and
 * appends the reply plus any authored cards; logging an outcome posts to the
 * action's log endpoint and reflects the result back as a new coaching turn.
 *
 * All I/O goes through an injected {@see CoachClient}, so this hook stays pure
 * UI state and is exercised in tests with a fake client.
 */
export function useChatThread(
    initialIntentions: IntentionData[],
    client: CoachClient = httpCoachClient,
) {
    const [messages, setMessages] = useState<ChatMessage[]>(() =>
        seedThread(initialIntentions),
    );

    // Mirror committed messages so a turn can read prior history without
    // threading it through every call.
    const historyRef = useRef<ChatMessage[]>(messages);
    useEffect(() => {
        historyRef.current = messages;
    }, [messages]);

    const converse = useCallback(
        async (text: string): Promise<void> => {
            const trimmed = text.trim();

            if (!trimmed) {
                return;
            }

            const history = toHistory(historyRef.current);

            setMessages((prev) => [
                ...prev,
                { id: nextId(), role: 'user', text: trimmed },
            ]);

            try {
                const reply = await client.sendMessage(trimmed, history);

                setMessages((prev) => [
                    ...prev,
                    { id: nextId(), role: 'coach', text: reply.message },
                    ...reply.cards.map(
                        (card): ChatMessage => ({
                            id: nextId(),
                            role: 'card',
                            intention: card.intention,
                        }),
                    ),
                ]);
            } catch {
                setMessages((prev) => [
                    ...prev,
                    { id: nextId(), role: 'coach', text: FALLBACK_REPLY },
                ]);
            }
        },
        [client],
    );

    const send = useCallback(
        (raw: string): void => {
            void converse(raw);
        },
        [converse],
    );

    const log = useCallback(
        async (
            intention: IntentionData,
            outcome: LogOutcome,
            reason?: string,
        ): Promise<void> => {
            const action = intention.active_action;

            if (!action) {
                return;
            }

            await client.logOutcome(action.id, outcome, reason);
            await converse(acknowledgement(action.title, outcome, reason));
        },
        [client, converse],
    );

    return { messages, send, log };
}

/** Map the visible thread to the role/content history the coach expects. */
function toHistory(messages: ChatMessage[]): CoachTurn[] {
    return messages
        .flatMap((message): CoachTurn[] =>
            message.role === 'card'
                ? []
                : [
                      {
                          role: message.role === 'user' ? 'user' : 'assistant',
                          content: message.text,
                      },
                  ],
        )
        .slice(-50);
}

/** The user-voiced note that reflects a logged outcome back to the coach. */
function acknowledgement(
    title: string,
    outcome: LogOutcome,
    reason?: string,
): string {
    switch (outcome) {
        case 'completed':
            return `Done: ${title}`;
        case 'skipped':
            return `Skipping: ${title}`;
        case 'failed':
            return reason ? `Missed: ${title} — ${reason}` : `Missed: ${title}`;
    }
}

function seedThread(intentions: IntentionData[]): ChatMessage[] {
    const greeting: ChatMessage = {
        id: nextId(),
        role: 'coach',
        text: intentions.length
            ? `You have ${intentions.length} ${intentions.length === 1 ? 'loop' : 'loops'} going. Want to run through them, or just give me a quick all-clear?`
            : "Let's build your first loop. Tell me a habit you want to start or stop.",
    };

    const cards: ChatMessage[] = intentions.map((intention) => ({
        id: nextId(),
        role: 'card',
        intention,
    }));

    return [greeting, ...cards];
}

export function ChatThread({
    messages,
    onLog,
}: {
    messages: ChatMessage[];
    onLog?: (
        intention: IntentionData,
        outcome: LogOutcome,
        reason?: string,
    ) => void;
}) {
    const endRef = useRef<HTMLDivElement>(null);
    // The loop currently awaiting a failure reason, if any.
    const [reasonFor, setReasonFor] = useState<IntentionData | null>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length]);

    const handleOutcome = (
        intention: IntentionData,
        outcome: LogOutcome,
    ): void => {
        if (outcome === 'failed') {
            setReasonFor(intention);

            return;
        }

        onLog?.(intention, outcome, undefined);
    };

    return (
        <div className="flex flex-col gap-3">
            {messages.map((message) =>
                message.role === 'card' ? (
                    <div key={message.id} className="flex justify-start">
                        <ActionCard
                            intention={message.intention}
                            onLog={
                                onLog && message.intention.active_action
                                    ? (outcome) =>
                                          handleOutcome(
                                              message.intention,
                                              outcome,
                                          )
                                    : undefined
                            }
                        />
                    </div>
                ) : (
                    <Bubble key={message.id} role={message.role}>
                        {message.text}
                    </Bubble>
                ),
            )}

            {reasonFor && (
                <ReasonPrompt
                    title={reasonFor.title}
                    onCancel={() => setReasonFor(null)}
                    onSubmit={(reason) => {
                        onLog?.(reasonFor, 'failed', reason);
                        setReasonFor(null);
                    }}
                />
            )}

            <div ref={endRef} />
        </div>
    );
}

function ReasonPrompt({
    title,
    onSubmit,
    onCancel,
}: {
    title: string;
    onSubmit: (reason: string) => void;
    onCancel: () => void;
}) {
    const [reason, setReason] = useState('');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const trimmed = reason.trim();

        if (trimmed) {
            onSubmit(trimmed);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="max-w-[85%] rounded-2xl border border-border bg-card p-3"
        >
            <label
                htmlFor="log-reason"
                className="text-xs font-medium text-muted-foreground"
            >
                What got in the way of “{title}”?
            </label>
            <input
                id="log-reason"
                type="text"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="A word or two is plenty…"
                className="mt-2 h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-foreground/30"
            />
            <div className="mt-2 flex justify-end gap-2">
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-lg px-3 py-1.5 text-xs text-muted-foreground hover:text-foreground"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={!reason.trim()}
                    className="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground disabled:opacity-40"
                >
                    Save
                </button>
            </div>
        </form>
    );
}

function Bubble({
    role,
    children,
}: {
    role: 'coach' | 'user';
    children: string;
}) {
    const mine = role === 'user';

    return (
        <div className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[85%] rounded-2xl px-4 py-2 text-sm',
                    mine
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-foreground',
                )}
            >
                {children}
            </div>
        </div>
    );
}

export function ChatComposer({ onSend }: { onSend: (text: string) => void }) {
    const [value, setValue] = useState('');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        onSend(value);
        setValue('');
    };

    return (
        <form onSubmit={submit} className="flex items-center gap-2 px-4 py-3">
            <input
                type="text"
                value={value}
                onChange={(event) => setValue(event.target.value)}
                placeholder="Message your coach…"
                className="h-10 flex-1 rounded-full border border-border bg-background px-4 text-sm outline-none focus:border-foreground/30"
                aria-label="Message your coach"
            />
            <button
                type="submit"
                disabled={!value.trim()}
                className="h-10 shrink-0 rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-40"
            >
                Send
            </button>
        </form>
    );
}
