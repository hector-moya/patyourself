import { Icon } from '@/patyourself/primitives';
import type { OutcomeMark } from '@/patyourself/types';

const MARK: Record<OutcomeMark, { label: string; cell: string }> = {
    completed: { label: 'held', cell: 'held' },
    failed: { label: 'did not hold', cell: 'failed' },
    skipped: { label: 'skipped', cell: 'skipped' },
};

/**
 * The last ten occasions, oldest → newest.
 *
 * One cell is one occasion — the same unit as the count beneath it, which is
 * the point of drawing it this way. It is not a calendar: two occasions on one
 * day are two cells, and a day with none takes no room at all.
 *
 * Three marks, because there are three outcomes. A skipped occasion is drawn
 * hollow rather than dimmed, because it is not a weaker version of either
 * other mark — it never happened, and it decides nothing either way.
 */
export function OutcomeStrip({ recent }: { recent: OutcomeMark[] }) {
    // The empty state is a distinct element; the outcome-strip testid marks a
    // rendered strip only.
    if (recent.length === 0) {
        return <p className="text-xs text-muted-foreground">No activity yet</p>;
    }

    return (
        <div className="p-strip">
            <div
                data-testid="outcome-strip"
                className="p-cells"
                // One label for the whole strip, not one per cell: read cell by
                // cell this is ten unlabelled boxes, and the shape of the run is
                // the only thing it says.
                role="img"
                aria-label={`The last ${recent.length} occasions, oldest first: ${recent
                    .map((mark) => MARK[mark].label)
                    .join(', ')}`}
            >
                {recent.map((mark, index) => (
                    <div
                        key={index}
                        className={`p-cell p-cell--${MARK[mark].cell}`}
                        title={MARK[mark].label}
                    >
                        {mark === 'completed' && <i className="p-dot" />}
                        {mark === 'failed' && <Icon name="x" size={13} />}
                        {mark === 'skipped' && <i className="p-dash" />}
                    </div>
                ))}
            </div>
            <div className="p-ends">
                <span>
                    the last {recent.length}{' '}
                    {recent.length === 1 ? 'occasion' : 'occasions'}
                </span>
                <span>latest</span>
            </div>
        </div>
    );
}
