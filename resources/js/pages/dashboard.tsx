import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Companion } from '@/patyourself/companion';
import type { CompanionData } from '@/patyourself/companion';
import { Icon } from '@/patyourself/primitives';
import type { LogOutcome } from '@/patyourself/types';
import { WorkflowRecord } from '@/patyourself/workflow-record';
import { store as storeActionLog } from '@/routes/actions/logs';
import { show } from '@/routes/loops';
import { store as storeOccurrenceLog } from '@/routes/occurrences/logs';

export interface TodaysOccasionData {
    /** Null for an anchored action with no materialised slot. Decides which
     *  endpoint the row posts to — see `logEndpoint`. */
    occurrence_id: number | null;
    action_id: number;
    loop_id: number;
    loop_title: string;
    /** The loop's recording surface. Null for the plain screen. */
    workflow: string | null;
    title: string;
    description: string | null;
    due: 'due_now' | 'upcoming' | 'anchored';
    scheduled_for: string | null;
    /** "v2 · after breakfast" — the version this occasion tests. Null for a
     *  loop with no running experiment, which logs just the same. */
    strategy: string | null;
    /** Null while the occasion is still open. */
    outcome: LogOutcome | null;
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
    /**
     * The moment the timeline draws its rule at.
     *
     * From the server rather than the browser clock, for two reasons. It is the
     * same clock that decided each occasion's `due`, so the rule cannot land on
     * the wrong side of a row the server already called due. And it keeps this
     * component pure: a render that read the wall clock would produce a
     * different tree every time React happened to re-run it.
     *
     * Fixed at page load, which is the right grain here — the screen is a
     * record of a day, not a ticking clock, and every Inertia visit brings a
     * fresh one.
     */
    now: string;
    occasions: TodaysOccasionData[];
    ready_for_verdict: ReadyForVerdictData[];
    companion: CompanionData;
    /**
     * The outcome recorded on the request that landed here, or null. Carried
     * from the server as a one-request flash, so it is null again the next
     * time this screen is opened.
     *
     * That guarantee is server-side only. Inertia keeps this page's props,
     * this one included, in `history.state` and restores them on `popstate`
     * without a round trip — so logging an outcome, visiting /companion, then
     * pressing Back replays this same id and Blob notices again. Known and
     * left alone: the cost is one extra ~500ms lift, and a fix would have to
     * live on the client, not here.
     */
    logged_outcome_id?: number | null;
}

/** What each verdict is called, and which token it lights up when chosen. */
const VERDICTS: {
    value: LogOutcome;
    label: string;
    chip: string;
    tone: string;
}[] = [
    {
        value: 'completed',
        label: 'Did it',
        chip: 'py-chip--reward',
        tone: 'did',
    },
    {
        value: 'failed',
        label: 'Did not hold',
        chip: 'py-chip--cue',
        tone: 'hold',
    },
    { value: 'skipped', label: 'Never happened', chip: '', tone: 'never' },
];

/**
 * The things that actually get in the way, offered rather than typed.
 *
 * A one-tap answer is the difference between a reason and a blank box at
 * 21:00, and the reason is what a revision is later argued from. The note
 * below them stays open for anything this list does not cover.
 */
const REASONS = [
    'Forgot in the moment',
    'Too tired',
    'No time',
    'Not near the cue',
    'Someone else decided',
    'Chose not to',
];

const OUTCOME_WORD: Record<LogOutcome, string> = {
    completed: 'did it',
    failed: 'did not hold',
    skipped: 'never happened',
};

/** Identifies a row across renders. An anchored action with no slot yet has no
 *  occurrence id, so the action id alone is not unique within a day. */
function keyOf(occasion: TodaysOccasionData): string {
    return `${occasion.action_id}-${occasion.occurrence_id ?? 'live'}`;
}

/**
 * The daily-driver screen: today, and only today.
 *
 * A timeline of the day's occasions against the clock, one open at a time. An
 * occasion missed on an earlier day never appears here — it stays loggable
 * forever on /catch-up. Surfacing it would make the first screen after login a
 * backlog.
 *
 * Answered occasions stay on the rail rather than disappearing, marked with
 * what was recorded. The day is the unit here, and a day that erased its own
 * answered hours would read as emptier the more of it you had dealt with. The
 * tally counts both halves for the same reason, and counts only today: two
 * occasions and six are not scored against each other across days.
 */
