import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { Icon } from '@/patyourself/primitives';
import type { LoopProgressCard } from '@/patyourself/types';
import { OutcomeStrip } from './outcome-strip';

/**
 * One loop on the progress index: what its running version has come to, set
 * against the version it replaced.
 *
 * The figures are the running version's, not the loop's lifetime — see
 * `LoopProgressCard`. That is what makes the comparison line a real claim
 * rather than a number measured partly against itself.
 *
 * Nothing here is a target. There is no bar to fill, no goal line and no
 * colour that means "not enough": the rate is reported at the same weight
 * whatever it is, because a version that held 40% of the time is evidence
 * about the strategy, not a mark out of ten for the person.
 */
export function ProgressCard({ loop }: { loop: LoopProgressCard }) {
    const { completed, failed, skipped } = loop.totals;
    const decided = completed + failed;
    const undecided = loop.completion_rate === null;
    const running =
        loop.streak.outcome === 'completed' && loop.streak.length > 0;

    return (
        <Link
            // Straight to the lab record. /progress/{id} still resolves, but it
            // is now a redirect, and there is no reason to spend a round trip.
            href={`/loops/${loop.id}`}
            // `cn`, not a template literal: the separating space in
            // `${cond ? ' is-quiet' : ''}` is invisible, and losing it yields
            // `p-cardis-quiet` — a class that matches nothing, on a card that
            // still renders perfectly.
            className={cn('p-card', undecided && 'is-quiet')}
        >
            {/* Divs, not spans: these hold a heading and a paragraph, and a
                span may only contain phrasing content. Valid inside the anchor,
                which takes flow content. */}
            <div className="p-top">
                <h3 className="p-title">{loop.title}</h3>
                <span className="p-open" aria-hidden="true">
                    <Icon name="arrow-up-right" size={18} />
                </span>
            </div>

            <p className="p-meta">{metaLine(loop)}</p>

            {undecided ? (
                <p className="p-none">
                    No occasions decided yet.
                    <span>the record starts on the first one</span>
                </p>
            ) : (
                <>
                    <OutcomeStrip recent={loop.recent} />

                    <div className="p-read">
                        <b className="p-rate">
                            {completed}
                            <span className="p-den">/{decided}</span>
                        </b>
                        <span className="p-of">
                            occasions held · {loop.completion_rate}%
                        </span>
                        <p className="p-vs">
                            {comparison(loop)}
                            {skipped > 0 &&
                                ` · ${skipped} skipped, not counted`}
                            {running && ` · ${loop.streak.length} in a row`}
                        </p>
                    </div>

                    {loop.summary_excerpt && (
                        <p className="p-say">{loop.summary_excerpt}</p>
                    )}
                </>
            )}
        </Link>
    );
}

/** "reduce · v2 · day 22", dropping whichever parts do not apply. */
function metaLine(loop: LoopProgressCard): string {
    const parts = [loop.type];

    if (loop.version !== null) {
        parts.push(`v${loop.version}`);
    }

    if (loop.day_of_experiment !== null) {
        parts.push(`day ${loop.day_of_experiment}`);
    }

    return parts.join(' · ');
}

/**
 * What this version is being measured against.
 *
 * Stated, never assumed: the design's line reads "this version is ahead", and
 * it only gets to say that when the numbers say it. Behind and level are
 * ordinary results — a revision that did not help is exactly the finding the
 * experiment was run to get, and hiding it would make the comparison
 * decorative.
 */
function comparison(loop: LoopProgressCard): string {
    if (loop.version === null) {
        return 'no experiment running · the record continues';
    }

    if (loop.previous_version === null) {
        return 'first version · nothing to compare against yet';
    }

    const { version, rate } = loop.previous_version;
    const mine = loop.completion_rate ?? 0;
    const verdict =
        mine > rate
            ? 'this version is ahead'
            : mine < rate
              ? 'this version is behind'
              : 'level with it so far';

    return `v${version} held ${rate}% · ${verdict}`;
}
