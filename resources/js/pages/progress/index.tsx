import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { CoachUsageCard } from '@/patyourself/progress/coach-usage-card';
import { ProgressCard } from '@/patyourself/progress/progress-card';
import type { CoachUsageSnapshot, LoopProgressCard } from '@/patyourself/types';

interface ProgressIndexProps {
    loops: LoopProgressCard[];
    usage: CoachUsageSnapshot;
}

/**
 * Progress dashboard — the account's coach-usage card, then a stack of
 * active-loop metric cards (streak, completion rate, recent-activity sparkline,
 * narrative snippet), each linking to the loop's detail. Read-only.
 */
export default function ProgressIndex({ loops, usage }: ProgressIndexProps) {
    return (
        <CoachLayout title="Progress" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-3">
                <CoachUsageCard usage={usage} />
                {loops.length === 0 ? (
                    <EmptyState />
                ) : (
                    <ul className="flex flex-col gap-3">
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
                No active loops yet.
            </p>
            <Link
                href="/dashboard"
                className="text-sm font-medium text-primary"
            >
                Start a loop with your coach
            </Link>
        </div>
    );
}
