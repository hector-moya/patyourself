import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Button } from '@/patyourself/primitives';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { LogOutcome } from '@/patyourself/types';

export interface TodaysOccasionData {
    /** Null for an anchored action with no materialised slot. Decides which
     *  endpoint the row posts to — see `logEndpoint`. */
    occurrence_id: number | null;
    action_id: number;
    loop_id: number;
    loop_title: string;
    title: string;
    description: string | null;
    due: 'due_now' | 'upcoming' | 'anchored';
    scheduled_for: string | null;
}

export interface ReadyForVerdictData {
    loop_id: number;
    loop_title: string;
    version: number;
    intervention_point: string;
    day_of_experiment: number;
    planned_days: number | null;
}

interface DashboardProps {
    today: string;
    occasions: TodaysOccasionData[];
    ready_for_verdict: ReadyForVerdictData[];
}

const SECTIONS: { due: TodaysOccasionData['due']; heading: string }[] = [
    { due: 'due_now', heading: 'Due now' },
    { due: 'upcoming', heading: 'Later today' },
    { due: 'anchored', heading: 'When the cue comes' },
];

/**
 * The daily-driver screen: today, and only today.
 *
 * An occasion missed on an earlier day never appears here — it stays loggable
 * forever on /catch-up. Surfacing it would make the first screen after login a
 * backlog, and there is no count anywhere on this page for the same reason: a
 * day with two occasions and a day with six are not scored against each other.
 *
 * A logged row leaves the screen rather than becoming a tick. What has been
 * dealt with stops asking; the loop's record keeps the outcome.
 */
export default function Dashboard({
    today,
    occasions,
    ready_for_verdict: readyForVerdict,
}: DashboardProps) {
    const nothingToday = occasions.length === 0;

    return (
        <CoachLayout title="Today" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-6">
                <p className="font-mono text-xs tracking-wide text-muted-foreground uppercase">
                    {formatDay(today)}
                </p>

                {nothingToday ? (
                    // A day with nothing scheduled is a normal day, not an
                    // empty one. Stated as a fact, with nothing to act on.
                    <p className="text-sm text-muted-foreground">
                        Nothing due today.
                    </p>
                ) : (
                    SECTIONS.map(({ due, heading }) => {
                        const rows = occasions.filter(
                            (occasion) => occasion.due === due,
                        );

                        // Omitted entirely rather than rendered with a zero.
                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <section key={due}>
                                <SectionHeading>{heading}</SectionHeading>
                                <ul className="divide-y divide-border">
                                    {rows.map((occasion) => (
                                        <OccasionRow
                                            key={`${occasion.action_id}-${occasion.occurrence_id ?? 'live'}`}
                                            occasion={occasion}
                                        />
                                    ))}
                                </ul>
                            </section>
                        );
                    })
                )}

                {readyForVerdict.length > 0 && (
                    <section>
                        <SectionHeading>Ready for a verdict</SectionHeading>
                        <ul className="flex flex-col gap-2">
                            {readyForVerdict.map((experiment) => (
                                <li key={experiment.loop_id}>
                                    <VerdictRow experiment={experiment} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </CoachLayout>
    );
}

/**
 * Which endpoint logs this occasion.
 *
 * With an occurrence id the row logs that exact occasion. Without one — an
 * anchored action whose slot was never materialised — it logs the live slot
 * through the action route. Sending everything to the action route would log
 * the wrong thing and say nothing about it.
 */
function logEndpoint(occasion: TodaysOccasionData): string {
    return occasion.occurrence_id === null
        ? `/actions/${occasion.action_id}/logs`
        : `/occurrences/${occasion.occurrence_id}/logs`;
}

function OccasionRow({ occasion }: { occasion: TodaysOccasionData }) {
    const [outcome, setOutcome] = useState<LogOutcome | null>(null);

    return (
        <li className="py-3">
            <div className="flex items-baseline justify-between gap-3">
                <span className="min-w-0">
                    <span className="block truncate text-sm text-foreground">
                        {occasion.title}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {occasion.loop_title}
                    </span>
                </span>
                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                    {occasion.due === 'anchored'
                        ? 'anchored'
                        : formatTime(occasion.scheduled_for)}
                </span>
            </div>

            <Form
                action={logEndpoint(occasion)}
                method="post"
                options={{ preserveScroll: true }}
                className="mt-2 flex flex-col gap-2"
                data-testid={`occasion-form-${occasion.action_id}`}
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
                                        onChange={() => setOutcome(option.value)}
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
 * A version that has reached its review date. Stated in the same weight as the
 * rest of the screen: a decision is available, not overdue. Nothing in this app
 * is late.
 */
function VerdictRow({ experiment }: { experiment: ReadyForVerdictData }) {
    return (
        <Link
            href={`/loops/${experiment.loop_id}`}
            className="flex items-baseline justify-between gap-3 rounded-xl border border-border p-3 transition-colors hover:border-foreground/20 hover:bg-accent/40"
        >
            <span className="min-w-0 truncate text-sm text-foreground">
                {experiment.loop_title}
            </span>
            <span className="shrink-0 font-mono text-xs text-muted-foreground">
                v{experiment.version} · {experiment.intervention_point}
            </span>
        </Link>
    );
}

const OUTCOMES: { value: LogOutcome; label: string }[] = [
    { value: 'completed', label: 'Did it' },
    { value: 'failed', label: 'Did not hold' },
    { value: 'skipped', label: 'Never happened' },
];

/** "Wednesday 27 August" — the day named, so the screen says what today is. */
function formatDay(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

function formatTime(iso: string | null): string {
    return iso === null
        ? ''
        : new Date(iso).toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
          });
}
