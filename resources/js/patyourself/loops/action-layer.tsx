import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/patyourself/primitives';
import { WorkflowConfig } from '@/patyourself/workflow-config';
import type { WorkflowConfigRow } from '@/patyourself/workflows';
import { destroy, update } from '@/routes/actions';
import actionsRoutes from '@/routes/loops/actions';

export type ActionSummary = {
    id: number;
    title: string;
    /** Null when the action has neither a recurrence nor a next occurrence to
     *  name — see cadenceLabel. Never a partial string like "daily at ". */
    cadence: string | null;
    /** The four fields below are the action's schedule, raw rather than
     *  formatted — `ActionEditor` opens on them so a save never posts a
     *  schedule other than the one the action already has. Optional, like
     *  `routine`, for callers (tests) that only need the read row. */
    scheduleKind?: 'clock' | 'anchored' | null;
    time?: string | null;
    recurrence?: string | null;
    anchor?: string | null;
    /** The action's configuration under the loop's workflow, or null when the
     *  loop has none — see `WorkflowConfig` for why the two are kept apart. */
    routine?: WorkflowConfigRow[] | null;
};

type Props = {
    loopId: number;
    actions: ActionSummary[];
    /** The loop's workflow, or null for a plain loop, which draws nothing
     *  extra here. */
    workflow?: string | null;
};

const FIELD_CLASS =
    'w-full rounded-md border border-border bg-background px-3 py-2 text-sm';

/**
 * The action layer between experiments: add one, retire one.
 *
 * Retiring archives. Occurrences hang off an action and outcomes hang off
 * occurrences, so the copy says "retire" and says the history is kept — a
 * button labelled "delete" would be describing a write that does not happen.
 */
