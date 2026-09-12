import { cn } from '@/lib/utils';
import type { IntentionData } from '@/patyourself/types';

/**
 * Each stage carries its own accent, defined as `--stage-*` in patyourself.css.
 * The four exist in the palette and are named for exactly these stages; painting
 * the intervention point with the generic primary threw that away and made the
 * chain read as one undifferentiated list.
 */
const STAGES = [
    { key: 'cue', label: 'Cue', hint: 'the trigger', accent: 'cue' },
    {
        key: 'craving',
        label: 'Craving',
        hint: 'the motivation',
        accent: 'craving',
    },
    {
        key: 'response',
        label: 'Response',
        hint: 'the behaviour',
        accent: 'response',
    },
    { key: 'reward', label: 'Reward', hint: 'the payoff', accent: 'reward' },
] as const;

/**
 * The loop's four stages, collapsed behind the chain that names them.
 *
 * The anatomy is the loop's identity and it is also the least changeable thing
 * on the screen — it is written once at authoring and then read occasionally.
 * Full-size it cost about a screen and a half above the actions, so it is
 * disclosed rather than drawn.
 *
 * What survives the collapse is chosen, not incidental: the four stage words,
 * so the loop still looks like itself, and a mark on the stage the active
 * strategy acts upon, because that is the only part of the anatomy that moves
 * over a loop's life. Everything else waits behind the tap.
 *
 * A `<details>` rather than a modal or a tab: this screen already discloses two
 * other controls the same way, and a disclosure needs no focus management and
 * no second navigation layer above the bottom nav.
 */
export function Anatomy({
    intention,
    interventionPoint,
}: {
    intention: IntentionData;
    interventionPoint: string | null;
}) {
    return (
        <details data-testid="habit-anatomy">
            <summary className="ds-label cursor-pointer">
                <LoopChain interventionPoint={interventionPoint} />
            </summary>

            <ol className="relative mt-3 flex flex-col gap-2">
                {STAGES.map((stage, index) => {
                    const acts = stage.key === interventionPoint;

                    return (
                        <li key={stage.key} className="flex gap-3">
                            <div className="flex flex-col items-center">
                                <span
                                    className="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                                    style={{
                                        borderColor: `var(--stage-${stage.accent})`,
                                        backgroundColor: acts
                                            ? `var(--stage-${stage.accent})`
                                            : `var(--stage-${stage.accent}-soft)`,
                                        color: acts
                                            ? 'var(--stage-on-accent, #FFF8F3)'
                                            : `var(--stage-${stage.accent})`,
                                    }}
                                >
                                    {index + 1}
                                </span>
                                {index < STAGES.length - 1 && (
                                    <span className="my-1 w-px flex-1 bg-border" />
                                )}
                            </div>

                            <div
                                className={cn(
                                    'mb-1 flex-1 rounded-xl border p-3',
                                    !acts && 'border-border',
                                )}
                                style={
                                    acts
                                        ? {
                                              borderColor: `var(--stage-${stage.accent})`,
                                              backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                          }
                                        : undefined
                                }
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span
                                        className="text-xs font-semibold tracking-wide uppercase"
                                        style={{
                                            color: `var(--stage-${stage.accent})`,
                                        }}
                                    >
                                        {stage.label}
                                        <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                                            · {stage.hint}
                                        </span>
                                    </span>
                                    {acts && (
                                        <span
                                            className="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            style={{
                                                backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                                color: `var(--stage-${stage.accent})`,
                                            }}
                                        >
                                            strategy acts here
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-foreground">
                                    {intention[stage.key]}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </details>
    );
}

/**
 * The chain, on one line, in the summary.
 *
 * The acting stage is drawn at full weight in its own accent and the other
 * three are muted. No badge and no extra wording — the value of this line is
 * that it is one line, and anything that can wrap defeats it.
 */
function LoopChain({ interventionPoint }: { interventionPoint: string | null }) {
    return (
        <span
            data-testid="loop-chain"
            className="inline-flex flex-nowrap items-center gap-1 text-xs normal-case"
        >
            {STAGES.map((stage, index) => {
                const acts = stage.key === interventionPoint;

                return (
                    <span key={stage.key} className="inline-flex items-center gap-1">
                        {index > 0 && (
                            <span aria-hidden="true" className="text-muted-foreground/50">
                                →
                            </span>
                        )}
                        <span
                            data-acting={acts ? 'true' : undefined}
                            className={cn(
                                acts ? 'font-semibold' : 'text-muted-foreground',
                            )}
                            style={
                                acts
                                    ? { color: `var(--stage-${stage.accent})` }
                                    : undefined
                            }
                        >
                            {stage.label}
                        </span>
                    </span>
                );
            })}
        </span>
    );
}
