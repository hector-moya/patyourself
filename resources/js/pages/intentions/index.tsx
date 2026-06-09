import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import type { IntentionData } from '@/patyourself/types';

interface LoopsIndexProps {
    intentions: IntentionData[];
}

/**
 * Loops list — every loop the user is working, linking through to its detail
 * screen. The habit-anatomy and richer card treatment land in Task 19; this is
 * the routed, navigable scaffold the rest of the app hangs off.
 */
export default function LoopsIndex({ intentions }: LoopsIndexProps) {
    return (
        <CoachLayout title="Loops" bottomNav={<BottomNav />}>
            {intentions.length === 0 ? (
                <EmptyState />
            ) : (
                <ul className="flex flex-col gap-3">
                    {intentions.map((loop) => (
                        <li key={loop.id}>
                            <LoopRow loop={loop} />
                        </li>
                    ))}
                </ul>
            )}
        </CoachLayout>
    );
}

function LoopRow({ loop }: { loop: IntentionData }) {
    return (
        <Link
            href={`/intentions/${loop.id}`}
            className="block rounded-xl border border-border bg-card p-4 transition-colors hover:border-foreground/20 hover:bg-accent/40"
        >
            <div className="flex items-center justify-between gap-3">
                <h2 className="truncate font-semibold text-foreground">
                    {loop.title}
                </h2>
                <span className="shrink-0 rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
                    {loop.type}
                </span>
            </div>
            {loop.strategy && (
                <p className="mt-1 truncate text-sm text-muted-foreground">
                    {loop.strategy.approach}
                </p>
            )}
            <p className="mt-2 text-xs text-muted-foreground/80 capitalize">
                {loop.status}
            </p>
        </Link>
    );
}

function EmptyState() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
            <h2 className="text-lg font-semibold text-foreground">
                No loops yet
            </h2>
            <p className="max-w-xs text-sm text-muted-foreground">
                Start a conversation with the Coach and it will author your
                first habit loop for you.
            </p>
            <Link
                href="/dashboard"
                className="mt-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
            >
                Talk to the Coach
            </Link>
        </div>
    );
}
