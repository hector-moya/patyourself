import { useSpriteClock } from '@/hooks/use-sprite-clock';
import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ambientFor, selfStartedFor } from '@/patyourself/companion';
import type {
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';
import { CompanionRoom } from '@/patyourself/companion-room';
import { Button } from '@/patyourself/primitives';
import { SectionHeading } from '@/patyourself/strategy-timeline';

interface CompanionPageProps {
    companion: CompanionData;
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
export default function CompanionPage({ companion }: CompanionPageProps) {
    const { animation, frame, react } = useSpriteClock(
        ambientFor(companion),
        selfStartedFor(companion),
    );
    const nothingYet = companion.unlocks.length === 0;

    return (
        <CoachLayout title="Blob" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-8">
                {nothingYet ? (
                    // Stated as a fact about the record, with nothing to act
                    // on and nothing owed. Not an empty slot, and no empty
                    // room either — there is nobody to put in it yet.
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Blob turns up once there is something in the record. Log
                        an outcome — any outcome — and it arrives.
                    </p>
                ) : (
                    <div className="flex flex-col items-center gap-4">
                        <CompanionRoom
                            companion={companion}
                            animation={animation}
                            frame={frame}
                            className="w-full max-w-md rounded-xl border border-border"
                        />

                        {/* Always enabled. Nothing makes them wait, nothing
                            limits them by the day, and nothing counts them:
                            these are not progress, they touch nothing the
                            resolver reads, and Blob never asks to be pressed. */}
                        <div className="flex gap-2">
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => react('pet')}
                            >
                                Pet
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => react('play')}
                            >
                                Play
                            </Button>
                        </div>
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
