import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/patyourself/primitives';
import verdict from '@/routes/strategies/verdict';

type Props = {
    strategyId: number;
    isUnderReview: boolean;
};

const VERDICTS = [
    {
        value: 'worked',
        label: 'It worked',
        hint: 'Keep running it. A version that worked stays active.',
    },
    {
        value: 'failed',
        label: 'It did not hold',
        hint: 'The strategy did not do what it was meant to.',
    },
    {
        value: 'inconclusive',
        label: 'Inconclusive',
        hint: 'Not enough happened to say. This is a real answer.',
    },
] as const;

/**
 * Concluding is not superseding: a version concluded as `worked` stays active
 * and keeps running. Starting the next one is a separate act.
 */
export function ConcludeExperimentForm({ strategyId, isUnderReview }: Props) {
    const [choice, setChoice] = useState<string>('');

    return (
        <Form {...verdict.store.form(strategyId)} className="space-y-4">
            {({ processing, errors }) => (
                <>
                    <fieldset className="space-y-2">
                        <legend className="ds-label">
                            {isUnderReview
                                ? 'This experiment has reached its review date.'
                                : 'Give this experiment a verdict.'}
                        </legend>

                        {VERDICTS.map((v) => (
                            <label
                                key={v.value}
                                className="flex items-start gap-3"
                            >
                                <input
                                    type="radio"
                                    name="verdict"
                                    value={v.value}
                                    checked={choice === v.value}
                                    onChange={() => setChoice(v.value)}
                                    className="mt-1"
                                />
                                <span>
                                    <span className="block font-medium">
                                        {v.label}
                                    </span>
                                    <span className="block text-sm opacity-70">
                                        {v.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {errors.verdict && (
                            <p className="text-sm text-destructive">
                                {errors.verdict}
                            </p>
                        )}
                    </fieldset>

                    {choice === 'failed' && (
                        <div className="space-y-1">
                            <label htmlFor="verdict-note" className="ds-label">
                                What the strategy did not do
                            </label>
                            <textarea
                                id="verdict-note"
                                name="note"
                                rows={3}
                                className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            />
                            {errors.note && (
                                <p className="text-sm text-destructive">
                                    {errors.note}
                                </p>
                            )}
                        </div>
                    )}

                    <Button
                        type="submit"
                        disabled={processing || choice === ''}
                    >
                        Record the verdict
                    </Button>
                </>
            )}
        </Form>
    );
}
