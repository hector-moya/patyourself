import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { StreakBadge } from './streak-badge';

describe('StreakBadge', () => {
    it('shows a run while it is running', () => {
        render(<StreakBadge streak={{ outcome: 'completed', length: 9 }} />);

        expect(screen.getByText(/9 in a row/)).toBeInTheDocument();
    });

    /**
     * The whole of the momentum decision. A run shows while it holds and simply
     * stops being rendered when it breaks — no reset, no zero, and above all no
     * instruction to start again. "3 missed — restart" counted a loss back at
     * the user on the day they least wanted it.
     */
    it('renders nothing once the run has broken', () => {
        const { container } = render(
            <StreakBadge streak={{ outcome: 'failed', length: 3 }} />,
        );

        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByText(/restart|missed|streak|lost/i)).toBeNull();
    });

    /**
     * No run yet is not an outstanding item. "No streak yet" implied one was
     * owed; the notebook never nags.
     */
    it('renders nothing when there is no run yet', () => {
        const { container } = render(
            <StreakBadge streak={{ outcome: null, length: 0 }} />,
        );

        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByText(/no streak|yet/i)).toBeNull();
    });

    it('renders nothing for a zero-length completed run', () => {
        const { container } = render(
            <StreakBadge streak={{ outcome: 'completed', length: 0 }} />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
