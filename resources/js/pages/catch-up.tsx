import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { formatOccasionDay } from '@/patyourself/occasion-date';
import { Button } from '@/patyourself/primitives';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { LogOutcome, PendingOccurrenceData } from '@/patyourself/types';

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
    const [outcome, setOutcome] = useState<LogOutcome | null>(null);

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

            <Form
                action={`/occurrences/${occurrence.id}/logs`}
                method="post"
                options={{ preserveScroll: true }}
                className="mt-2 flex flex-col gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {OUTCOMES.map((option) => (
                                <label
                                    key={option.value}
                                    className="flex cursor-pointer items-center gap-1.5 rounded-full border border-border px-3 py-1 text-xs text-muted-foreground has-checked:border-primary has-checked:text-foreground"
                                >
                                    <input
                                        type="radio"
                                        name="outcome"
                                        value={option.value}
                                        checked={outcome === option.value}
                                        onChange={() =>
                                            setOutcome(option.value)
                                        }
                                        className="sr-only"
                                    />
                                    {option.label}
                                </label>
                            ))}
                        </div>

                        {/* A failure carries the user's own words, the same rule
                            the tool boundary enforces, for the same reason. */}
                        {outcome === 'failed' && (
                            <div className="flex flex-col gap-1">
                                <textarea
                                    name="reason"
                                    rows={2}
                                    placeholder="What happened, in your words"
                                    aria-label="What happened, in your words"
                                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                />
                                {errors.reason && (
                                    <p className="text-xs text-destructive">
                                        {errors.reason}
                                    </p>
                                )}
                            </div>
                        )}

                        {outcome !== null && (
                            <Button
                                type="submit"
                                disabled={processing}
                                className="self-start"
                            >
                                Log it
                            </Button>
                        )}
                    </>
                )}
            </Form>
        </li>
    );
}

/**
 * `skipped` means the occasion never happened. `failed` means it happened and
 * the strategy did not hold — including simply not thinking about it. Neither
 * label says anything about the person.
 */
const OUTCOMES: { value: LogOutcome; label: string }[] = [
    { value: 'completed', label: 'Did it' },
    { value: 'failed', label: 'Did not hold' },
    { value: 'skipped', label: 'Never happened' },
];

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