export default function Dashboard({
    today,
    now,
    occasions,
    ready_for_verdict: readyForVerdict,
    companion,
    logged_outcome_id: loggedOutcomeId = null,
}: DashboardProps) {
    const open = occasions.filter((occasion) => occasion.outcome === null);
    const recorded = occasions.length - open.length;

    // Of the occasions whose hour has come and gone unanswered, the most recent
    // is what is being asked now; the ones before it are simply still open. The
    // server sends `due_now` for all of them — it marks the hour having passed,
    // not which one is the question — and the list arrives in time order, so
    // the last is the nearest.
    const dueNow = open.filter((occasion) => occasion.due === 'due_now');
    const dueKey = dueNow.length > 0 ? keyOf(dueNow[dueNow.length - 1]) : null;

    const [openKey, setOpenKey] = useState<string | null>(dueKey);

    return (
        <CoachLayout
            title="Today"
            // The day's name and tally go in the layout's header slot, which
            // sits outside the scroll area — so what day it is and how much of
            // it is left stay on screen while the timeline moves under them.
            header={
                <div className="t-head">
                    <div>
                        <p className="t-date">{formatDay(today)}</p>
                        <h1 className="t-day">Today</h1>
                    </div>
                    <div className="t-headside">
                        {occasions.length > 0 && (
                            <p className="t-tally">
                                {recorded} recorded · {open.length} left
                            </p>
                        )}
                        <CompanionCorner
                            companion={companion}
                            reactTo={loggedOutcomeId}
                        />
                    </div>
                </div>
            }
            flush
            bottomNav={<BottomNav />}
        >
            <div className="t-body">
                <div className="t-col">
                    {occasions.length === 0 ? (
                        // A day with nothing scheduled is a normal day, not an
                        // empty one. Stated as a fact, with nothing to act on.
                        <p className="text-sm text-muted-foreground">
                            Nothing due today.
                        </p>
                    ) : (
                        <Timeline
                            occasions={occasions}
                            now={now}
                            dueKey={dueKey}
                            openKey={openKey}
                            onToggle={setOpenKey}
                        />
                    )}

                    {readyForVerdict.length > 0 && (
                        <section className="t-sec">
                            <h2 className="t-sech">Ready for a verdict</h2>
                            <div className="flex flex-col gap-2">
                                {readyForVerdict.map((experiment) => (
                                    <VerdictRow
                                        key={experiment.loop_id}
                                        experiment={experiment}
                                    />
                                ))}
                            </div>
                        </section>
                    )}

                    {occasions.length > 0 && open.length === 0 && (
                        <p className="t-done">
                            Everything today has an answer. Nothing else is
                            owed.
                        </p>
                    )}
                </div>
            </div>
        </CoachLayout>
    );
}

/**
 * The day against the clock, with a rule drawn at the current time.
 *
 * The rule sits before the first occasion still ahead — either one scheduled
 * later, or a cue-anchored one, which has no hour at all and so is placed after
 * everything the clock can order. Nothing is ahead of now, and the rule falls
 * at the bottom.
 */
function Timeline({
    occasions,
    now,
    dueKey,
    openKey,
    onToggle,
}: {
    occasions: TodaysOccasionData[];
    now: string;
    dueKey: string | null;
    openKey: string | null;
    onToggle: (key: string | null) => void;
}) {
    const nowMs = new Date(now).getTime();
    const nowIndex = occasions.findIndex(
        (occasion) =>
            occasion.scheduled_for === null ||
            new Date(occasion.scheduled_for).getTime() > nowMs,
    );

    const rows = occasions.flatMap((occasion, index) => {
        const key = keyOf(occasion);
        const isOpen = openKey === key && occasion.outcome === null;
        // An hour that has passed unanswered, and is not the one being asked.
        const isPast =
            occasion.outcome === null &&
            occasion.due === 'due_now' &&
            key !== dueKey;

        const slot = (
            <li
                key={key}
                data-testid={`occasion-${occasion.action_id}`}
                className={[
                    't-slot',
                    isOpen ? 'is-open' : '',
                    occasion.outcome ? 'is-logged' : '',
                    isPast && !isOpen ? 'is-past' : '',
                ]
                    .filter(Boolean)
                    .join(' ')}
            >
                <span className="t-dot" />
                <span className="t-when">
                    {occasion.due === 'anchored' ? (
                        <>
                            <Icon name="anchor" size={13} />
                            <small>anchored</small>
                        </>
                    ) : (
                        formatTime(occasion.scheduled_for)
                    )}
                </span>

                {isOpen ? (
                    <OpenSlot
                        occasion={occasion}
                        eyebrow={
                            key === dueKey ? 'Due now' : 'Not recorded yet'
                        }
                    />
                ) : (
                    <button
                        type="button"
                        className="t-row"
                        onClick={() => onToggle(occasion.outcome ? null : key)}
                    >
                        {/* Spans, not paragraphs: a button's content model is
                            phrasing content, and the design's markup was
                            written for a canvas that never had to say so. */}
                        <span className="t-title">{occasion.title}</span>
                        <span className="t-loop">{occasion.loop_title}</span>
                        <RowTag occasion={occasion} past={isPast} />
                    </button>
                )}
            </li>
        );

        return index === nowIndex
            ? [<NowRule key="now" at={now} />, slot]
            : [slot];
    });

    return (
        <ul className="t-tl">
            {rows}
            {nowIndex === -1 && <NowRule key="now" at={now} />}
        </ul>
    );
}

