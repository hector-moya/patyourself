import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CurrentVersionData } from '@/patyourself/types';
import { ExperimentHeader } from './experiment-header';

function current(
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
        totals: { completed: 15, failed: 7, skipped: 3 },
        last_logged_at: '2026-08-27T09:00:00+00:00',
        ...overrides,
    };
}

describe('ExperimentHeader', () => {
    it('names the version and where it intervenes', () => {
        render(
            <ExperimentHeader
                current={current()}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(screen.getByText(/craving/i)).toBeInTheDocument();
    });

    it('renders a planned run as day N of M', () => {
        render(
            <ExperimentHeader
                current={current({ day_of_experiment: 9, planned_days: 14 })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.getByTestId('experiment-state')).toHaveTextContent(
            /day 9 of 14/i,
        );
    });

    /**
     * `planned_days: null` means open-ended. It is a legitimate way to run an
     * experiment and must never render as a countdown or a zero-day run.
     */
    it('renders an open-ended run without a countdown', () => {
        render(
            <ExperimentHeader
                current={current({ day_of_experiment: 9, planned_days: null })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        // Scoped to the state line: the momentum line legitimately reads
        // "15 of 22 held", so a page-wide /of \d/ would match the wrong thing.
        const state = screen.getByTestId('experiment-state');

        expect(state).toHaveTextContent(/open-ended/i);
        expect(state).not.toHaveTextContent(/of \d/);
        expect(state).not.toHaveTextContent(/\bday 0\b/i);
    });

    /**
     * Past its review date the day count stops being the useful fact. A version
     * on day 15 of a 14-day run should ask for a verdict, not report an overrun.
     */
    it('asks for a verdict once the version is under review', () => {
        render(
            <ExperimentHeader
                current={current({
                    day_of_experiment: 15,
                    planned_days: 14,
                    is_under_review: true,
                })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.getByText(/ready for a verdict/i)).toBeInTheDocument();
        expect(screen.queryByText(/day 15 of 14/i)).not.toBeInTheDocument();
    });

    /**
     * A loop between experiments is a success, not neglect. The notebook never
     * nags, so this state carries no prompt and no warning language.
     */
    it('states the no-experiment case plainly and does not prompt', () => {
        render(
            <ExperimentHeader
                current={null}
                interventionPoint={null}
                previousRate={null}
            />,
        );

        expect(screen.getByText(/logging continues/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/start an experiment|no experiments yet|overdue/i),
        ).not.toBeInTheDocument();
    });

    it('omits the delta entirely when there is no previous version', () => {
        render(
            <ExperimentHeader
                current={current({ completion_rate: 68 })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.queryByText(/up from|down from/i)).not.toBeInTheDocument();
        expect(screen.queryByText('—')).not.toBeInTheDocument();
    });

    it('compares this version against the one before it', () => {
        render(
            <ExperimentHeader
                current={current({ completion_rate: 68 })}
                interventionPoint="craving"
                previousRate={41}
            />,
        );

        expect(screen.getByText(/up from 41%/i)).toBeInTheDocument();
    });

    /**
     * A falling delta is information about the strategy, not a warning about the
     * person. It renders in exactly the same styling as a rising one — asserted
     * by comparing the rendered classes, because prose alone would not catch a
     * stray `text-red-600`.
     */
    it('renders a falling delta in the same styling as a rising one', () => {
        const { unmount } = render(
            <ExperimentHeader
                current={current({ completion_rate: 68 })}
                interventionPoint="craving"
                previousRate={41}
            />,
        );
        const rising = screen.getByTestId('experiment-delta').className;
        unmount();

        render(
            <ExperimentHeader
                current={current({ completion_rate: 41 })}
                interventionPoint="craving"
                previousRate={68}
            />,
        );
        const falling = screen.getByTestId('experiment-delta');

        expect(falling).toHaveTextContent(/down from 68%/i);
        expect(falling.className).toBe(rising);
        expect(
            screen.queryByText(/warning|slipping|failing|behind|worse/i),
        ).not.toBeInTheDocument();
    });

    it('reports the counts that produced the rate', () => {
        render(
            <ExperimentHeader
                current={current({
                    totals: { completed: 15, failed: 7, skipped: 3 },
                })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        // 15 of 22 decided — skipped is never in the denominator.
        expect(screen.getByText(/15 of 22 held/i)).toBeInTheDocument();
    });

    it('shows a streak while it is running', () => {
        render(
            <ExperimentHeader
                current={current({
                    streak: { outcome: 'completed', length: 9 },
                })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.getByText(/9 in a row/i)).toBeInTheDocument();
    });

    /**
     * The whole of the gamification decision. A streak shows while it runs and
     * simply stops being rendered when it breaks — no reset, no zero, no
     * "streak lost". The app never counts a loss back at its owner.
     */
    it('shows nothing at all once a streak has broken', () => {
        render(
            <ExperimentHeader
                current={current({ streak: { outcome: 'failed', length: 3 } })}
                interventionPoint="craving"
                previousRate={null}
            />,
        );

        expect(screen.queryByText(/in a row/i)).not.toBeInTheDocument();
        expect(
            screen.queryByText(/streak|reset|lost|broken/i),
        ).not.toBeInTheDocument();
    });
});
