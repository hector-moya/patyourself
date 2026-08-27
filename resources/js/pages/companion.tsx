import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Companion } from '@/patyourself/companion';
import type {
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';
import { SectionHeading } from '@/patyourself/strategy-timeline';

interface CompanionPageProps {
    companion: CompanionData;
}

/**
 * Blob's screen: Blob at full size, and a plain list of what it has and when
 * each part arrived.
 *
 * The list is history. There are no locked slots, no count of what is left and
 * no preview of what comes next — the moment the screen shows what has *not*
 * happened it becomes a checklist, and a checklist is a thing to be behind on.
 * Only ever show what has happened.
 */
export default function CompanionPage({ companion }: CompanionPageProps) {
    const nothingYet = companion.unlocks.length === 0;

    return (
        <CoachLayout title="Blob" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-8">
                <div className="flex justify-center py-4">
                    {nothingYet ? (
                        // Stated as a fact about the record, with nothing to act
                        // on and nothing owed. Not an empty slot.
                        <p className="max-w-[34ch] text-center text-sm text-muted-foreground">
                            Blob turns up once there is something in the record.
                            Log an outcome — any outcome — and it arrives.
                        </p>
                    ) : (
                        <Companion companion={companion} size={220} />
                    )}
                </div>

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
