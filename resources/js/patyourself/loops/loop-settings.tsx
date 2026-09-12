import { Form } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
import { StartExperimentForm } from '@/patyourself/loops/start-experiment-form';
import { Button } from '@/patyourself/primitives';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { StrategyData } from '@/patyourself/types';

/** One workflow the loop may record through, straight from the server registry. */
export interface WorkflowOptionData {
    name: string;
    label: string;
}

interface LoopSettingsProps {
    loopId: number;
    workflow: string | null;
    workflows: WorkflowOptionData[];
    /**
     * The active unconcluded version, when the verdict is not already in the
     * experiment card. Undefined when there is none, or when the version is
     * under review and the card holds the question instead.
     */
    endableExperiment: StrategyData | undefined;
    canStartNext: boolean;
    currentCadence: string;
}

/**
 * The rare and consequential controls, in one place.
 *
 * These three used to sit loose in the column between the actions and the
 * outcome history, each competing for the same attention as the record itself:
 * a workflow picker, a start-the-next-experiment disclosure, and — before the
 * verdict was conditioned — a full verdict form near the top of the screen.
 * None of them is the day's business, and all three are hard to undo.
 *
 * One disclosure rather than three: a screen with three collapsed things on it
 * reads as three things you have not done.
 */
export function LoopSettings({
    loopId,
    workflow,
    workflows,
    endableExperiment,
    canStartNext,
    currentCadence,
}: LoopSettingsProps) {
    const showsRecording = workflows.length > 0;

    if (!showsRecording && !canStartNext && endableExperiment === undefined) {
        return null;
    }

    return (
        <details data-testid="loop-settings">
            <summary className="ds-label cursor-pointer">Loop settings</summary>

            <div className="mt-4 flex flex-col gap-6">
                {showsRecording && (
                    <Recording
                        loopId={loopId}
                        current={workflow}
                        workflows={workflows}
                    />
                )}

                {canStartNext && (
                    <section>
                        <SectionHeading>
                            Start the next experiment
                        </SectionHeading>
                        <StartExperimentForm
                            loopId={loopId}
                            currentCadence={currentCadence}
                        />
                    </section>
                )}

                {endableExperiment && (
                    <section>
                        <SectionHeading>
                            End this experiment early
                        </SectionHeading>
                        <ConcludeExperimentForm
                            strategyId={endableExperiment.id}
                            isUnderReview={false}
                        />
                    </section>
                )}
            </div>
        </details>
    );
}

/**
 * What this loop records, on top of whether it happened.
 *
 * "Nothing extra" is the first option and the one a loop already has:
 * recording nothing extra is what almost every loop does, forever, and the
 * control must not read as a field left blank.
 *
 * The options come from the server registry and are never typed. A free-text
 * tag was the earlier answer and it failed silently on `Gym`, `gimnasio` or a
 * trailing space, with nothing on screen to say why — see
 * `UpdateIntentionRequest`, which validates against the same list this is
 * drawn from.
 */
function Recording({
    loopId,
    current,
    workflows,
}: {
    loopId: number;
    current: string | null;
    workflows: WorkflowOptionData[];
}) {
    return (
        <section data-testid="workflow-picker">
            <SectionHeading>Recording</SectionHeading>

            <Form
                {...update.form(loopId)}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-2"
            >
                {({ processing }) => (
                    <>
                        <label htmlFor="loop-workflow" className="sr-only">
                            What this loop records
                        </label>
                        <select
                            id="loop-workflow"
                            name="workflow"
                            defaultValue={current ?? ''}
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Nothing extra</option>
                            {workflows.map((workflow) => (
                                <option
                                    key={workflow.name}
                                    value={workflow.name}
                                >
                                    {workflow.label}
                                </option>
                            ))}
                        </select>

                        <p className="text-xs text-muted-foreground">
                            Most loops record nothing extra — the outcome is the
                            whole record. A workflow adds somewhere to write
                            down what happened during an occasion. Changing this
                            keeps everything already written.
                        </p>

                        <div className="self-start">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}
