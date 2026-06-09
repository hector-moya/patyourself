import { useCallback, useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';
import type { ChatMessage, IntentionData } from '@/patyourself/types';
import { ActionCard } from './action-card';

let counter = 0;
const nextId = (): string => `m${++counter}`;

/**
 * The chat thread state. Seeds a greeting plus one inline action card per
 * active loop, and handles sending: the user turn appends immediately and a
 * stubbed coach turn follows after a short beat.
 *
 * The stub is the swappable seam — Task 22 replaces `replyTo` with a real
 * POST /chat round-trip that returns the coach's reply and any authored cards.
 */
export function useChatThread(initialIntentions: IntentionData[]) {
    const [messages, setMessages] = useState<ChatMessage[]>(() =>
        seedThread(initialIntentions),
    );

    const send = useCallback((raw: string) => {
        const text = raw.trim();

        if (!text) {
            return;
        }

        setMessages((prev) => [...prev, { id: nextId(), role: 'user', text }]);

        window.setTimeout(() => {
            setMessages((prev) => [
                ...prev,
                { id: nextId(), role: 'coach', text: replyTo() },
            ]);
        }, 320);
    }, []);

    return { messages, send };
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

/** Placeholder coach reply until the real coach is wired in Task 22. */
function replyTo(): string {
    return 'Heard. Want me to pull up a specific loop, or keep talking?';
}

export function ChatThread({ messages }: { messages: ChatMessage[] }) {
    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length]);

    return (
        <div className="flex flex-col gap-3">
            {messages.map((message) =>
                message.role === 'card' ? (
                    <div key={message.id} className="flex justify-start">
                        <ActionCard intention={message.intention} />
                    </div>
                ) : (
                    <Bubble key={message.id} role={message.role}>
                        {message.text}
                    </Bubble>
                ),
            )}
            <div ref={endRef} />
        </div>
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