function NowRule({ at }: { at: string }) {
    return (
        <li className="t-nowrow" data-testid="now-rule">
            <span className="t-dot" />
            <span className="t-nowtime">{formatTime(at)}</span>
            <span className="t-nowline" />
        </li>
    );
}

/** What the row says about itself when it is not the one open. */
function RowTag({
    occasion,
    past,
}: {
    occasion: TodaysOccasionData;
    past: boolean;
}) {
    if (occasion.outcome !== null) {
        return (
            <span
                className={`t-tag t-tag--${occasion.outcome === 'completed' ? 'done' : 'miss'}`}
            >
                Logged · {OUTCOME_WORD[occasion.outcome]}
            </span>
        );
    }

    return past ? (
        <span className="t-tag t-tag--miss">Still open · tap to record</span>
    ) : (
        <span className="t-tag t-tag--open">Tap to record</span>
    );
}

/**
 * Which endpoint logs this occasion.
 *
 * With an occurrence id the row logs that exact occasion. Without one — an
 * anchored action whose slot was never materialised — it logs the live slot
 * through the action route. Sending everything to the action route would log
 * the wrong thing and say nothing about it.
 *
 * Takes the occurrence id as its own argument rather than the whole occasion:
 * the caller passes its own state, not `occasion.occurrence_id` directly.
 * That field is a one-time server render, and a workflow's recording surface
 * can materialise an occasion on the client afterwards — the card keeps the
 * current id in state, seeded from this same prop and updated by
 * `onOccurrenceMaterialised`, so a verdict pressed after materialising still
 * follows the occasion the surface actually recorded against instead of
 * falling back to whatever the action route's live slot resolves to.
 */
function logEndpoint(actionId: number, occurrenceId: number | null): string {
    return occurrenceId === null
        ? storeActionLog.url(actionId)
        : storeOccurrenceLog.url(occurrenceId);
}

/**
 * The occasion being answered: what it was, which version it tests, and the
 * verdict controls.
 *
 * `skipped` means the occasion never happened. `failed` means it happened and
 * the strategy did not hold — including simply not thinking about it. Neither
 * label says anything about the person.
 *
 * A failure carries a reason, the same rule the tool boundary enforces, for the
 * same reason: it is the evidence a revision is argued from, and a verdict
 * without one is an outcome nobody can learn from. The chips are the common
 * answers; the note takes anything they miss, and both travel as the one
 * `reason` the server validates.
 *
 * Entering a workflow's record never decides the outcome — filling in three
 * sets and then marking the session missed because you cut it short is a real
 * thing that happens, and a form that inferred "done" from the presence of data
 * would be overruling the person who was there. So the record sits above the
 * question, outside the form, and answers nothing on its own.
 */
