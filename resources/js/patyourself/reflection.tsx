import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { ReflectionData } from '@/patyourself/types';

/**
 * The loop's rolling narrative, and the record it was written from.
 *
 * Claude supplies the words through `write-reflection`; the window and the
 * occasion count come from the database. Rendering the provenance alongside the
 * prose is the point — it is the difference between evidence and an assertion,
 * and it is why the numbers sit in mono while the sentences do not.
 *
 * The body is verbatim. Nothing here trims, squishes or re-cases it.
 */
export function Reflection({
    reflection,
}: {
    reflection: ReflectionData | null;
}) {
    return (
        <section>
            <SectionHeading>What the record shows</SectionHeading>

            {reflection === null ? (
                // A fact, not an outstanding item. The notebook never nags, and
                // there is no coach in the app to be waiting on.
                <p className="text-sm text-muted-foreground">
                    No reflection written yet.
                </p>
            ) : (
                <>
                    <p
                        data-testid="reflection-body"
                        className="text-sm whitespace-pre-line text-foreground"
                    >
                        {reflection.content}
                    </p>
                    <Provenance reflection={reflection} />
                </>
            )}
        </section>
    );
}

/**
 * Rendered only when the record actually carries a window. A reflection written
 * before those columns existed still has words worth reading, and a half-empty
 * date range would read as a bug.
 */
function Provenance({ reflection }: { reflection: ReflectionData }) {
    const { window_start: start, window_end: end, events_count: count } = reflection;

    if (start === null || end === null || count === null) {
        return null;
    }

    return (
        <p
            data-testid="reflection-provenance"
            className="mt-2 font-mono text-xs text-muted-foreground"
        >
            {formatWindow(start, end)} · {count}{' '}
            {count === 1 ? 'occasion' : 'occasions'}
        </p>
    );
}

/**
 * `13–27 Aug` when the window sits inside one month, `28 Jul – 11 Aug` when it
 * spans two. Short because it is provenance, not a headline.
 */
function formatWindow(start: string, end: string): string {
    const from = new Date(start);
    const to = new Date(end);

    const day = (date: Date) => date.getUTCDate();
    const month = (date: Date) =>
        date.toLocaleString('en-GB', { month: 'short', timeZone: 'UTC' });

    return month(from) === month(to)
        ? `${day(from)}–${day(to)} ${month(to)}`
        : `${day(from)} ${month(from)} – ${day(to)} ${month(to)}`;
}
