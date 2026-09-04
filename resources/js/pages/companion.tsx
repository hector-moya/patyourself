import { useSpriteClock } from '@/hooks/use-sprite-clock';
import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import {
    actionsFor,
    ambientFor,
    selfStartedFor,
} from '@/patyourself/companion';
import type {
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';
import { CompanionRoom } from '@/patyourself/companion-room';
import { SectionHeading } from '@/patyourself/strategy-timeline';

interface CompanionPageProps {
    companion: CompanionData;
    /**
     * One thing Blob has to say this visit, or null. Written by the coach and
     * relayed verbatim — the app does not compose it and does not edit it.
     *
     * Only ever on this screen. A line beside every logged breakfast is
     * wallpaper within a week, and wallpaper is worse than silence.
     */
    remark?: string | null;
}

/**
 * Blob's screen: the room, two things you can do to Blob, and a plain list of
 * what it has and when each part arrived.
 *
 * The list is history. There are no locked slots, no count of what is left and
 * no preview of what comes next — the moment the screen shows what has *not*
 * happened it becomes a checklist, and a checklist is a thing to be behind on.
 * Only ever show what has happened.
 *
 * The clock lives here rather than inside the drawing because the buttons need
 * to reach it. Everything on this screen reads the same two numbers.
 */
export default function CompanionPage({
    companion,
    remark = null,
}: CompanionPageProps) {
    const { animation, frame, react } = useSpriteClock(
        ambientFor(companion),
        selfStartedFor(companion),
    );
    const nothingYet = companion.unlocks.length === 0;

    // Whether the remark shows is `remark !== null` alone — the server's call,
    // via CompanionController, on whether Blob exists. `nothingYet` decides
    // the room and the buttons here, same as always, but must never also gate
    // the remark: `nothingYet` and the controller's `stageIndex() === 0` are
    // two independent expressions of the same fact, identical today only
    // because nothing enforces that they agree. If they ever diverged, a
    // remark nested inside the `nothingYet` branch would have already been
    // burned into the session by the controller and then never drawn — the
    // exact failure `test_before_blob_exists_no_remark_is_drawn` exists to
    // prevent, just reached from the other side.
    const showRoomCluster = !nothingYet || remark !== null;

    return (
        <CoachLayout title="Blob" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-8">
                {nothingYet && (
                    // Stated as a fact about the record, with nothing to act
                    // on and nothing owed. Not an empty slot, and no empty
                    // room either — there is nobody to put in it yet.
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Blob turns up once there is something in the record. Log
                        an outcome — any outcome — and it arrives.
                    </p>
                )}

                {showRoomCluster && (
                    <div className="flex flex-col items-center gap-4">
                        {!nothingYet && (
                            <div className="pixel-frame w-full max-w-md">
                                <CompanionRoom
                                    companion={companion}
                                    animation={animation}
                                    frame={frame}
                                />
                            </div>
                        )}

                        {remark !== null && (
                            <p
                                data-testid="companion-remark"
                                className="max-w-md text-center text-sm text-balance text-muted-foreground"
                            >
                                {remark}
                            </p>
                        )}

                        {/* Never disabled, never on a timer, never counted:
                            pressing one is not progress, none of them touch
                            anything the resolver reads, and Blob never asks to
                            be pressed.

                            Which ones are here is a different question from
                            whether they work, and it is `actionsFor`'s — the
                            two done to Blob are always present, and one that
                            asks Blob to use an ability appears only once the
                            ladder has announced it, absent until then rather
                            than greyed. */}
                        {!nothingYet && (
                            <div className="flex flex-wrap justify-center gap-2">
                                {actionsFor(companion).map((action) => (
                                    <button
                                        key={action.animation}
                                        type="button"
                                        className="pixel-button"
                                        onClick={() => react(action.animation)}
                                    >
                                        {action.label}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {!nothingYet && (
                    <section>
                        <SectionHeading>What has happened</SectionHeading>
                        <ul className="divide-y divide-border">
                            {/* Newest first: the most recent thing is the thing
                                being read, and the beginning stays at the end
                                where it belongs. */}
                            {[...companion.unlocks]
                                .reverse()
                                .map((unlock, index) => (
                                    <UnlockRow
                                        key={`${unlock.kind}-${unlock.name}-${index}`}
                                        unlock={unlock}
                                    />
                                ))}
                        </ul>
                    </section>
                )}
            </div>
        </CoachLayout>
    );
}

function UnlockRow({ unlock }: { unlock: CompanionUnlockData }) {
    return (
        <li className="py-3">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm text-foreground">{label(unlock)}</span>
                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                    {formatDay(unlock.unlocked_at)}
                </span>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
                {unlock.message}
            </p>
        </li>
    );
}

/** "scarf", or "scarf (coral)" once a type has been recoloured. */
function label(unlock: CompanionUnlockData): string {
    return unlock.variant === null
        ? unlock.name
        : `${unlock.name} (${unlock.variant})`;
}

function formatDay(iso: string | null): string {
    return iso === null
        ? ''
        : new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}
