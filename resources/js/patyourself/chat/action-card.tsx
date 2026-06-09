import { Link } from '@inertiajs/react';

import type { IntentionData } from '@/patyourself/types';

/**
 * Inline action card — renders an LLM-authored Intention loop inside the chat
 * thread. Tapping it opens the loop's detail screen. This is the minimal,
 * shared rendering; the richer card (quick log buttons, habit anatomy) is built
 * out as a dedicated component in Task 21.
 */
export function ActionCard({ intention }: { intention: IntentionData }) {
    const tactic = intention.strategy?.approach ?? intention.response;

    return (
        <Link
            href={`/intentions/${intention.id}`}
            className="block w-full max-w-[85%] rounded-2xl border border-border bg-card p-4 text-left shadow-sm transition-colors hover:border-foreground/20 hover:bg-accent/40"
        >
            <div className="flex items-center justify-between gap-2">
                <span className="truncate font-semibold text-foreground">
                    {intention.title}
                </span>
                <span className="shrink-0 rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
                    {intention.type}
                </span>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{tactic}</p>
            <span className="mt-3 inline-block text-xs font-medium text-primary">
                View loop →
            </span>
        </Link>
    );
}
