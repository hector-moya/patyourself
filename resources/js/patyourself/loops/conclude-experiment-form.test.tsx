import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ConcludeExperimentForm } from './conclude-experiment-form';

describe('ConcludeExperimentForm', () => {
    it('offers all three verdicts', () => {
        render(<ConcludeExperimentForm strategyId={1} isUnderReview />);

        expect(screen.getByLabelText(/worked/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/did not hold/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/inconclusive/i)).toBeInTheDocument();
    });

    it('asks for a note only once the verdict is a failure', async () => {
        render(<ConcludeExperimentForm strategyId={1} isUnderReview />);

        expect(
            screen.queryByLabelText(/what the strategy did not do/i),
        ).not.toBeInTheDocument();

        await userEvent.click(screen.getByLabelText(/did not hold/i));

        expect(
            screen.getByLabelText(/what the strategy did not do/i),
        ).toBeInTheDocument();
    });

    it('posts to the verdict route for this strategy', () => {
        const { container } = render(
            <ConcludeExperimentForm strategyId={42} isUnderReview />,
        );

        expect(
            container.querySelector('form')?.getAttribute('action'),
        ).toContain('/strategies/42/verdict');
    });
});
