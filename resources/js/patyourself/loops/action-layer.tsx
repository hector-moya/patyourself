import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { RECURRENCES, namesADay } from '@/patyourself/loops/recurrences';
import { Button } from '@/patyourself/primitives';
import { WorkflowConfig } from '@/patyourself/workflow-config';
import type { WorkflowConfigRow, WorkflowRegistry } from '@/patyourself/workflows';
import { WORKFLOWS, configSurfaceFor } from '@/patyourself/workflows';
import { destroy, update } from '@/routes/actions';
import actionsRoutes from '@/routes/loops/actions';

export type ActionSummary = {
    id: number;
    title: string;
    /** Null when the action has neither a recurrence nor a next occurrence to
     *  name — see cadenceLabel. Never a partial string like "daily at ". */
    cadence: string | null;
    /** The action's schedule, raw rather than formatted. `ActionEditor` opens
     *  on these so a save never posts a schedule other than the one the action
     *  already has. Required rather than optional: a caller that stopped
     *  passing them would put the editor back on its own defaults, silently,
     *  and a title-only save would then reschedule the action. Null is a real
     *  value here — a cue-anchored action has no clock time. */
    scheduleKind: 'clock' | 'anchored' | null;
    time: string | null;
    recurrence: string | null;
    anchor: string | null;
    /** The anchor's date in the owner's zone, `YYYY-MM-DD`, pre-formatted by
     *  the server. Required for the same reason its siblings above are: a
     *  caller that stopped passing it would silently put the editor's date
     *  input back on an empty default, and a save that changed only the title
     *  would then move the series. */
    date: string | null;
    /** The action's configuration under the loop's workflow, or null when the
     *  loop has none — see `WorkflowConfig` for why the two are kept apart.
     *  Required rather than optional, for the same reason as the schedule
     *  fields above: a caller that stopped passing it would fall back to
     *  `undefined`, and `configSurfaceFor` would then find no rows for an
     *  action that actually has a routine — silently flattening a row that
     *  should have collapsed, rather than merely omitting a panel. */
    routine: WorkflowConfigRow[] | null;
};

