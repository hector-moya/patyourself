import { Form } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/patyourself/primitives';
import type { LogOutcome } from '@/patyourself/types';

export interface VerdictFormProps {
    /** Where the verdict posts. Every caller resolves this through a
     *  Wayfinder helper — the two routes differ in what they key on, and
     *  choosing between them is the caller's question, not this form's. */
    action: string;
    className?: string;
    testId?: string;
}

/**
 * The verdict controls: which outcome, the reason a failure carries, and the
 * press that records it.
 *
 * Extracted at the third identical copy — dashboard, catch-up and the gym
 * session screen — where it had already drifted once: catch-up passed
 * `className` to `Button`, which does not accept one, and that was the
 * repository's only TypeScript error. Three copies of a form is three places
 * to fix the next thing, and this one carries a rule that must not vary.
 *
 * `skipped` means the occasion never happened. `failed` means it happened and
 * the strategy did not hold — including simply not thinking about it. Neither
 * label says anything about the person.
 *
 * A failure carries the user's own words, the same rule the tool boundary
 * enforces, for the same reason: the reason is the evidence a revision is
 * argued from, and a verdict without one is an outcome nobody can learn from.
 *
 * Entering a workflow's record never decides the outcome — filling in three
 * sets and then marking the session missed because you cut it short is a real
 * thing that happens, and a form that inferred "done" from the presence of
 * data would be overruling the person who was there. Which is why this form
 * knows nothing about any workflow.
 */
export default function VerdictForm({
    action,
    className = 'mt-2 flex flex-col gap-2',
    testId,
}: VerdictFormProps) {
    const [outcome, setOutcome] = useState<LogOutcome | null>(null);

    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className={className}
            data-testid={testId}
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
                        <div className="self-start">
                            <Button type="submit" disabled={processing}>
                                Log it
                            </Button>
                        </div>
                    )}
                </>
            )}
        </Form>
    );
}

const OUTCOMES: { value: LogOutcome; label: string }[] = [
    { value: 'completed', label: 'Did it' },
    { value: 'failed', label: 'Did not hold' },
    { value: 'skipped', label: 'Never happened' },
];
