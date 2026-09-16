import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    RecordCutData,
    RecordEntry,
    RecordLoopData,
} from '@/patyourself/types';

const page = {
    url: '/loops/1/record',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import LoopRecord from './record';

function loop(overrides: Partial<RecordLoopData> = {}): RecordLoopData {
    return {
        id: 1,
        title: 'No reels before bed or on waking',
        type: 'break',
        version: 1,
        day_of_experiment: 18,
        action_title: 'Phone goes on the TV table and stays there',
        ...overrides,
    };
}

function occasion(
    overrides: Partial<Extract<RecordEntry, { kind: 'occasion' }>> = {},
): RecordEntry {
    return {
        kind: 'occasion',
        id: 1,
        occurred_at: '2026-09-09T21:00:00+00:00',
        logged_at: '2026-09-09T21:30:00+00:00',
        logged_later: false,
        action_title: 'Phone goes on the TV table and stays there',
        outcome: 'completed',
        reason: null,
        context: null,
        context_fields: null,
        strategy_version: 1,
        ...overrides,
    };
}

function renderRecord(
    overrides: Partial<React.ComponentProps<typeof LoopRecord>> = {},
) {
    return render(
        <LoopRecord
            loop={loop()}
            progress={{
                streak: { outcome: 'completed', length: 3 },
                completion_rate: 60,
                totals: { completed: 12, failed: 8, skipped: 2 },
                recent: ['completed', 'failed', 'completed'],
                last_logged_at: '2026-09-09T21:00:00+00:00',
            }}
            previous_version={null}
            cuts={[]}
            reasons={[]}
            reasons_total={0}
            reflection={null}
            entries={[]}
            occasions_total={22}
            showing_all_history={false}
            {...overrides}
        />,
    );
}

describe('LoopRecord', () => {
    it('names the loop and the version the record is of', () => {
        renderRecord();

        expect(
            screen.getByText('No reels before bed or on waking'),
        ).toBeInTheDocument();
        expect(screen.getByText('break · v1 · day 18')).toBeInTheDocument();
    });

    /**
     * The count and its denominator are always together, and the denominator
     * excludes skips: 12 held of 20 decided, with the 2 skipped named
     * separately. Fold them in and the rate silently drops for occasions that
     * never happened.
     */
    it('counts held against decided, with skips named but not counted', () => {
        renderRecord();

        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('/20')).toBeInTheDocument();
        expect(screen.getByText(/2 skipped, not counted/i)).toBeInTheDocument();
    });

    it('carries the run and when the record was last added to', () => {
        renderRecord();

        expect(screen.getByText(/3 in a row/i)).toBeInTheDocument();
        expect(screen.getByText(/last recorded/i)).toBeInTheDocument();
    });

    /**
     * Never invent a comparison. With one version there is nothing to compare
     * against, and a delta against zero would read as an improvement no
     * evidence supports.
     */
    it('says there is nothing to compare a first version against', () => {
        renderRecord();

        expect(
            screen.getByText(/first version, nothing to compare against yet/i),
        ).toBeInTheDocument();
    });

    it('compares against the version it replaced when there is one', () => {
        renderRecord({ previous_version: { version: 1, rate: 43 } });

        expect(
            screen.getByText(/v1 held 43% · ahead of it/i),
        ).toBeInTheDocument();
    });

    it('says a version is behind when it is', () => {
        renderRecord({
            previous_version: { version: 1, rate: 80 },
        });

        expect(
            screen.getByText(/v1 held 80% · behind it/i),
        ).toBeInTheDocument();
    });

    it('states an empty record rather than rendering a zero', () => {
        renderRecord({
            progress: {
                streak: { outcome: null, length: 0 },
                completion_rate: null,
                totals: { completed: 0, failed: 0, skipped: 0 },
                recent: [],
                last_logged_at: null,
            },
        });

        expect(
            screen.getByText(/nothing has been decided yet/i),
        ).toBeInTheDocument();
        expect(screen.queryByText('0%')).toBeNull();
    });

    describe('the cuts', () => {
        const cuts: RecordCutData[] = [
            {
                key: 'day',
                label: 'Day of the week',
                rows: [
                    { name: 'Thursday', held: 1, decided: 3 },
                    { name: 'Friday', held: 0, decided: 3 },
                ],
            },
            {
                key: 'place',
                label: 'Where you were',
                rows: [
                    { name: 'Bedroom', held: 6, decided: 7 },
                    { name: 'Not recorded', held: 1, decided: 1 },
                ],
            },
        ];

        it('shows held against decided on every row', () => {
            renderRecord({ cuts });

            expect(screen.getByText('1/3')).toBeInTheDocument();
            expect(screen.getByText('0/3')).toBeInTheDocument();
        });

        /**
         * A group that has never once held is what the reader came for. It is
         * emphasised rather than left to blend in with the rows that are fine.
         */
        it('marks a group that has never held', () => {
            const { container } = renderRecord({ cuts });

            const rows = [...container.querySelectorAll('.d-bars li')];
            const friday = rows.find((row) =>
                row.textContent?.includes('Friday'),
            );
            const thursday = rows.find((row) =>
                row.textContent?.includes('Thursday'),
            );

            expect(friday).toHaveClass('is-none');
            expect(thursday).not.toHaveClass('is-none');
        });

        it('switches cut without losing the section', () => {
            renderRecord({ cuts });

            expect(screen.getByText('Thursday')).toBeInTheDocument();

            fireEvent.click(
                screen.getByRole('button', { name: 'Where you were' }),
            );

            expect(screen.getByText('Bedroom')).toBeInTheDocument();
            expect(screen.queryByText('Thursday')).toBeNull();
        });

        /**
         * Occasions with no context recorded are shown, not dropped. Every cut
         * has to add up to the same decided count, and silently discarding them
         * is how two cuts of one record end up disagreeing about how much
         * record there is.
         */
        it('keeps unrecorded context visible as its own row', () => {
            renderRecord({ cuts });

            fireEvent.click(
                screen.getByRole('button', { name: 'Where you were' }),
            );

            expect(screen.getByText('Not recorded')).toBeInTheDocument();
        });

        it('says the denominator excludes skips', () => {
            renderRecord({ cuts });

            expect(
                screen.getByText(/held of decided · skips excluded/i),
            ).toBeInTheDocument();
        });
    });

    describe('the words', () => {
        /**
         * Verbatim. No capitalisation fixed, no sentence tidied, nothing
         * summarised — they are the user's own words at the moment it mattered
         * and the most valuable content on the page.
         */
        it('quotes a reason exactly as it was written', () => {
            const raw =
                'phone is still with me because im recording this after';

            renderRecord({
                reasons: [
                    {
                        id: 4,
                        reason: raw,
                        occurred_at: '2026-09-06T21:00:00+00:00',
                    },
                ],
                reasons_total: 1,
            });

            expect(screen.getByText(`“${raw}”`)).toBeInTheDocument();
        });

        it('offers the rest only when there are more than it shows', () => {
            renderRecord({
                reasons: [
                    {
                        id: 4,
                        reason: 'too tired',
                        occurred_at: '2026-09-06T21:00:00+00:00',
                    },
                ],
                reasons_total: 8,
            });

            expect(
                screen.getByRole('link', { name: /all 8 reasons/i }),
            ).toHaveAttribute('href', '/loops/1/record?history=all');
        });

        it('omits the section entirely when nothing was ever said', () => {
            renderRecord();

            expect(screen.queryByText(/what you said when/i)).toBeNull();
        });
    });

    describe('the reading', () => {
        it('states plainly when no reflection has been written', () => {
            renderRecord();

            expect(
                screen.getByText(/no reflection written yet/i),
            ).toBeInTheDocument();
        });

        /** Provenance is what makes it evidence rather than an assertion. */
        it('carries the window and the count it was written from', () => {
            renderRecord({
                reflection: {
                    content: 'The misses are not spread out.',
                    window_start: '2026-08-26T00:00:00+00:00',
                    window_end: '2026-09-09T00:00:00+00:00',
                    events_count: 22,
                },
            });

            expect(
                screen.getByTestId('reflection-provenance'),
            ).toHaveTextContent('Written from 22 occasions · 26 Aug – 9 Sep');
        });

        it('renders a reflection with no window without a half-empty range', () => {
            renderRecord({
                reflection: {
                    content: 'Older reflection.',
                    window_start: null,
                    window_end: null,
                    events_count: null,
                },
            });

            expect(screen.getByTestId('reflection-body')).toBeInTheDocument();
            expect(screen.queryByTestId('reflection-provenance')).toBeNull();
        });
    });

    describe('the chronology', () => {
        const entries: RecordEntry[] = [
            occasion({ id: 1, outcome: 'completed' }),
            occasion({
                id: 2,
                outcome: 'failed',
                reason: 'It’s Friday and I stayed a bit later',
                occurred_at: '2026-09-04T21:00:00+00:00',
            }),
            occasion({
                id: 3,
                outcome: 'skipped',
                occurred_at: '2026-08-30T21:00:00+00:00',
            }),
            {
                kind: 'note',
                id: 9,
                occurred_at: '2026-09-06T10:00:00+00:00',
                body: 'The weekend version of this is a different animal.',
            },
        ];

        /** Held / Did not hold / Did not happen — never "failed", never "success". */
        it('names outcomes in the words the app uses everywhere', () => {
            renderRecord({ entries });

            expect(screen.getAllByText(/^Held/).length).toBeGreaterThan(0);
            expect(screen.getAllByText(/^Did not hold/).length).toBeGreaterThan(
                0,
            );
            expect(
                screen.getAllByText(/^Did not happen/).length,
            ).toBeGreaterThan(0);
            expect(screen.queryByText(/\bfailed\b/i)).toBeNull();
            expect(screen.queryByText(/\bsuccess\b/i)).toBeNull();
        });

        it('interleaves notes with occasions rather than listing them apart', () => {
            const { container } = renderRecord({ entries });

            expect(container.querySelector('.d-row--note')).toBeInTheDocument();
            expect(
                screen.getByText(/the weekend version of this/i),
            ).toBeInTheDocument();
        });

        it('filters to one kind of row', () => {
            const { container } = renderRecord({ entries });

            fireEvent.click(screen.getByRole('button', { name: 'Notes' }));

            expect(
                screen.getByText(/the weekend version of this/i),
            ).toBeInTheDocument();
            // Scoped to the rows: "Held" is also a filter chip, and an
            // unscoped query matches the control rather than the content.
            expect(
                container.querySelectorAll('.d-rows .d-verdict'),
            ).toHaveLength(0);
        });

        /**
         * An occasion answered the next morning is a different kind of record
         * from one answered as it happened. Said on the row, not hidden.
         */
        it('says when an occasion was answered on a later day', () => {
            renderRecord({
                entries: [
                    occasion({
                        logged_later: true,
                        logged_at: '2026-09-10T08:00:00+00:00',
                    }),
                ],
            });

            expect(screen.getByText(/^logged /)).toBeInTheDocument();
        });

        it('says nothing about logging when it happened the same day', () => {
            renderRecord({ entries: [occasion({ logged_later: false })] });

            expect(screen.queryByText(/^logged /)).toBeNull();
        });

        it('stamps the version each occasion was recorded under', () => {
            renderRecord({ entries: [occasion({ strategy_version: 2 })] });

            expect(screen.getByText('v2')).toBeInTheDocument();
        });

        it('offers the full record only while something is held back', () => {
            renderRecord({ entries, occasions_total: 40 });

            expect(
                screen.getByRole('link', { name: /the full record/i }),
            ).toHaveAttribute('href', '/loops/1/record?history=all');
        });

        it('offers no full-record link once it is showing everything', () => {
            renderRecord({
                entries,
                occasions_total: 3,
                showing_all_history: true,
            });

            expect(screen.queryByText(/the full record/i)).toBeNull();
        });

        /** The one write on the page. A note attaches to no occasion. */
        it('keeps the add-note form on the page', () => {
            renderRecord();

            const form = screen.getByTestId('add-note');

            expect(form).toHaveAttribute('action', '/loops/1/notes');
            expect(
                within(form).getByRole('button', { name: /keep this note/i }),
            ).toBeInTheDocument();
        });
    });

    describe('the way back', () => {
        it('links out to the loop itself', () => {
            renderRecord();

            const links = screen.getAllByRole('link');
            const toLoop = links.find(
                (link) => link.getAttribute('href') === '/loops/1',
            );

            expect(toLoop).toBeDefined();
        });

        /**
         * Reached from Progress and from Loops, so the way back cannot be a
         * fixed href — it would be wrong half the time.
         */
        it('goes back to wherever you came from', () => {
            const back = vi
                .spyOn(window.history, 'back')
                .mockImplementation(() => {});

            renderRecord();
            fireEvent.click(screen.getByRole('button', { name: /back/i }));

            expect(back).toHaveBeenCalled();
            back.mockRestore();
        });
    });
});