type Props = {
    loopId: number;
    actions: ActionSummary[];
    /** The loop's workflow, or null for a plain loop, which draws nothing
     *  extra here. */
    workflow?: string | null;
    /** Injectable so a test can exercise the collapsed shape without a second
     *  module having been shipped — the same reason `WorkflowConfig` takes
     *  one. Defaults to the registry the app actually draws. */
    registry?: WorkflowRegistry;
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
export function ActionLayer({
    loopId,
    actions,
    workflow = null,
    registry = WORKFLOWS,
}: Props) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <div className="space-y-4">
            <ul className="space-y-2">
                {actions.map((action) => {
                    const rows = action.routine ?? null;
                    const isEditing = editing === action.id;

                    // The same resolver the slot itself uses. An action only
                    // collapses when there is genuinely something behind the
                    // triangle: a plain loop, an unknown workflow name, or a
                    // module with no config site all keep the flat row they
                    // have always had.
                    const collapses =
                        configSurfaceFor(workflow, rows, registry) !== null;

                    const controls = isEditing ? (
                        <ActionEditor
                            action={action}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <ActionControls
                            action={action}
                            onEdit={() => setEditing(action.id)}
                        />
                    );

                    // Draws nothing for a plain loop, which is every loop
                    // with no workflow — the row then reads exactly as it
                    // always has.
                    const config = (
                        <WorkflowConfig
                            workflow={workflow}
                            actionId={action.id}
                            rows={rows}
                            registry={registry}
                        />
                    );

                    if (collapses) {
                        return (
                            <li key={action.id}>
                                <details>
                                    <summary className="cursor-pointer">
                                        <ActionHeader action={action} />
                                    </summary>
                                    <div className="space-y-2 pt-2">
                                        {controls}
                                        {config}
                                    </div>
                                </details>
                            </li>
                        );
                    }

                    return (
                        <li key={action.id} className="space-y-2">
                            {isEditing ? (
                                controls
                            ) : (
                                <div className="flex items-center justify-between gap-3">
                                    <ActionHeader action={action} />
                                    {controls}
                                </div>
                            )}
                            {config}
                        </li>
                    );
                })}
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
                                            {RECURRENCES.map((recurrence) => (
                                                <option
                                                    key={recurrence.value}
                                                    value={recurrence.value}
                                                >
                                                    {recurrence.label}
                                                </option>
                                            ))}
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
 * What an action says about itself: its title, and its cadence when it has one
 * to name.
 *
 * Rendered inside `<summary>` for an action that collapses and inside the flat
 * row for one that does not, so the two shapes cannot drift into describing an
 * action differently.
 */
function ActionHeader({ action }: { action: ActionSummary }) {
    return (
        <span>
            <span className="block">{action.title}</span>
            {action.cadence !== null && (
                <span className="block text-sm opacity-70">
                    {action.cadence}
                </span>
            )}
        </span>
    );
}

/**
 * The two things that can be done to an action from the layer.
 *
 * These live *outside* `<summary>` for a collapsing action, which is not a
 * layout preference: `Retire` is a submit button inside a form, and a button
 * inside `<summary>` both submits and toggles the disclosure on one click.
 */
function ActionControls({
    action,
    onEdit,
}: {
    action: ActionSummary;
    onEdit: () => void;
}) {
    return (
        <span className="flex items-center gap-1">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={`Edit ${action.title}`}
                onClick={onEdit}
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
    // An action can carry no schedule at all — retire one, start a revision
    // with no revised action, and StartExperiment writes `metadata: []` with
    // a null series_started_at. Such an action has nothing to reschedule, so
    // it gets no schedule controls at all rather than controls collapsed to
    // `clock` with an empty time: that would make `kind` the only reachable
    // value, `time` blank, and RescheduleActionRequest's
    // `required_if:kind,clock` would reject every save — including the pure
    // rename this form exists to allow. Worse, if the empty time were ever
    // accepted it would be a real schedule change the user never asked for,
    // not the rename they came here to make.
    const hasSchedule =
        action.scheduleKind !== null ||
        action.time !== null ||
        action.anchor !== null;

    const [kind, setKind] = useState<'clock' | 'anchored'>(
        action.scheduleKind ?? 'clock',
    );

    // Controlled, unlike the add-an-action form's, because it decides whether
    // the date input is rendered at all: `daily` and `weekdays` repeat on every
    // day they apply to, so a date names nothing there, while `weekly` picks
    // the weekday and a one-off's date is the event itself.
    const [recurrence, setRecurrence] = useState<string>(
        action.recurrence ?? 'once',
    );

    const needsDate = namesADay(recurrence);

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

                    {hasSchedule && (
                        <>
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
                                <>
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
                                                value={recurrence}
                                                onChange={(e) =>
                                                    setRecurrence(
                                                        e.target.value,
                                                    )
                                                }
                                                className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                            >
                                                {RECURRENCES.map((recurrence) => (
                                                    <option
                                                        key={recurrence.value}
                                                        value={recurrence.value}
                                                    >
                                                        {recurrence.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>

                                    {needsDate && (
                                        <div className="space-y-1">
                                            <label
                                                htmlFor={`action-date-${action.id}`}
                                                className="ds-label"
                                            >
                                                Starts on
                                            </label>
                                            {/* No `min`: an Inertia <Form> is a
                                             *  real form, so native validation
                                             *  runs on submit, and a minimum
                                             *  of today against an anchor date
                                             *  that has passed would block the
                                             *  pure rename this form exists to
                                             *  allow. The server snaps a past
                                             *  date forward instead of
                                             *  refusing it. */}
                                            <input
                                                id={`action-date-${action.id}`}
                                                name="date"
                                                type="date"
                                                defaultValue={action.date ?? ''}
                                                className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                            />
                                            {errors.date && (
                                                <p className="text-sm text-destructive">
                                                    {errors.date}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </>
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
                        </>
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
