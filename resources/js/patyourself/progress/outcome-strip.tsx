import { cn } from '@/lib/utils';
import type { OutcomeMark } from '@/patyourself/types';

const MARK: Record<
    OutcomeMark,
    { glyph: string; className: string; label: string }
> = {
    completed: { glyph: '●', className: 'text-primary', label: 'completed' },
    failed: { glyph: '×', className: 'text-destructive', label: 'failed' },
    skipped: {
        glyph: '–',
        className: 'text-muted-foreground/60',
        label: 'skipped',
    },
};

/** The recent-activity sparkline: the last N outcomes, oldest → newest. */
export function OutcomeStrip({ recent }: { recent: OutcomeMark[] }) {
    if (recent.length === 0) {
        return <p className="text-xs text-muted-foreground">No activity yet</p>;
    }

    return (
        <div
            data-testid="outcome-strip"
            className="flex items-center gap-1"
            aria-label="Recent activity"
        >
            {recent.map((mark, index) => (
                <span
                    key={index}
                    className={cn('text-sm leading-none', MARK[mark].className)}
                    aria-label={MARK[mark].label}
                    title={MARK[mark].label}
                >
                    {MARK[mark].glyph}
                </span>
            ))}
        </div>
    );
}
