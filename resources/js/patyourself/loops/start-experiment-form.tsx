import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/patyourself/primitives';
import experiments from '@/routes/loops/experiments';

type Props = {
    loopId: number;
    currentCadence: string | null;
};

const POINTS = ['cue', 'craving', 'response', 'reward'] as const;

const FIELD_CLASS =
    'w-full rounded-md border border-border bg-background px-3 py-2 text-sm';

/**
 * StartExperiment's `$revisedAction` is the least guessable part of its API:
 * passing null does not mean "no action", it means "inherit the prior cadence,
 * retitled from the new approach". So the form asks instead of defaulting, and
 * names the current cadence in the option so the choice is legible.
 */
export function StartExperimentForm({ loopId, currentCadence }: Props) {
    const [cadence, setCadence] = useState<'keep' | 'change'>('keep');
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');

    return (
        <Form {...experiments.store.form(loopId)} className="space-y-4">
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <label
                            htmlFor="intervention_point"
                            className="ds-label"
                        >
                            Where in the chain does this one intervene?
                        </label>
                        <select
                            id="intervention_point"
                            name="intervention_point"
                            className={FIELD_CLASS}
                        >
                            {POINTS.map((p) => (
                                <option key={p} value={p}>
                                    {p}
                                </option>
                            ))}
                        </select>
                        {errors.intervention_point && (
                            <p className="text-sm text-destructive">
                                {errors.intervention_point}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="approach" className="ds-label">
                            The approach
                        </label>
                        <textarea
                            id="approach"
                            name="approach"
                            rows={3}
                            className={FIELD_CLASS}
                        />
                        {errors.approach && (
                            <p className="text-sm text-destructive">
                                {errors.approach}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="rationale" className="ds-label">
                            The hypothesis — why this point, and why now
                        </label>
                        <textarea
                            id="rationale"
                            name="rationale"
                            rows={2}
                            className={FIELD_CLASS}
                        />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="supersedes_reason" className="ds-label">
                            Why the current version is being replaced
                        </label>
                        <textarea
                            id="supersedes_reason"
                            name="supersedes_reason"
                            rows={2}
                            className={FIELD_CLASS}
                        />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="review_after_days" className="ds-label">
                            Review it after (days)
                        </label>
                        <input
                            id="review_after_days"
                            name="review_after_days"
                            type="number"
                            min={1}
                            className={FIELD_CLASS}
                        />
                        <p className="text-sm opacity-70">
                            Leave this empty to run it open-ended.
                        </p>
                        {errors.review_after_days && (
                            <p className="text-sm text-destructive">
                                {errors.review_after_days}
                            </p>
                        )}
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="ds-label">The action</legend>

                        <label className="flex items-center gap-3">
                            <input
                                type="radio"
                                name="cadence"
                                value="keep"
                                checked={cadence === 'keep'}
                                onChange={() => setCadence('keep')}
                            />
                            <span>
                                Keep the current cadence
                                {currentCadence ? ` (${currentCadence})` : ''}
                            </span>
                        </label>

                        <label className="flex items-center gap-3">
                            <input
                                type="radio"
                                name="cadence"
                                value="change"
                                checked={cadence === 'change'}
                                onChange={() => setCadence('change')}
                            />
                            <span>Set a new cadence</span>
                        </label>
                    </fieldset>

                    {cadence === 'change' && (
                        <div className="space-y-3 border-l border-border pl-4">
                            <div className="space-y-1">
                                <label
                                    htmlFor="action_title"
                                    className="ds-label"
                                >
                                    What to do
                                </label>
                                <input
                                    id="action_title"
                                    name="action_title"
                                    className={FIELD_CLASS}
                                />
                                {errors.action_title && (
                                    <p className="text-sm text-destructive">
                                        {errors.action_title}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1">
                                <label
                                    htmlFor="action_kind"
                                    className="ds-label"
                                >
                                    When
                                </label>
                                <select
                                    id="action_kind"
                                    name="action_kind"
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
                                            htmlFor="action_time"
                                            className="ds-label"
                                        >
                                            Time
                                        </label>
                                        <input
                                            id="action_time"
                                            name="action_time"
                                            type="time"
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        />
                                        {errors.action_time && (
                                            <p className="text-sm text-destructive">
                                                {errors.action_time}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label
                                            htmlFor="action_recurrence"
                                            className="ds-label"
                                        >
                                            How often
                                        </label>
                                        <select
                                            id="action_recurrence"
                                            name="action_recurrence"
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
                                        htmlFor="action_anchor"
                                        className="ds-label"
                                    >
                                        After what
                                    </label>
                                    <input
                                        id="action_anchor"
                                        name="action_anchor"
                                        className={FIELD_CLASS}
                                    />
                                    {errors.action_anchor && (
                                        <p className="text-sm text-destructive">
                                            {errors.action_anchor}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    <Button type="submit" disabled={processing}>
                        Start this experiment
                    </Button>
                </>
            )}
        </Form>
    );
}
