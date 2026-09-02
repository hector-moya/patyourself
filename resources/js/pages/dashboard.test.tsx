import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = { url: '/dashboard', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import { companion, noCompanion } from '@/patyourself/companion.fixture';

import Dashboard from './dashboard';
import type {ReadyForVerdictData, TodaysOccasionData} from './dashboard';

function occasion(
    overrides: Partial<TodaysOccasionData> = {},
): TodaysOccasionData {
    return {
        occurrence_id: 42,
        action_id: 7,
        loop_id: 3,
        loop_title: 'Evening snacking',
        title: 'Lunch without bread',
        description: null,
        due: 'due_now',
        scheduled_for: '2026-08-27T12:30:00+00:00',
        ...overrides,
    };
}

function verdict(
    overrides: Partial<ReadyForVerdictData> = {},
): ReadyForVerdictData {
    return {
        loop_id: 9,
        loop_title: 'Morning walk',
        version: 1,
        intervention_point: 'cue',
        day_of_experiment: 15,
        planned_days: 14,
        ...overrides,
    };
}

function renderDashboard(props: Partial<React.ComponentProps<typeof Dashboard>> = {}) {
    return render(
        <Dashboard
            today="2026-08-27"
            occasions={[]}
            ready_for_verdict={[]}
            companion={noCompanion()}
            {...props}
        />,
    );
}

describe('Dashboard', () => {
    it('names the day', () => {
        renderDashboard();

        expect(screen.getByText(/thursday 27 august/i)).toBeInTheDocument();
    });

    it('separates what is due now from what is later today', () => {
        renderDashboard({
            occasions: [
                occasion({ due: 'due_now', title: 'Lunch without bread' }),
                occasion({
                    due: 'upcoming',
                    action_id: 8,
                    occurrence_id: 43,
                    title: 'Reading',
                }),
            ],
        });

        expect(screen.getByText(/due now/i)).toBeInTheDocument();
        expect(screen.getByText(/later today/i)).toBeInTheDocument();
    });

    /**
     * An empty section is omitted, not rendered with a zero. A count anywhere on
     * this screen would score one day against another.
     */
    it('omits a section with nothing in it', () => {
        renderDashboard({ occasions: [occasion({ due: 'due_now' })] });

        expect(screen.getByText(/due now/i)).toBeInTheDocument();
        expect(screen.queryByText(/later today/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/when the cue comes/i)).not.toBeInTheDocument();
    });

    /**
     * The failure this pins is silent in production: an anchored occasion has no
     * occurrence id, and posting every row to the action route would log the
     * live slot rather than the occasion on screen — with no error either way.
     */
    it('posts an occasion with a slot to the occurrence route', () => {
        renderDashboard({ occasions: [occasion({ occurrence_id: 42 })] });

        expect(screen.getByTestId('occasion-form-7')).toHaveAttribute(
            'action',
            '/occurrences/42/logs',
        );
    });

    it('posts an anchored occasion with no slot to the action route', () => {
        renderDashboard({
            occasions: [occasion({ occurrence_id: null, due: 'anchored' })],
        });

        expect(screen.getByTestId('occasion-form-7')).toHaveAttribute(
            'action',
            '/actions/7/logs',
        );
    });

    it('marks an anchored occasion as having no clock time', () => {
        renderDashboard({
            occasions: [
                occasion({
                    due: 'anchored',
                    occurrence_id: null,
                    scheduled_for: null,
                }),
            ],
        });

        expect(screen.getByText(/anchored/i)).toBeInTheDocument();
    });

    /**
     * A failed outcome carries the user's own words — the same rule the tool
     * boundary enforces. The field appears only for that outcome.
     */
    it('asks for a reason only when the strategy did not hold', () => {
        renderDashboard({ occasions: [occasion()] });

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByText(/did not hold/i));

        expect(
            screen.getByLabelText(/what happened, in your words/i),
        ).toBeInTheDocument();
    });

    it('asks for no reason when the occasion never happened', () => {
        renderDashboard({ occasions: [occasion()] });

        fireEvent.click(screen.getByText(/never happened/i));

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();
    });

    /**
     * A version past its review date is not late. The section states that a
     * decision is available and carries no count and no alarm language.
     */
    it('offers a verdict without counting or alarming', () => {
        renderDashboard({ ready_for_verdict: [verdict()] });

        expect(screen.getByText(/ready for a verdict/i)).toBeInTheDocument();
        expect(screen.getByText(/morning walk/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/overdue|late|behind|\(1\)/i),
        ).not.toBeInTheDocument();
    });

    /**
     * The dashboard used to state the review with no way to act on it. Each
     * row now leads to the loop's own record, where the verdict form lives.
     */
    it('links a review-due experiment to the record where it can be answered', () => {
        renderDashboard({ ready_for_verdict: [verdict({ loop_id: 1 })] });

        const link = screen.getByRole('link', { name: /give it a verdict/i });

        expect(link.getAttribute('href')).toContain('/loops/1');
    });

    it('omits the verdict section when nothing is ready', () => {
        renderDashboard({ occasions: [occasion()] });

        expect(
            screen.queryByText(/ready for a verdict/i),
        ).not.toBeInTheDocument();
    });

    /**
     * Blob rides in the corner and links to its own screen. It carries no
     * count and no badge: the header is not where progress gets tallied.
     */
    it('puts Blob in the corner once it exists', () => {
        renderDashboard({
            companion: noCompanion({
                stage_index: 1,
                log_count: 1,
                features: ['blob'],
            }),
        });

        const link = screen.getByRole('link', { name: 'Blob' });

        expect(link).toHaveAttribute('href', '/companion');
    });

    it('shows no corner at all before Blob exists', () => {
        renderDashboard();

        expect(screen.queryByRole('link', { name: 'Blob' })).toBeNull();
    });

    /**
     * A day with nothing scheduled is a normal day. The empty state says so and
     * offers nothing to catch up on.
     */
    it('states an empty day as a fact, not a backlog', () => {
        renderDashboard();

        expect(screen.getByText(/nothing due today/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/behind|missed|overdue|catch up/i),
        ).not.toBeInTheDocument();
    });

    /**
     * The corner is the only place the reaction lands. Nothing else on this
     * screen changes when an outcome is recorded — no copy, no toast, no line.
     */
    it('hands the just-recorded outcome to the corner Blob', () => {
        const { container } = renderDashboard({
            companion: companion(),
            logged_outcome_id: 101,
        });

        expect(
            container.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    it('leaves Blob at rest on a plain visit', () => {
        const { container } = renderDashboard({
            companion: companion(),
            logged_outcome_id: null,
        });

        expect(
            container.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('idle');
    });
});