export function ActionLayer({ loopId, actions, workflow = null }: Props) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <div className="space-y-4">
            <ul className="space-y-2">
                {actions.map((action) => (
                    <li key={action.id} className="space-y-2">
                        {editing === action.id ? (
                            <ActionEditor
                                action={action}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div className="flex items-center justify-between gap-3">
                                <span>
                                    <span className="block">
                                        {action.title}
                                    </span>
                                    {action.cadence !== null && (
                                        <span className="block text-sm opacity-70">
                                            {action.cadence}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Edit ${action.title}`}
                                        onClick={() => setEditing(action.id)}
                                    >
                                        Edit
                                    </Button>
                                    <Form {...destroy.form(action.id)}>
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Retire
                                            </Button>
                                        )}
                                    </Form>
                                </span>
                            </div>
                        )}

                        {/* Draws nothing for a plain loop, which is every loop
                         *  with no workflow — the row above then reads exactly
                         *  as it always has. */}
                        <WorkflowConfig
                            workflow={workflow}
                            actionId={action.id}
                            rows={action.routine ?? null}
                        />
                    </li>
                ))}
            </ul>

            <p className="text-sm opacity-70">
                Retiring an action stops it running. Everything it recorded is
                kept.
            </p>

            <details>
                <summary className="ds-label cursor-pointer">
                    Add an action
                </summary>
                <Form
                    {...actionsRoutes.store.form(loopId)}
                    resetOnSuccess
                    className="space-y-3 pt-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1">
                                <label
                                    htmlFor="action-title"
                                    className="ds-label"
                                >
                                    What to do
                                </label>
                                <input
                                    id="action-title"
                                    name="title"
                                    className={FIELD_CLASS}
                                />
                                {errors.title && (
                                    <p className="text-sm text-destructive">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1">
                                <label
                                    htmlFor="action-kind"
                                    className="ds-label"
                                >
                                    When
                                </label>
                                <select
                                    id="action-kind"
                                    name="kind"
                                    value={kind}
                                    onChange={(e) =>
                                        setKind(
                                            e.target.value as
                                                | 'clock'
                                                | 'anchored',
                                        )
                                    }
                                    className={FIELD_CLASS}
                                >
                                    <option value="clock">At a time</option>
                                    <option value="anchored">
                                        After something else
                                    </option>
                                </select>
                            </div>

                            {kind === 'clock' ? (
                                <div className="flex gap-3">
                                    <div className="space-y-1">
                                        <label
                                            htmlFor="action-time"
                                            className="ds-label"
                                        >
                                            Time
                                        </label>
                                        <input
                                            id="action-time"
                                            name="time"
                                            type="time"
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        />
                                        {errors.time && (
                                            <p className="text-sm text-destructive">
                                                {errors.time}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label
                                            htmlFor="action-recurrence"
                                            className="ds-label"
                                        >
                                            How often
                                        </label>
                                        <select
                                            id="action-recurrence"
                                            name="recurrence"
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        >
                                            <option value="once">Once</option>
                                            <option value="daily">Daily</option>
                                            <option value="weekdays">
                                                Weekdays
                                            </option>
                                            <option value="weekly">
                                                Weekly
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-1">
                                    <label
                                        htmlFor="action-anchor"
                                        className="ds-label"
                                    >
                                        After what
                                    </label>
                                    <input
                                        id="action-anchor"
                                        name="anchor"
                                        className={FIELD_CLASS}
                                    />
                                    {errors.anchor && (
                                        <p className="text-sm text-destructive">
                                            {errors.anchor}
                                        </p>
                                    )}
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                Add
                            </Button>
                        </>
                    )}
                </Form>
            </details>
        </div>
    );
}

/**
 * Amending one action: what it says, and when it happens.
 *
 * The schedule half posts on every save, even when only the title changed —
 * `RescheduleAction` treats a schedule that resolves to the action's current
 * one as no reschedule at all, so the occasions survive. That guard is on the
 * server rather than here on purpose: a diff computed in the client would
 * delete occasions the day it got the comparison wrong.
 *
 * That safety only holds once the posted schedule is the one the guard
 * compares against, though — so every field below is initialised from the
 * action's own schedule, not from a fixed default. Opening on `clock`/empty
 * fields regardless of what the action actually is would make a title-only
 * save on an anchored, weekly action post `clock`/`once`, which is a real
 * change: the guard would not fire, and the action's occasions would be
 * purged. The pre-fill is what makes an unrelated edit safe, not decoration.
 *
 * Field names are unprefixed to match `actions.update`. `StartExperimentForm`
 * asks the same questions under `action_*` names because it posts them to the
 * experiment endpoint — its markup is worth copying, its names are not.
 */
function ActionEditor({
    action,
    onDone,
}: {
    action: ActionSummary;
    onDone: () => void;
}) {
    const [kind, setKind] = useState<'clock' | 'anchored'>(
        action.scheduleKind ?? 'clock',
    );

    return (
        <Form
            {...update.form(action.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            data-testid={`action-editor-${action.id}`}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <label
                            htmlFor={`action-title-${action.id}`}
                            className="ds-label"
                        >
                            What to do
                        </label>
                        <input
                            id={`action-title-${action.id}`}
                            name="title"
                            defaultValue={action.title}
                            className={FIELD_CLASS}
                        />
                        {errors.title && (
                            <p className="text-sm text-destructive">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor={`action-kind-${action.id}`}
                            className="ds-label"
                        >
                            When
                        </label>
                        <select
                            id={`action-kind-${action.id}`}
                            name="kind"
                            value={kind}
                            onChange={(e) =>
                                setKind(e.target.value as 'clock' | 'anchored')
                            }
                            className={FIELD_CLASS}
                        >
                            <option value="clock">At a time</option>
                            <option value="anchored">
                                After something else
                            </option>
                        </select>
                    </div>

                    {kind === 'clock' ? (
                        <div className="flex gap-3">
                            <div className="space-y-1">
                                <label
                                    htmlFor={`action-time-${action.id}`}
                                    className="ds-label"
                                >
                                    Time
                                </label>
                                <input
                                    id={`action-time-${action.id}`}
                                    name="time"
                                    type="time"
                                    defaultValue={action.time ?? ''}
                                    className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                />
                                {errors.time && (
                                    <p className="text-sm text-destructive">
                                        {errors.time}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <label
                                    htmlFor={`action-recurrence-${action.id}`}
                                    className="ds-label"
                                >
                                    How often
                                </label>
                                <select
                                    id={`action-recurrence-${action.id}`}
                                    name="recurrence"
                                    defaultValue={action.recurrence ?? 'once'}
                                    className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="once">Once</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekdays">Weekdays</option>
                                    <option value="weekly">Weekly</option>
                                </select>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-1">
                            <label
                                htmlFor={`action-anchor-${action.id}`}
                                className="ds-label"
                            >
                                After what
                            </label>
                            <input
                                id={`action-anchor-${action.id}`}
                                name="anchor"
                                defaultValue={action.anchor ?? ''}
                                className={FIELD_CLASS}
                            />
                            {errors.anchor && (
                                <p className="text-sm text-destructive">
                                    {errors.anchor}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
