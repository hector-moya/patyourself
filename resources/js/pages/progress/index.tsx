import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ProgressCard } from '@/patyourself/progress/progress-card';
import type { LoopProgressCard, ProgressSummary } from '@/patyourself/types';

interface ProgressIndexProps {
    loops: LoopProgressCard[];
    summary: ProgressSummary;
}

/**
 * The record, read-only: what every running version has come to.
 *
 * Split into what is recording and what has nothing to show yet, rather than
 * one list ordered by rate. A loop four days old is not losing to one that has
 * been running a month, and sorting them against each other would say it was.
 *
 * There is no score on this screen and no target anywhere on it. Every figure
 * is a count of occasions with its own denominator visible, because a
 * percentage with the denominator hidden is the one way this page could lie —
 * three out of four and thirty out of forty are not the same evidence.
 */
export default function ProgressIndex({ loops, summary }: ProgressIndexProps) {
    const recording = loops.filter((loop) => loop.completion_rate !== null);
    const quiet = loops.filter((loop) => loop.completion_rate === null);

    return (
        <CoachLayout
            title="Progress"
            header={
                <div className="t-head">
                    <div>
                        <p className="t-date">What the record shows</p>
                        <h1 className="t-day">Progress</h1>
                    </div>
                    {loops.length > 0 && (
                        <p className="t-tally">
                            {loops.length}{' '}
                            {loops.length === 1 ? 'loop' : 'loops'} recording
                        </p>
                    )}
                </div>
            }
            flush
            bottomNav={<BottomNav />}
        >
            <div className="t-body">
                <div className="t-col t-col--wide">
                    {loops.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <>
                            <Lede summary={summary} />

                            {recording.length > 0 && (
                                <>
                                    <h2 className="p-sech">Recording</h2>
                                    <CardGrid loops={recording} />
                                </>
                            )}

                            {quiet.length > 0 && (
                                <>
                                    <h2 className="p-sech">
                                        Nothing to show yet
                                    </h2>
                                    <CardGrid loops={quiet} />
                                </>
                            )}

                            <Legend />
                        </>
                    )}
                </div>
            </div>
        </CoachLayout>
    );
}

function CardGrid({ loops }: { loops: LoopProgressCard[] }) {
    return (
        <ul className="p-grid">
            {loops.map((loop) => (
                <li key={loop.id}>
                    <ProgressCard loop={loop} />
                </li>
            ))}
        </ul>
    );
}

/**
 * The one top-level answer, and what it was worked out from.
 *
 * The provenance line under it is not decoration: it is the denominator the
 * headline is drawn from, and the skipped count that is deliberately not in
 * it. Stating both is what stops "held" from being a number nobody can check.
 */
function Lede({ summary }: { summary: ProgressSummary }) {
    const { held, decided, skipped, versions_running: running } = summary;

    return (
        <>
            <p className="p-lede">
                {decided === 0 ? (
                    'Nothing has been decided yet — the record starts on the first occasion you log.'
                ) : (
                    <>
                        Of every occasion you have decided,{' '}
                        <b>
                            {held} of {decided} held
                        </b>
                        . {aheadClause(summary)}
                    </>
                )}
            </p>
            <p className="p-prov">
                {decided} decided · {skipped} skipped ·{' '}
                {running === 1 ? '1 version' : `${running} versions`} running
            </p>
        </>
    );
}

/**
 * Whether the running versions are beating what they replaced.
 *
 * Counted on the server and reported as counted. The design's line asserts
 * "both running versions are ahead of the ones they replaced" — true of its
 * fixture and of nothing else, so here it is stated only when it holds, and a
 * mixed or losing picture gets said out loud instead. A revision that did not
 * help is the finding, not a failure to hide.
 */
function aheadClause({
    versions_ahead: ahead,
    versions_running: running,
}: ProgressSummary): string {
    if (running === 0 || ahead === 0) {
        return '';
    }

    if (ahead === running) {
        return running === 1
            ? 'The running version is ahead of the one it replaced.'
            : 'Both running versions are ahead of the ones they replaced.';
    }

    return `${ahead} of ${running} running versions are ahead of what they replaced.`;
}

/**
 * What the three marks mean. Spelled out rather than left to colour alone —
 * the skipped cell in particular reads as "missing" if you have not been told
 * it is deliberate, and it is the one mark that decides nothing.
 */
function Legend() {
    return (
        <div className="p-legend">
            <span>
                <i className="held" />
                Held
            </span>
            <span>
                <i className="failed" />
                Did not hold
            </span>
            <span>
                <i className="skipped" />
                Skipped · never counted
            </span>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-8 text-center">
            <p className="text-sm text-muted-foreground">
                No active loops yet. New loops are created by talking to Claude
                through the PatYourSelf connector.
            </p>
            <Link href="/loops" className="text-sm font-medium text-primary">
                View your loops
            </Link>
        </div>
    );
}
