import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { formatOccasionDay } from '@/patyourself/occasion-date';
import { Icon } from '@/patyourself/primitives';
import { OutcomeStrip } from '@/patyourself/progress/outcome-strip';
import type {
    LoopStreak,
    OutcomeContextFields,
    OutcomeMark,
    RecordCutData,
    RecordEntry,
    RecordLoopData,
    RecordReasonData,
    ReflectionData,
} from '@/patyourself/types';
import notes from '@/routes/loops/notes';

interface RecordProgress {
    streak: LoopStreak;
    completion_rate: number | null;
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[];
    last_logged_at: string | null;
}

interface RecordProps {
    loop: RecordLoopData;
    progress: RecordProgress;
    previous_version: { version: number; rate: number } | null;
    cuts: RecordCutData[];
    reasons: RecordReasonData[];
    reasons_total: number;
    reflection: ReflectionData | null;
    entries: RecordEntry[];
    occasions_total: number;
    showing_all_history: boolean;
}

/** Held / Did not hold / Did not happen — never "failed", never "success". */
const OUTCOME: Record<string, { label: string; cls: string }> = {
    completed: { label: 'Held', cls: 'held' },
    failed: { label: 'Did not hold', cls: 'failed' },
    skipped: { label: 'Did not happen', cls: 'skipped' },
};

const FILTERS = [
    { key: 'all', label: 'Everything' },
    { key: 'failed', label: 'Did not hold' },
    { key: 'completed', label: 'Held' },
    { key: 'skipped', label: 'Did not happen' },
    { key: 'note', label: 'Notes' },
];

/**
 * The record for one loop: what happened, and what the history shows.
 *
 * Split from the loop page by tense. The loop states what it *is* — chain,
 * strategy, schedule, versions; this reports what *occurred*. Nothing is on
 * both, so there is never a number here and a different number there.
 *
 * Every figure on the page is `held / decided`, and `decided` excludes skips: a
 * skipped occasion never happened, so it decided nothing and belongs in no
 * denominator. It is counted once in the provenance line and drawn as a dashed
 * cell, never as a miss.
 *
 * Nothing here is a target. There is no goal line, no streak headline and no
 * colour meaning "not enough" — a version that held two times in ten is
 * evidence about the strategy, and the page's job is to say where those eight
 * went.
 */
export default function LoopRecord({
    loop,
    progress,
    previous_version: previousVersion,
    cuts,
    reasons,
    reasons_total: reasonsTotal,
    reflection,
    entries,
    occasions_total: occasionsTotal,
    showing_all_history: showingAll,
}: RecordProps) {
    return (
        <CoachLayout
            title={`${loop.title} — the record`}
            header={<RecordHeader loop={loop} />}
            flush
            bottomNav={<BottomNav />}
        >
            <div className="t-body">
                <div className="d-grid">
                    <div className="d-headcol">
                        <Lede
                            loop={loop}
                            progress={progress}
                            previousVersion={previousVersion}
                        />
                    </div>

                    <aside className="d-aside">
                        <Cuts cuts={cuts} />
                        <Reasons
                            reasons={reasons}
                            total={reasonsTotal}
                            loopId={loop.id}
                        />
                        <Reading reflection={reflection} />
                    </aside>

                    <div className="d-maincol">
                        <Chronology
                            entries={entries}
                            total={occasionsTotal}
                            showingAll={showingAll}
                            loopId={loop.id}
                        />
                    </div>
                </div>
            </div>
        </CoachLayout>
    );
}

/**
 * Back to where you came from, and out to the loop itself.
 *
 * The page has no nav entry — it is always about a loop you were already
 * looking at — so the way back matters more here than anywhere else in the app.
 * `history.back()` rather than a fixed href, because the two entry points are
 * Progress and Loops and a hardcoded destination would be wrong half the time.
 */
