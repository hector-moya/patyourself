import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ProgressCard } from '@/patyourself/progress/progress-card';
import type { LoopProgressCard } from '@/patyourself/types';

interface ProgressIndexProps {
    loops: LoopProgressCard[];
}

/**
 * Progress dashboard — a stack of active-loop metric cards (streak, completion
 * rate, recent-activity sparkline, narrative snippet), each linking to the
 * loop's detail. Read-only.
 */
export default function ProgressIndex({ loops }: ProgressIndexProps) {
    return (
        <CoachLayout title="Progress" bottomNav={<BottomNav />} wide>
            <div className="flex flex-col gap-3 lg:gap-5">
                {loops.length === 0 ? (
                    <EmptyState />
                ) : (
                    <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:gap-4 xl:grid-cols-3">
                        {loops.map((loop) => (
                            <li key={loop.id}>
                                <ProgressCard loop={loop} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </CoachLayout>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-8 text-center">
            <p className="text-sm text-muted-foreground">
                No active loops yet. New loops are created by talking to Claude
                through the PatYourSelf connector.
            </p>
            <Link
                href="/loops"
                className="text-sm font-medium text-primary"
            >
                View your loops
            </Link>
        </div>
    );
}
