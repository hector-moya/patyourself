import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { OutcomeStrip } from '@/patyourself/progress/outcome-strip';
import { StreakBadge } from '@/patyourself/progress/streak-badge';
import {
    SectionHeading,
    StrategyTimeline,
} from '@/patyourself/strategy-timeline';
import type { LoopProgressDetail, StrategyData } from '@/patyourself/types';

interface ProgressShowProps {
    intention: LoopProgressDetail;
    strategies: StrategyData[];
    summary: string | null;
}

/**
 * Progress detail — one loop's full metrics (streak, completion rate, totals,
 * sparkline), its versioned strategy journey (reused timeline), and the coach's
 * rolling narrative. Read-only; back-links to the progress index.
 */
export default function ProgressShow({
    intention,
    strategies,
    summary,
}: ProgressShowProps) {
    const { totals } = intention;

    const back = (
        <Link
            href="/progress"
            className="-ml-1 flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            aria-label="Back to progress"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );

    return (
        <CoachLayout
            title={intention.title}
            headerLeading={back}
            bottomNav={<BottomNav />}
        >
            <div className="flex flex-col gap-6">
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2">
                        <StreakBadge streak={intention.streak} />
                        <span className="text-sm font-medium text-foreground">
                            {intention.completion_rate === null
                                ? '—'
                                : `${intention.completion_rate}% complete`}
                        </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {totals.completed} done · {totals.failed} missed ·{' '}
                        {totals.skipped} skipped
                    </p>
                    <OutcomeStrip recent={intention.recent} />
                </section>

                <StrategyTimeline strategies={strategies} />

                <section>
                    <SectionHeading>Coach summary</SectionHeading>
                    {summary ? (
                        <p className="text-sm whitespace-pre-line text-foreground">
                            {summary}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Your coach hasn't summarized this loop yet.
                        </p>
                    )}
                </section>
            </div>
        </CoachLayout>
    );
}
