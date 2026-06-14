import { Link } from '@inertiajs/react';
import { Check, SkipForward, X } from 'lucide-react';
import { useState } from 'react';

import { cn } from '@/lib/utils';
import type { IntentionData, LogOutcome } from '@/patyourself/types';
import type { ReschedulePayload } from './coach-client';

/**
 * The action card — the app's foundational "AI authors, UI renders" surface. It
 * takes one LLM-authored Intention and presents it as an interactive loop card:
 * a habit-anatomy summary (cue → response → reward), the active strategy's
 * tactic, quick-log buttons, and a link into the full loop detail.
 *
 * Logging is opt-in: pass `onLog` to surface the quick-log row (the chat thread
 * wires it to the API in Task 22). Without it the card is purely presentational,
 * so the same component renders safely anywhere a loop needs to be shown.
 */
export function ActionCard({
    intention,
    onLog,
    onReschedule,
}: {
    intention: IntentionData;
    onLog?: (outcome: LogOutcome) => void;
    onReschedule?: (
        intention: IntentionData,
        schedule: ReschedulePayload,
    ) => void;
}) {
    const tactic = intention.strategy?.approach ?? intention.response;

    return (
        <article className="w-full max-w-[85%] rounded-2xl border border-border bg-card p-4 text-left shadow-sm">
            <header className="flex items-start justify-between gap-2">
                <h3 className="font-semibold text-foreground">
                    {intention.title}
                </h3>
                <div className="flex shrink-0 items-center gap-1">
                    <Badge tone="solid">
                        {intention.type === 'break' ? 'Break' : 'Build'}
                    </Badge>
                    <Badge>{intention.status}</Badge>
                </div>
            </header>

            {intention.active_action && (
                <div className="mt-2 flex items-center gap-2">
                    <ScheduleChip action={intention.active_action} />
                    {intention.active_action.status === 'active' && (
                        <span className="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                            Due now
                        </span>
                    )}
                </div>
            )}

            {onReschedule && intention.active_action && (
                <ScheduleEditor
                    action={intention.active_action}
                    onSave={(schedule) => onReschedule(intention, schedule)}
                />
            )}

            <dl className="mt-3 flex flex-col gap-1.5">
                <AnatomyRow label="When" value={intention.cue} />
                <AnatomyRow label="I'll" value={intention.response} />
                <AnatomyRow label="To get" value={intention.reward} />
            </dl>

            <p className="mt-3 rounded-xl bg-muted px-3 py-2 text-sm text-foreground">
                {tactic}
            </p>

            {onLog && (
                <div className="mt-3 grid grid-cols-3 gap-2">
                    <LogButton
                        icon={<Check className="size-4" />}
                        label="Done"
                        tone="positive"
                        onClick={() => onLog('completed')}
                    />
                    <LogButton
                        icon={<X className="size-4" />}
                        label="Missed"
                        tone="negative"
                        onClick={() => onLog('failed')}
                    />
                    <LogButton
                        icon={<SkipForward className="size-4" />}
                        label="Skip"
                        tone="neutral"
                        onClick={() => onLog('skipped')}
                    />
                </div>
            )}

            <Link
                href={`/intentions/${intention.id}`}
                className="mt-3 inline-block text-xs font-medium text-primary hover:underline"
            >
                View loop →
            </Link>
        </article>
    );
}

function AnatomyRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-2 text-sm">
            <dt className="w-14 shrink-0 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="flex-1 text-foreground">{value}</dd>
        </div>
    );
}

const TONE_STYLES: Record<'positive' | 'negative' | 'neutral', string> = {
    positive:
        'border-emerald-500/30 text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400',
    negative:
        'border-rose-500/30 text-rose-700 hover:bg-rose-500/10 dark:text-rose-400',
    neutral: 'border-border text-muted-foreground hover:bg-accent',
};

function LogButton({
    icon,
    label,
    tone,
    onClick,
}: {
    icon: React.ReactNode;
    label: string;
    tone: 'positive' | 'negative' | 'neutral';
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex items-center justify-center gap-1.5 rounded-lg border py-2 text-xs font-medium transition-colors',
                TONE_STYLES[tone],
            )}
        >
            {icon}
            {label}
        </button>
    );
}

const RECURRENCE_LABEL: Record<string, string> = {
    daily: 'Daily',
    weekdays: 'Weekdays',
    weekly: 'Weekly',
};

function formatSchedule(
    action: NonNullable<IntentionData['active_action']>,
): string | null {
    if (
        action.schedule_kind === 'anchored' ||
        (!action.scheduled_for && action.anchor)
    ) {
        return action.anchor ?? null;
    }

    if (!action.scheduled_for) {
        return null;
    }

    const when = new Date(action.scheduled_for);
    const time = when.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
    const cadence = action.recurrence
        ? (RECURRENCE_LABEL[action.recurrence] ?? action.recurrence)
        : when.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          });

    return `${cadence} · ${time}`;
}

function ScheduleChip({
    action,
}: {
    action: NonNullable<IntentionData['active_action']>;
}) {
    const label = formatSchedule(action);

    if (!label) {
        return null;
    }

    return (
        <p className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
            {label}
        </p>
    );
}

function ScheduleEditor({
    action,
    onSave,
}: {
    action: NonNullable<IntentionData['active_action']>;
    onSave: (schedule: ReschedulePayload) => void;
}) {
    const [open, setOpen] = useState(false);
    const initialTime = action.scheduled_for
        ? new Date(action.scheduled_for).toLocaleTimeString(undefined, {
              hour12: false,
              hour: '2-digit',
              minute: '2-digit',
          })
        : '07:00';
    const [time, setTime] = useState(initialTime);
    const [recurrence, setRecurrence] = useState(action.recurrence ?? 'daily');

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-2 text-xs font-medium text-muted-foreground hover:text-foreground"
            >
                Edit time
            </button>
        );
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-xl border border-border p-2">
            <input
                type="time"
                aria-label="Action time"
                value={time}
                onChange={(event) => setTime(event.target.value)}
                className="h-8 rounded-lg border border-border bg-background px-2 text-sm"
            />
            <select
                aria-label="Recurrence"
                value={recurrence}
                onChange={(event) => setRecurrence(event.target.value)}
                className="h-8 rounded-lg border border-border bg-background px-2 text-sm"
            >
                <option value="once">Once</option>
                <option value="daily">Daily</option>
                <option value="weekdays">Weekdays</option>
                <option value="weekly">Weekly</option>
            </select>
            <button
                type="button"
                onClick={() => {
                    onSave({ kind: 'clock', time, recurrence });
                    setOpen(false);
                }}
                className="h-8 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground"
            >
                Save time
            </button>
        </div>
    );
}

function Badge({
    children,
    tone = 'outline',
}: {
    children: string;
    tone?: 'outline' | 'solid';
}) {
    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-xs capitalize',
                tone === 'solid'
                    ? 'bg-primary/10 font-medium text-primary'
                    : 'border border-border text-muted-foreground',
            )}
        >
            {children}
        </span>
    );
}