function RecordHeader({ loop }: { loop: RecordLoopData }) {
    return (
        <div className="d-top">
            <button
                type="button"
                className="d-back"
                onClick={() => window.history.back()}
                aria-label="Back"
            >
                <Icon name="chevron-left" size={18} />
                <span className="hidden lg:inline">Back</span>
            </button>

            <div className="d-id">
                <h1 className="d-title">{loop.title}</h1>
                <p className="d-meta">{metaLine(loop)}</p>
            </div>

            <Link href={`/loops/${loop.id}`} className="d-loop">
                <Icon name="git-branch" size={15} />
                <span className="hidden lg:inline">The loop itself</span>
            </Link>
        </div>
    );
}

/** "break · v1 · day 18", dropping whichever parts do not apply. */
function metaLine(loop: RecordLoopData): string {
    const parts = [loop.type];

    if (loop.version !== null) {
        parts.push(`v${loop.version}`);
    }

    if (loop.day_of_experiment !== null) {
        parts.push(`day ${loop.day_of_experiment}`);
    }

    return parts.join(' · ');
}

/**
 * The count, the strip it is made of, and everything needed to check it.
 *
 * The provenance line carries the skips that are *not* in the count, the
 * comparison (or the absence of one), the run, and when the record was last
 * added to. Together they are what makes the headline checkable rather than
 * merely stated.
 */
