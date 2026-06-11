import { useCallback, useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';
import type {
    ChatMessage,
    IntentionData,
    LogOutcome,
    ThreadMessage,
} from '@/patyourself/types';
import { ActionCard } from './action-card';
import { httpCoachClient } from './coach-client';
import type { CoachClient } from './coach-client';

let counter = 0;
const nextId = (): string => `m${++counter}`;

const FALLBACK_REPLY =
    "I couldn't reach the coach just now — give that another try in a moment.";

/**
 * The chat thread. Seeds either from a server-provided conversation history
 * (`initialThread`) or a synthetic greeting, then appends one inline action card
 * per active loop. Drives the live loop: a sent message posts to the coach
 * (POST /chat) and appends the reply plus any authored cards; logging an outcome
 * posts to the action's log endpoint and reflects the result back as a new
 * coaching turn.
 *
 * All I/O goes through an injected {@see CoachClient}, so this hook stays pure
 * UI state and is exercised in tests with a fake client.
 *
 * Signature: useChatThread(initialIntentions, initialThread, client)
 */
export function useChatThread(
    initialIntentions: IntentionData[],
    initialThread: ThreadMessage[] = [],
    client: CoachClient = httpCoachClient,
) {
    const [messages, setMessages] = useState<ChatMessage[]>(() =>
        seedThread(initialIntentions, initialThread),
    );

    const converse = useCallback(
        async (text: string): Promise<void> => {
            const trimmed = text.trim();

            if (!trimmed) {
                return;
            }

            setMessages((prev) => [
                ...prev,
                { id: nextId(), role: 'user', text: trimmed },
            ]);

            try {
                const reply = await client.sendMessage(trimmed);

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

/**
 * Build the initial message list. When the server supplies stored history,
 * map those turns directly (keeping their server ids) and append loop cards.
 * Otherwise emit a synthetic greeting plus loop cards.
 */
function seedThread(
    intentions: IntentionData[],
    initialThread: ThreadMessage[],
): ChatMessage[] {
    const cards: ChatMessage[] = intentions.map((intention) => ({
        id: nextId(),
        role: 'card',
        intention,
    }));

    if (initialThread.length > 0) {
        return [
            ...initialThread.map(
                (m): ChatMessage => ({ id: m.id, role: m.role, text: m.text }),
            ),
            ...cards,
        ];
    }

    const greeting: ChatMessage = {
        id: nextId(),
        role: 'coach',
        text: intentions.length
            ? `You have ${intentions.length} ${intentions.length === 1 ? 'loop' : 'loops'} going. Want to run through them, or just give me a quick all-clear?`
            : "Let's build your first loop. Tell me a habit you want to start or stop.",
    };

    return [greeting, ...cards];
}

/** Starter habits a fresh account can tap instead of facing a blank composer. */
const FIRST_LOOP_SUGGESTIONS = [
    'I want to read before bed instead of scrolling',
    'Help me get up when my alarm goes off',
    'I want to stop snacking after dinner',
];

export function ChatThread({
    messages,
    onLog,
    onSuggest,
}: {
    messages: ChatMessage[];
    onLog?: (
        intention: IntentionData,
        outcome: LogOutcome,
        reason?: string,
    ) => void;
    onSuggest?: (text: string) => void;
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

            {onSuggest &&
                !messages.some(
                    (message) =>
                        message.role === 'user' || message.role === 'card',
                ) && <FirstLoopSuggestions onPick={onSuggest} />}

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

/**
 * The fresh-account welcome: explains that the coach builds loops from a plain
 * sentence and offers tappable starters so the user never faces a blank
 * composer. Disappears the moment the user sends anything (or a loop card lands)
 * — the conversation takes it from there.
 */
function FirstLoopSuggestions({ onPick }: { onPick: (text: string) => void }) {
    return (
        <div className="rounded-2xl border border-border bg-card/70 p-5 shadow-sm">
            <p className="font-mono text-[0.7rem] tracking-[0.16em] text-primary/80 uppercase">
                New here
            </p>
            <h3 className="mt-1.5 font-display text-xl font-semibold text-foreground">
                Start your first loop
            </h3>
            <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                Tell the coach a habit you want to build or break — it designs
                the loop for you. Tap one to see how it works:
            </p>
            <div className="mt-4 flex flex-col gap-2">
                {FIRST_LOOP_SUGGESTIONS.map((suggestion) => (
                    <button
                        key={suggestion}
                        type="button"
                        onClick={() => onPick(suggestion)}
                        className="group flex items-center justify-between gap-3 rounded-xl border border-border bg-background px-4 py-2.5 text-left text-sm text-foreground transition-all hover:border-primary/50 hover:bg-primary/5"
                    >
                        <span>{suggestion}</span>
                        <span
                            aria-hidden
                            className="text-primary opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100"
                        >
                            →
                        </span>
                    </button>
                ))}
            </div>
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
                What got in the way of "{title}"?
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
