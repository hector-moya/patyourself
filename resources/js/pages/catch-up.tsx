import { Link } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { formatOccasionDay } from '@/patyourself/occasion-date';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { PendingOccurrenceData } from '@/patyourself/types';
import VerdictForm from '@/patyourself/verdict-form';
import { WorkflowRecord } from '@/patyourself/workflow-record';
import { store as storeOccurrenceLog } from '@/routes/occurrences/logs';

interface CatchUpProps {
    occurrences: PendingOccurrenceData[];
    showing_all: boolean;
}

/**
 * Occasions that passed without an outcome, grouped by loop.
 *
 * Deliberately quiet. There is no count, no badge and no overdue state
 * anywhere here or on the link that leads to it: an occasion stays loggable
 * forever, and a backlog is a record of what has not been written down, not a
 * debt to be settled.
 */
export default function CatchUp({
    occurrences,
    showing_all: showingAll,
}: CatchUpProps) {
    const byLoop = groupByLoop(occurrences);

    return (
        <CoachLayout title="Catch up" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-6">
                {occurrences.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing waiting to be logged.
                    </p>
                ) : (
                    byLoop.map((group) => (
                        <section key={group.loopId}>
                            <SectionHeading>{group.loopTitle}</SectionHeading>
                            <ul className="flex flex-col divide-y divide-border">
                                {group.occurrences.map((occurrence) => (
                                    <CatchUpRow
                                        key={occurrence.id}
                                        occurrence={occurrence}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))
                )}

                {!showingAll && (
                    <Link
                        href="/catch-up?since=all"
                        className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                    >
                        Show everything further back
                    </Link>
                )}
            </div>
        </CoachLayout>
    );
}

function CatchUpRow({ occurrence }: { occurrence: PendingOccurrenceData }) {
    /**
     * Seeded from `occurrence.id`, which is never null here — every catch-up
     * row already names a real, materialised occurrence, unlike a dashboard
     * row that can still be anchored to a slot that does not exist yet. Held
     * as state anyway so this row honours the same
     * `onOccurrenceMaterialised` contract `WorkflowRecordProps` puts on every
     * recording surface, mirroring `dashboard.tsx`'s `OccasionRow`.
     */
    const [occurrenceId, setOccurrenceId] = useState(occurrence.id);

    return (
        <li className="py-3">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm text-foreground">
                    {occurrence.action_title}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                    {formatOccasionDay(occurrence.scheduled_for)}
                </span>
            </div>

            <WorkflowRecord
                workflow={occurrence.workflow}
                occurrenceId={occurrenceId}
                actionId={occurrence.action_id}
                onOccurrenceMaterialised={setOccurrenceId}
            />

            <VerdictForm action={storeOccurrenceLog.url(occurrenceId)} />
        </li>
    );
}

interface LoopGroup {
    loopId: number;
    loopTitle: string;
    occurrences: PendingOccurrenceData[];
}

function groupByLoop(occurrences: PendingOccurrenceData[]): LoopGroup[] {
    const groups: LoopGroup[] = [];

    for (const occurrence of occurrences) {
        const existing = groups.find((g) => g.loopId === occurrence.loop_id);

        if (existing) {
            existing.occurrences.push(occurrence);
        } else {
            groups.push({
                loopId: occurrence.loop_id,
                loopTitle: occurrence.loop_title,
                occurrences: [occurrence],
            });
        }
    }

    return groups;
}
