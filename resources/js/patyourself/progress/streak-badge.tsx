import { Icon } from '@/patyourself/primitives';
import type { LoopStreak } from '@/patyourself/types';

/**
 * A run of completed occasions, shown only while it is running.
 *
 * When it breaks, this renders nothing at all — no reset, no zero, and no
 * instruction to start again. It previously said "{n} missed — restart" on a
 * failed run and "No streak yet" otherwise, which counted a loss back at the
 * user on the day they least wanted it and implied a run was owed.
 *
 * A streak is a statistic. It is not a score, and it cannot be lost here.
 */
export function StreakBadge({ streak }: { streak: LoopStreak }) {
    if (streak.outcome !== 'completed' || streak.length === 0) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1 text-sm font-medium text-primary">
            <Icon name="trending-up" size={16} />
            {streak.length} in a row
        </span>
    );
}