function Lede({
    loop,
    progress,
    previousVersion,
}: {
    loop: RecordLoopData;
    progress: RecordProgress;
    previousVersion: RecordProps['previous_version'];
}) {
    const { completed, failed, skipped } = progress.totals;
    const decided = completed + failed;
    const running =
        progress.streak.outcome === 'completed' && progress.streak.length > 0;

    return (
        <section className="d-lede">
            <p className="d-eyebrow">What the record shows</p>

            {decided === 0 ? (
                <p className="d-empty">
                    Nothing has been decided yet — the record starts on the
                    first occasion you log.
                </p>
            ) : (
                <>
                    <p className="d-count">
                        <b>
                            {completed}
                            <span>/{decided}</span>
                        </b>
                        <span className="d-cread">
                            occasions held · {progress.completion_rate}%
                        </span>
                    </p>

                    <OutcomeStrip recent={progress.recent} />

                    <p className="d-prov">
                        {[
                            skipped > 0 && `${skipped} skipped, not counted`,
                            comparison(progress, previousVersion),
                            running && `${progress.streak.length} in a row`,
                            progress.last_logged_at !== null &&
                                `last recorded ${formatOccasionDay(progress.last_logged_at)}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </>
            )}

            {loop.action_title !== null && (
                <p className="d-action">
                    <Icon name="clock" size={15} />
                    <span>{loop.action_title}</span>
                </p>
            )}
        </section>
    );
}

/**
 * What this version is measured against, or plainly that it is not.
 *
 * Never invents a comparison. With one version there is nothing to compare
 * against, and a flat delta against zero would read as an improvement that no
 * evidence supports.
 */
function comparison(
    progress: RecordProgress,
    previous: RecordProps['previous_version'],
): string {
    if (previous === null) {
        return 'first version, nothing to compare against yet';
    }

    const mine = progress.completion_rate ?? 0;
    const verdict =
        mine > previous.rate
            ? 'ahead of it'
            : mine < previous.rate
              ? 'behind it'
              : 'level with it';

    return `v${previous.version} held ${previous.rate}% · ${verdict}`;
}

/**
 * Four cuts of the same record, switched by chips.
 *
 * Every cut sums to the same decided count — that is what makes them
 * comparable, and why an occasion with no context recorded appears in a "Not
 * recorded" row instead of quietly vanishing from one cut and not another.
 *
 * A group that has never held is emphasised rather than hidden. It is the
 * finding: the reader came to learn where it goes wrong, and a row reading
 * 0/3 is the most useful thing on the page.
 */
function Cuts({ cuts }: { cuts: RecordCutData[] }) {
    const [active, setActive] = useState(cuts[0]?.key ?? 'day');
    const cut = cuts.find((c) => c.key === active) ?? cuts[0];

    if (cut === undefined || cut.rows.length === 0) {
        return null;
    }

    return (
        <section className="d-sec">
            <h2 className="d-sech">Where it goes</h2>

            <div className="d-tabs">
                {cuts.map((option) => (
                    <button
                        key={option.key}
                        type="button"
                        aria-pressed={option.key === active}
                        onClick={() => setActive(option.key)}
                        className={cn(
                            'py-chip py-chip--btn d-tab',
                            option.key === active && 'is-active',
                        )}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            <ul className="d-bars">
                {cut.rows.map((row) => (
                    <li
                        key={row.name}
                        className={cn(row.held === 0 && 'is-none')}
                    >
                        <span className="d-bname">{row.name}</span>
                        <span className="d-btrack">
                            <i
                                style={{
                                    width: `${Math.round((row.held / row.decided) * 100)}%`,
                                }}
                            />
                        </span>
                        <span className="d-bnum">
                            {row.held}/{row.decided}
                        </span>
                    </li>
                ))}
            </ul>

            <p className="d-note">
                <span>held of decided · skips excluded</span>
            </p>
        </section>
    );
}

/**
 * The words written when it did not hold.
 *
 * Verbatim — no capitalisation fixed, no sentence tidied, nothing summarised.
 * They are the user's own words at the moment it mattered, and the most
 * valuable content on the page.
 */
function Reasons({
    reasons,
    total,
    loopId,
}: {
    reasons: RecordReasonData[];
    total: number;
    loopId: number;
}) {
    if (reasons.length === 0) {
        return null;
    }

    return (
        <section className="d-sec">
            <h2 className="d-sech">What you said when it didn&rsquo;t hold</h2>
            <ul className="d-quotes">
                {reasons.map((entry) => (
                    <li key={entry.id}>
                        <p>&ldquo;{entry.reason}&rdquo;</p>
                        <span>{formatOccasionDay(entry.occurred_at)}</span>
                    </li>
                ))}
            </ul>
            {total > reasons.length && (
                <Link
                    href={`/loops/${loopId}/record?history=all`}
                    className="d-more"
                >
                    All {total} reasons
                </Link>
            )}
        </section>
    );
}

/** The reflection, with the record it was written from. */
function Reading({ reflection }: { reflection: ReflectionData | null }) {
    return (
        <section className="d-sec">
            <h2 className="d-sech">The reading</h2>

            {reflection === null ? (
                // A fact, not an outstanding item. Nothing in this app nags.
                <p className="d-empty">No reflection written yet.</p>
            ) : (
                <>
                    <p data-testid="reflection-body" className="d-refl">
                        {reflection.content}
                    </p>
                    <Provenance reflection={reflection} />
                </>
            )}
        </section>
    );
}

/**
 * Rendered only when the record carries a window. A reflection written before
 * those columns existed still has words worth reading, and a half-empty date
 * range would read as a bug.
 */
function Provenance({ reflection }: { reflection: ReflectionData }) {
    const {
        window_start: start,
        window_end: end,
        events_count: count,
    } = reflection;

    if (start === null || end === null || count === null) {
        return null;
    }

    return (
        <p data-testid="reflection-provenance" className="d-prov">
            Written from {count} {count === 1 ? 'occasion' : 'occasions'} ·{' '}
            {formatWindow(start, end)}
        </p>
    );
}

/** `13–27 Aug`, or `28 Jul – 11 Aug` across two months. Provenance, not a headline. */
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

/**
 * Every occasion, newest first, with notes interleaved by date.
 *
 * The filter is client-side because the whole page already has the rows; a
 * round trip to hide four of them would be slower and would lose the scroll.
 */
function Chronology({
    entries,
    total,
    showingAll,
    loopId,
}: {
    entries: RecordEntry[];
    total: number;
    showingAll: boolean;
    loopId: number;
}) {
    const [filter, setFilter] = useState('all');

    const rows = entries.filter((entry) => {
        if (filter === 'all') {
            return true;
        }

        return filter === 'note'
            ? entry.kind === 'note'
            : entry.kind === 'occasion' && entry.outcome === filter;
    });

    const shownOccasions = rows.filter(
        (entry) => entry.kind === 'occasion',
    ).length;

    return (
        <section className="d-sec">
            <h2 className="d-sech">
                Every occasion<span className="d-of"> ({total})</span>
            </h2>

            <div className="d-tabs">
                {FILTERS.map((option) => (
                    <button
                        key={option.key}
                        type="button"
                        aria-pressed={option.key === filter}
                        onClick={() => setFilter(option.key)}
                        className={cn(
                            'py-chip py-chip--btn d-tab',
                            option.key === filter && 'is-active',
                        )}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            {rows.length === 0 ? (
                <p className="d-empty">Nothing recorded under that yet.</p>
            ) : (
                <ul className="d-rows">
                    {rows.map((entry) => (
                        <Row key={`${entry.kind}-${entry.id}`} entry={entry} />
                    ))}
                </ul>
            )}

            <p className="d-showing">
                {shownOccasions > 0 &&
                    `Showing ${shownOccasions} of ${total} occasions`}
                {!showingAll &&
                    total > shownOccasions &&
                    shownOccasions > 0 && (
                        <>
                            {' · '}
                            <Link href={`/loops/${loopId}/record?history=all`}>
                                the full record
                            </Link>
                        </>
                    )}
            </p>

            <AddNote loopId={loopId} />
        </section>
    );
}

function Row({ entry }: { entry: RecordEntry }) {
    if (entry.kind === 'note') {
        return (
            <li className="d-row d-row--note">
                <p className="d-when">
                    {formatOccasionDay(entry.occurred_at)}
                    <span>note</span>
                </p>
                <p className="d-body">{entry.body}</p>
            </li>
        );
    }

    const outcome = OUTCOME[entry.outcome] ?? {
        label: entry.outcome,
        cls: 'skipped',
    };

    return (
        <li className="d-row">
            <p className="d-when">
                {formatOccasionDay(entry.occurred_at)}
                {entry.strategy_version !== null && (
                    <span>v{entry.strategy_version}</span>
                )}
            </p>

            <p className={`d-verdict is-${outcome.cls}`}>
                {outcome.label}
                <em> · {entry.action_title}</em>
            </p>

            {entry.reason && (
                <p className="d-quote">&ldquo;{entry.reason}&rdquo;</p>
            )}
            {entry.context && <p className="d-ctx">{entry.context}</p>}
            {contextFields(entry.context_fields) && (
                <p className="d-fields">
                    {contextFields(entry.context_fields)}
                </p>
            )}
            {/* An occasion answered the next morning is a different kind of
                record from one answered as it happened. Said, not hidden. */}
            {entry.logged_later && (
                <p className="d-fields">
                    logged {formatOccasionDay(entry.logged_at)}
                </p>
            )}
        </li>
    );
}

/** "bedroom · alone · after getting into bed", omitting whatever is absent. */
function contextFields(fields: OutcomeContextFields | null): string | null {
    if (fields === null) {
        return null;
    }

    const parts = [
        fields.place,
        fields.with_others === null || fields.with_others === undefined
            ? null
            : fields.with_others
              ? 'with others'
              : 'alone',
        fields.preceded_by ? `after ${fields.preceded_by}` : null,
    ].filter((part): part is string => typeof part === 'string' && part !== '');

    return parts.length > 0 ? parts.join(' · ') : null;
}

/**
 * The one write on this page.
 *
 * A note is an observation that is not an outcome — stored verbatim, attached
 * to no occasion, never edited and never deleted. The record is append-only.
 */
function AddNote({ loopId }: { loopId: number }) {
    return (
        <Form
            {...notes.store.form(loopId)}
            resetOnSuccess
            className="d-add"
            data-testid="add-note"
        >
            {({ processing, errors }) => (
                <>
                    <label htmlFor="record-note" className="d-addlab">
                        Something you noticed
                    </label>
                    <textarea
                        id="record-note"
                        name="body"
                        rows={2}
                        className="d-input"
                        placeholder="Kept for the record, attached to no occasion"
                    />
                    {errors.body && (
                        <p className="text-sm text-destructive">
                            {errors.body}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={processing}
                        className="py-btn py-btn--secondary py-btn--sm"
                    >
                        Keep this note
                    </button>
                </>
            )}
        </Form>
    );
}
