import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { LoopProgressCard, ProgressSummary } from '@/patyourself/types';

const page = { url: '/progress', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressIndex from './index';

function card(overrides: Partial<LoopProgressCard> = {}): LoopProgressCard {
    return {
        id: 3,
        title: 'Morning walk',
        type: 'build',
        version: 2,
        day_of_experiment: 22,
        streak: { outcome: 'completed', length: 5 },
        completion_rate: 82,
        totals: { completed: 14, failed: 3, skipped: 1 },
        recent: ['completed', 'completed', 'failed', 'completed'],
        previous_version: { version: 1, rate: 43 },
        last_logged_at: '2026-06-22T07:00:00Z',
        summary_excerpt: 'You complete most mornings.',
        ...overrides,
    };
}

function summary(overrides: Partial<ProgressSummary> = {}): ProgressSummary {
    return {
        held: 14,
        decided: 17,
        skipped: 1,
        versions_running: 1,
        versions_ahead: 1,
        ...overrides,
    };
}

function renderProgress(
    loops: LoopProgressCard[],
    over: Partial<ProgressSummary> = {},
) {
    return render(<ProgressIndex loops={loops} summary={summary(over)} />);
}

describe('ProgressIndex', () => {
    it('renders a card per active loop with its strip and rate', () => {
        renderProgress([card()]);

        expect(screen.getByText('Morning walk')).toBeInTheDocument();
        expect(screen.getByText(/82%/)).toBeInTheDocument();
        expect(screen.getByTestId('outcome-strip')).toBeInTheDocument();
    });

    it('links a card to its detail screen', () => {
        renderProgress([card({ id: 7 })]);

        expect(screen.getByText('Morning walk').closest('a')).toHaveAttribute(
            'href',
            '/loops/7',
        );
    });

    /**
     * The count and its denominator are always shown together. A bare
     * percentage is the one way this screen could mislead — three of four and
     * thirty of forty are not the same evidence, and 75% hides which one it is.
     */
    it('shows the count the rate was worked out from', () => {
        renderProgress([card()]);

        expect(screen.getByText('14')).toBeInTheDocument();
        expect(screen.getByText('/17')).toBeInTheDocument();
    });

    it('names the running version and how far into it the loop is', () => {
        renderProgress([card()]);

        expect(screen.getByText('build · v2 · day 22')).toBeInTheDocument();
    });

    it('drops the version from the meta line when no experiment is running', () => {
        renderProgress([card({ version: null, day_of_experiment: null })]);

        expect(screen.getByText('build')).toBeInTheDocument();
    });

    describe('the comparison against the previous version', () => {
        /**
         * The card's figures are the running version's, so this line compares
         * like with like. The design asserts "this version is ahead"; it is
         * only true when the numbers say so, and the three cases below are all
         * ordinary results.
         */
        it('says a version is ahead when it is', () => {
            renderProgress([
                card({
                    completion_rate: 82,
                    previous_version: { version: 1, rate: 43 },
                }),
            ]);

            expect(
                screen.getByText(/v1 held 43% · this version is ahead/i),
            ).toBeInTheDocument();
        });

        /**
         * A revision that did not help is the finding the experiment was run
         * to get. Hiding it would make the comparison decorative.
         */
        it('says a version is behind when it is', () => {
            renderProgress([
                card({
                    completion_rate: 31,
                    previous_version: { version: 1, rate: 43 },
                }),
            ]);

            expect(
                screen.getByText(/v1 held 43% · this version is behind/i),
            ).toBeInTheDocument();
        });

        it('says level rather than picking a side on a tie', () => {
            renderProgress([
                card({
                    completion_rate: 43,
                    previous_version: { version: 1, rate: 43 },
                }),
            ]);

            expect(
                screen.getByText(/level with it so far/i),
            ).toBeInTheDocument();
        });

        it('offers no comparison for a first version', () => {
            renderProgress([card({ previous_version: null })]);

            expect(
                screen.getByText(/first version · nothing to compare against/i),
            ).toBeInTheDocument();
        });
    });

    /**
     * A skipped occasion never happened, so it decides nothing. It is reported
     * beside the rate rather than folded into it, so a thin sample stays
     * visible instead of hiding behind a percentage.
     */
    it('reports skipped occasions as excluded, not as failures', () => {
        renderProgress([
            card({ totals: { completed: 14, failed: 3, skipped: 4 } }),
        ]);

        expect(screen.getByText(/4 skipped, not counted/i)).toBeInTheDocument();
        // 14/17 — the skipped four are not in the denominator.
        expect(screen.getByText('/17')).toBeInTheDocument();
    });

    it('mentions no skips when there are none', () => {
        renderProgress([
            card({ totals: { completed: 14, failed: 3, skipped: 0 } }),
        ]);

        expect(screen.queryByText(/skipped, not counted/i)).toBeNull();
    });

    /**
     * A loop with nothing decided is separated out rather than shown with a
     * zero. Four days old is not losing to a loop a month in, and a 0% next to
     * an 82% would say it was.
     */
    it('separates a loop with nothing decided from the ones recording', () => {
        renderProgress([
            card(),
            card({
                id: 9,
                title: 'Gym three times a week',
                completion_rate: null,
                totals: { completed: 0, failed: 0, skipped: 0 },
                recent: [],
                streak: { outcome: null, length: 0 },
            }),
        ]);

        expect(screen.getByText('Recording')).toBeInTheDocument();
        expect(screen.getByText('Nothing to show yet')).toBeInTheDocument();
        expect(
            screen.getByText(/no occasions decided yet/i),
        ).toBeInTheDocument();
        expect(screen.queryByText('0%')).toBeNull();
    });

    /**
     * A card with nothing decided is drawn back, and the class is the whole
     * mechanism. It was briefly `p-cardis-quiet` — a concatenation that lost
     * its separating space, matched no rule, and rendered a perfectly
     * ordinary-looking card. Nothing else on screen would have told you.
     */
    it('marks an undecided card quiet with a class that actually parses', () => {
        const { container } = renderProgress([
            card({
                completion_rate: null,
                totals: { completed: 0, failed: 0, skipped: 0 },
                recent: [],
            }),
        ]);

        expect(container.querySelector('.p-card')).toHaveClass('is-quiet');
    });

    it('leaves a recording card unmarked', () => {
        const { container } = renderProgress([card()]);

        expect(container.querySelector('.p-card')).not.toHaveClass('is-quiet');
    });

    it('omits the "recording" heading when nothing has been decided at all', () => {
        renderProgress(
            [
                card({
                    completion_rate: null,
                    totals: { completed: 0, failed: 0, skipped: 0 },
                    recent: [],
                }),
            ],
            { held: 0, decided: 0, skipped: 0, versions_ahead: 0 },
        );

        expect(screen.queryByText('Recording')).toBeNull();
        expect(screen.getByText('Nothing to show yet')).toBeInTheDocument();
    });

    describe('the lede', () => {
        it('states the whole record with its denominator', () => {
            renderProgress([card()]);

            expect(screen.getByText(/14 of 17 held/i)).toBeInTheDocument();
            expect(
                screen.getByText(/17 decided · 1 skipped · 1 version running/i),
            ).toBeInTheDocument();
        });

        /**
         * The design's line asserts every running version is ahead. That was
         * true of its fixture; here it is claimed only when it holds, and a
         * mixed picture is said out loud instead.
         */
        it('claims versions are ahead only when all of them are', () => {
            renderProgress([card()], {
                versions_running: 2,
                versions_ahead: 2,
            });

            expect(
                screen.getByText(/both running versions are ahead/i),
            ).toBeInTheDocument();
        });

        it('states the split when only some versions are ahead', () => {
            renderProgress([card()], {
                versions_running: 3,
                versions_ahead: 1,
            });

            expect(
                screen.getByText(/1 of 3 running versions are ahead/i),
            ).toBeInTheDocument();
        });

        it('claims nothing when no version is ahead', () => {
            renderProgress([card()], {
                versions_running: 2,
                versions_ahead: 0,
            });

            expect(screen.queryByText(/are ahead/i)).toBeNull();
        });

        it('says the record has not started when nothing is decided', () => {
            renderProgress([card({ completion_rate: null, recent: [] })], {
                held: 0,
                decided: 0,
                versions_ahead: 0,
            });

            expect(
                screen.getByText(/nothing has been decided yet/i),
            ).toBeInTheDocument();
        });
    });

    /**
     * The skipped cell reads as "missing" unless you are told it is
     * deliberate, and it is the one mark that decides nothing either way.
     */
    it('spells out what the three marks mean', () => {
        renderProgress([card()]);

        expect(screen.getByText('Held')).toBeInTheDocument();
        expect(screen.getByText('Did not hold')).toBeInTheDocument();
        expect(
            screen.getByText(/skipped · never counted/i),
        ).toBeInTheDocument();
    });

    it('shows the empty state with a link to the loop list when there are no loops', () => {
        renderProgress([]);

        expect(screen.getByText(/no active loops yet/i)).toBeInTheDocument();
        expect(
            screen.getByText(/view your loops/i).closest('a'),
        ).toHaveAttribute('href', '/loops');
    });
});
