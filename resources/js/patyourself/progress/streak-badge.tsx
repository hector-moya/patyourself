import { Icon } from '@/patyourself/primitives';
import type { LoopStreak } from '@/patyourself/types';

/** The streak pill: a win run (▲, primary), a miss run (▽, muted caution), or none. */
export function StreakBadge({ streak }: { streak: LoopStreak }) {
    if (streak.outcome === 'completed' && streak.length > 0) {
        return (
            <span className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                <Icon name="trending-up" size={16} />
                {streak.length} in a row
            </span>
        );
    }

    if (streak.outcome === 'failed' && streak.length > 0) {
        return (
            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                <Icon name="trending-down" size={16} />
                {streak.length} missed — restart
            </span>
        );
    }

    return <span className="text-sm text-muted-foreground">No streak yet</span>;
}
