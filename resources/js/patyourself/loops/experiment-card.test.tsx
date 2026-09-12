import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CurrentVersionData, StrategyData } from '@/patyourself/types';

import { ExperimentCard } from './experiment-card';

function currentVersion(
    overrides: Partial<CurrentVersionData> = {},
): CurrentVersionData {
    return {
        version: 2,
        started_at: '2026-08-18T09:00:00+00:00',
        day_of_experiment: 9,
        planned_days: 14,
        is_under_review: false,
        verdict: null,
        streak: { outcome: null, length: 0 },
        completion_rate: 68,
        totals: { completed: 15, failed: 7, skipped: 0 },
        last_logged_at: null,
        ...overrides,
    };
}

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 7,
        version: 2,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: null,
        superseded_reason: null,
        review_at: null,
        verdict: null,
        verdict_note: null,
        day_of_experiment: 9,
        planned_days: 14,
        is_under_review: false,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('ExperimentCard', () => {
    /**
     * The hypothesis is what the card exists to raise. It used to render only
     * inside the timeline, below the anatomy, which is why the experiment read
     * as buried while its version number sat at the top.
     *
     * Killing mutation: drop the `{runningExperiment.approach}` paragraph. The
     * card would still show v2 and the day count and look plausible, and this
     * assertion is the only thing that catches it.
     */
    it('carries the active version’s hypothesis', () => {
        render(
            <ExperimentCard
                current={currentVersion()}
                runningExperiment={strategy()}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(
            screen.getByText(/lay your shoes by the door/i),
        ).toBeInTheDocument();
    });

    /**
     * The verdict question is only live at the end of an experiment's run.
     * `is_under_review` already says when that is — ConcludeExperimentForm has
     * always received it and used it only to reword its legend.
     *
     * Asserted by ancestry, not by presence: in Task 4 the verdict gains a
     * second mount point inside a `<details>`, and a collapsed `<details>`
     * keeps its content in the DOM. `queryByLabelText` would find it in both
     * states and this test would pass for the wrong reason.
     */
    it('does not hold the verdict while the experiment is still running', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: false })}
                runningExperiment={strategy({ is_under_review: false })}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

    /**
     * Killing mutation: mount the form unconditionally. The test above fails.
     * Mount it never, and this one fails.
     */
    it('holds the verdict once the experiment is under review', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: true })}
                runningExperiment={strategy({ id: 7, is_under_review: true })}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        const option = screen.getByLabelText(/it worked/i);

        expect(
            option.closest('[data-testid="experiment-card"]'),
        ).not.toBeNull();
        expect(option.closest('form')?.getAttribute('action')).toContain(
            '/strategies/7/verdict',
        );
    });

    /**
     * A version already concluded has answered the question, even though a
     * `worked` verdict leaves it active. Asking again would reopen a closed
     * finding.
     */
    it('does not hold the verdict for a version already concluded', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: true })}
                runningExperiment={undefined}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

    /**
     * Between experiments the card still renders — logging continues, and the
     * screen must say so rather than showing an empty frame. ExperimentHeader
     * already words this; the card must not swallow it.
     */
    it('says logging continues when no experiment is running', () => {
        render(
            <ExperimentCard
                current={null}
                runningExperiment={undefined}
                interventionPoint={null}
                previousRate={null}
            />,
        );

        expect(screen.getByText(/logging continues/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/lay your shoes by the door/i),
        ).not.toBeInTheDocument();
    });
});
