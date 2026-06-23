import { cn } from '@/lib/utils';
import type { CoachUsageSnapshot } from '@/patyourself/types';

/** Purposes that make up the background auto-coaching pass (vs interactive `coach` chat). */
const AUTO_COACHING_PURPOSES = ['summarizer', 'strategist'];

/**
 * The account-level coach token usage for today: used / budget / remaining with a
 * bar, plus an auto-coaching-vs-chat breakdown. Sits above the per-loop cards on
 * the progress index. Read-only.
 */
export function CoachUsageCard({ usage }: { usage: CoachUsageSnapshot }) {
    const capped = usage.budget > 0;
    const overBudget = capped && usage.remaining === 0;
    const pct = capped
        ? Math.min(100, Math.round((usage.used / usage.budget) * 100))
        : 0;

    const autoCoaching = AUTO_COACHING_PURPOSES.reduce(
        (sum, purpose) => sum + (usage.breakdown[purpose] ?? 0),
        0,
    );
    const chat = usage.breakdown['coach'] ?? 0;

    return (
        <section
            data-testid="coach-usage-card"
            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-foreground">
                    Coach usage today
                </span>
                <span
                    className={cn(
                        'text-sm tabular-nums',
                        overBudget
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {usage.used.toLocaleString()}
                    {capped ? ` / ${usage.budget.toLocaleString()}` : ''}
                </span>
            </div>

            {capped ? (
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        data-testid="usage-bar"
                        className={cn(
                            'h-full rounded-full',
                            overBudget ? 'bg-destructive' : 'bg-primary',
                        )}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            ) : (
                <span className="text-xs text-muted-foreground">No cap</span>
            )}

            <p className="text-xs text-muted-foreground">
                {!capped
                    ? 'Unlimited budget'
                    : overBudget
                      ? 'Over budget — auto-coaching paused until usage frees up.'
                      : `${usage.remaining!.toLocaleString()} tokens remaining`}
            </p>

            <p className="text-xs text-muted-foreground">
                Auto-coaching {autoCoaching.toLocaleString()} · Chat{' '}
                {chat.toLocaleString()}
            </p>
        </section>
    );
}