function OpenSlot({
    occasion,
    eyebrow,
}: {
    occasion: TodaysOccasionData;
    eyebrow: string;
}) {
    const [occurrenceId, setOccurrenceId] = useState(occasion.occurrence_id);
    const [outcome, setOutcome] = useState<LogOutcome | null>(null);
    const [reason, setReason] = useState<string | null>(null);
    const [note, setNote] = useState('');

    const ready = outcome !== null && (outcome !== 'failed' || reason !== null);

    return (
        <div className="t-card">
            <p className="t-eyebrow">{eyebrow}</p>
            <h3 className="t-title">{occasion.title}</h3>
            <div className="t-meta">
                {occasion.strategy !== null && (
                    <span className="t-chipq">{occasion.strategy}</span>
                )}
                <Link className="t-link" href={show.url(occasion.loop_id)}>
                    {occasion.loop_title}
                </Link>
            </div>

            <WorkflowRecord
                workflow={occasion.workflow}
                occurrenceId={occurrenceId}
                actionId={occasion.action_id}
                onOccurrenceMaterialised={setOccurrenceId}
            />

            <Form
                action={logEndpoint(occasion.action_id, occurrenceId)}
                method="post"
                options={{ preserveScroll: true }}
                data-testid={`occasion-form-${occasion.action_id}`}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="outcome"
                            value={outcome ?? ''}
                        />
                        {outcome === 'failed' && (
                            <input
                                type="hidden"
                                name="reason"
                                value={failureReason(reason, note)}
                            />
                        )}

                        <p className="t-ask">What happened?</p>
                        <div className="t-verdicts">
                            {VERDICTS.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={outcome === option.value}
                                    className={cn(
                                        'py-chip py-chip--btn t-v',
                                        outcome === option.value && [
                                            option.chip,
                                            'on',
                                            `on--${option.tone}`,
                                        ],
                                    )}
                                    onClick={() => {
                                        setOutcome(option.value);

                                        if (option.value !== 'failed') {
                                            setReason(null);
                                        }
                                    }}
                                >
                                    <i />
                                    {option.label}
                                </button>
                            ))}
                        </div>

                        {outcome === 'failed' && (
                            <div className="t-why">
                                <p className="t-whyq">What got in the way?</p>
                                <div className="t-reasons">
                                    {REASONS.map((option) => (
                                        <button
                                            key={option}
                                            type="button"
                                            aria-pressed={reason === option}
                                            className={cn(
                                                'py-chip py-chip--btn t-r',
                                                reason === option &&
                                                    'py-chip--cue on',
                                            )}
                                            onClick={() => setReason(option)}
                                        >
                                            {option}
                                        </button>
                                    ))}
                                </div>
                                <textarea
                                    className="t-note"
                                    rows={2}
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                    placeholder="Add a note, in your words (optional)"
                                    aria-label="Add a note, in your words"
                                />
                                {errors.reason && (
                                    <p className="t-err">{errors.reason}</p>
                                )}
                            </div>
                        )}

                        <div className="t-confirm">
                            <button
                                type="submit"
                                className="py-btn py-btn--primary py-btn--md"
                                disabled={!ready || processing}
                            >
                                Log it
                            </button>
                            <span className="t-hint">
                                {hintFor(outcome, reason)}
                            </span>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

/**
 * The one `reason` string a failure posts.
 *
 * The chip leads because it is the answer; the note elaborates on it. Joined
 * rather than sent as two fields — the server stores one reason, and splitting
 * it here would leave the note unreadable everywhere the reason is shown back.
 */
function failureReason(reason: string | null, note: string): string {
    const words = note.trim();

    if (reason === null) {
        return words;
    }

    return words === '' ? reason : `${reason} — ${words}`;
}

/** Says what the press is waiting for, so a disabled button is never a puzzle. */
function hintFor(outcome: LogOutcome | null, reason: string | null): string {
    if (outcome === null) {
        return 'Pick one, then log it.';
    }

    if (outcome === 'failed' && reason === null) {
        return 'Pick what got in the way.';
    }

    return 'Nothing is logged until you press.';
}

/**
 * Blob in the corner, at 32px, linking to its own screen.
 *
 * Renders nothing before the first outcome — no placeholder and no outline,
 * because an empty slot in the header would be one more thing owed. The link
 * carries no count and no badge for the same reason.
 *
 * `reactTo` is how the corner learns an outcome was just recorded. Movement
 * only: no copy is added here, and none should be.
 */
function CompanionCorner({
    companion,
    reactTo = null,
}: {
    companion: CompanionData;
    reactTo?: number | null;
}) {
    if (companion.stage_index === 0) {
        return null;
    }

    return (
        <Link
            href="/companion"
            aria-label="Blob"
            className="flex items-center rounded-md p-1 transition-opacity hover:opacity-80"
        >
            <Companion companion={companion} size={32} reactTo={reactTo} />
        </Link>
    );
}

/**
 * A version that has reached its review date. Stated in the same weight as the
 * rest of the screen: a decision is available, not overdue. Nothing in this app
 * is late, so `planned_days` is carried but never rendered as a countdown — an
 * open-ended experiment has nothing to misread.
 *
 * The card itself is not the link — the verdict form it leads to lives at the
 * loop's own record, so the door is named for the decision, not the record.
 */
function VerdictRow({ experiment }: { experiment: ReadyForVerdictData }) {
    return (
        <div className="t-exp">
            <p>{experiment.loop_title}</p>
            <span>
                v{experiment.version} · {experiment.intervention_point} · day{' '}
                {experiment.day_of_experiment}
            </span>
            <Link
                href={show.url(experiment.loop_id)}
                className="py-btn py-btn--secondary py-btn--md"
            >
                Give it a verdict
            </Link>
        </div>
    );
}

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
