import { formatOccasionDay } from '@/patyourself/occasion-date';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { NoteData } from '@/patyourself/types';

/**
 * Observations attached to the loop and to no occasion — the things noticed
 * between check-ins that are not outcomes. Read-only here: notes are written
 * from the conversation, and shown verbatim for the same reason reasons are.
 */
export function LoopNotes({ notes }: { notes: NoteData[] }) {
    return (
        <section>
            <SectionHeading>
                Notes
                <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                    ({notes.length})
                </span>
            </SectionHeading>

            {notes.length === 0 ? (
                <p className="text-sm text-muted-foreground">No notes yet.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-border">
                    {notes.map((note) => (
                        <li key={note.id} className="py-3">
                            <span className="text-xs text-muted-foreground">
                                {formatOccasionDay(note.noted_at, {
                                    day: 'numeric',
                                    month: 'short',
                                })}
                            </span>
                            <p className="mt-0.5 text-sm text-foreground">
                                {note.body}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
