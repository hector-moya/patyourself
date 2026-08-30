import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/patyourself/primitives';
import { destroy } from '@/routes/actions';
import actionsRoutes from '@/routes/loops/actions';

export type ActionSummary = {
    id: number;
    title: string;
    /** Null when the action has neither a recurrence nor a next occurrence to
     *  name — see cadenceLabel. Never a partial string like "daily at ". */
    cadence: string | null;
};

type Props = {
    loopId: number;
    actions: ActionSummary[];
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
export function ActionLayer({ loopId, actions }: Props) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');

    return (
        <div className="space-y-4">
            <ul className="space-y-2">
                {actions.map((action) => (
                    <li
                        key={action.id}
                        className="flex items-center justify-between gap-3"
                    >
                        <span>
                            <span className="block">{action.title}</span>
                            {action.cadence !== null && (
                                <span className="block text-sm opacity-70">
                                    {action.cadence}
                                </span>
                            )}
                        </span>
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
                    </li>
                ))}
            </ul>

            <p className="text-sm opacity-70">
                Retiring an action stops it running. Everything it recorded is
                kept.
            </p>

            <details>
                <summary className="cursor-pointer ds-label">
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
                                            <option value="daily">
                                                Daily
                                            </option>
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
