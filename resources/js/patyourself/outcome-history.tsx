import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { formatOccasionDay } from '@/patyourself/occasion-date';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { OutcomeEntryData } from '@/patyourself/types';

/**
 * Outcome labels. A skip is not a failure — the occasion never happened — so it
 * reads as its own neutral thing rather than as a softer defeat.
 */
const OUTCOME_LABEL: Record<string, string> = {
    completed: 'Completed',
    failed: 'Did not hold',
    skipped: 'Did not happen',
};

const OUTCOME_TONE: Record<string, string> = {
    completed: 'text-foreground',
    failed: 'text-foreground',
    // Neutral, and deliberately quieter: a skip is information, not a result.
    skipped: 'text-muted-foreground',
};

/**
 * Every outcome recorded on this loop, newest occasion first — dated by when
 * the occasion happened, not by when it was typed.
 *
 * The reason is rendered exactly as the user wrote it. It is the raw material
 * the next experiment gets written from, and tidying it here would be the same
 * mistake as tidying it on the way in.
 */
export function OutcomeHistory({
    outcomes,
    total,
    showingAll,
    loopId,
}: {
    outcomes: OutcomeEntryData[];
    total: number;
    showingAll: boolean;
    loopId: number;
}) {
    return (
        <section>
            <SectionHeading>
                Outcome history
                <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                    ({total})
                </span>
            </SectionHeading>

            {outcomes.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing logged yet.
                </p>
            ) : (
                <>
                    <ol className="flex flex-col divide-y divide-border">
                        {outcomes.map((entry) => (
                            <OutcomeRow key={entry.id} entry={entry} />
                        ))}
                    </ol>

                    {!showingAll && total > outcomes.length && (
                        <Link
                            href={`/loops/${loopId}?history=all`}
                            className="mt-3 inline-block text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                        >
                            Show the full history
                        </Link>
                    )}
                </>
            )}
        </section>
    );
}

function OutcomeRow({ entry }: { entry: OutcomeEntryData }) {
    return (
        <li className="py-3">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-xs text-muted-foreground">
                    {formatOccasionDay(entry.occurred_at)}
                </span>
                {entry.strategy_version !== null && (
                    <span className="text-[10px] text-muted-foreground/70">
                        v{entry.strategy_version}
                    </span>
                )}
            </div>

            <p
                className={cn(
                    'mt-0.5 text-sm',
                    OUTCOME_TONE[entry.outcome] ?? 'text-foreground',
                )}
            >
                {OUTCOME_LABEL[entry.outcome] ?? entry.outcome}
                <span className="text-muted-foreground">
                    {' · '}
                    {entry.action_title}
                </span>
            </p>

            {entry.reason && (
                <p className="mt-1 text-sm text-muted-foreground italic">
                    “{entry.reason}”
                </p>
            )}

            {entry.context && (
                <p className="mt-1 text-xs text-muted-foreground/80">
                    {entry.context}
                </p>
            )}

            {entry.context_fields && (
                <ContextFields fields={entry.context_fields} />
            )}
        </li>
    );
}

function ContextFields({
    fields,
}: {
    fields: NonNullable<OutcomeEntryData['context_fields']>;
}) {
    const parts: string[] = [];

    if (fields.place) {
        parts.push(fields.place);
    }

    if (fields.with_others !== undefined && fields.with_others !== null) {
        parts.push(fields.with_others ? 'with others' : 'alone');
    }

    if (fields.preceded_by) {
        parts.push(`after ${fields.preceded_by}`);
    }

    if (parts.length === 0) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-muted-foreground/70">
            {parts.join(' · ')}
        </p>
    );
}
