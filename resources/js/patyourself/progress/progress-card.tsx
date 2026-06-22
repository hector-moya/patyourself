import { Link } from '@inertiajs/react';

import type { LoopProgressCard } from '@/patyourself/types';
import { OutcomeStrip } from './outcome-strip';
import { StreakBadge } from './streak-badge';

/** One active loop on the progress index — metrics + a one-line narrative, linking to its detail. */
export function ProgressCard({ loop }: { loop: LoopProgressCard }) {
    return (
        <Link
            href={`/progress/${loop.id}`}
            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 transition-colors hover:border-primary/40"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-sm font-medium text-foreground">
                    {loop.title}
                </span>
                <span className="shrink-0 text-sm text-muted-foreground">
                    {loop.completion_rate === null
                        ? '—'
                        : `${loop.completion_rate}%`}
                </span>
            </div>
            <StreakBadge streak={loop.streak} />
            <OutcomeStrip recent={loop.recent} />
            {loop.summary_excerpt && (
                <p className="line-clamp-1 text-xs text-muted-foreground">
                    {loop.summary_excerpt}
                </p>
            )}
        </Link>
    );
}
