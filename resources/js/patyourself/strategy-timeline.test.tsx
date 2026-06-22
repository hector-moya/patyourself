import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StrategyData } from '@/patyourself/types';
import { StrategyTimeline } from './strategy-timeline';

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 1,
        version: 1,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: 'initial',
        superseded_reason: null,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('StrategyTimeline', () => {
    it('renders a node per version with its change-reason copy', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({
                        id: 1,
                        version: 1,
                        status: 'superseded',
                        superseded_reason: 'kept missing it',
                    }),
                    strategy({
                        id: 2,
                        version: 2,
                        status: 'active',
                        change_reason: 'restrategized_on_failure',
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/v1/)).toBeInTheDocument();
        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(
            screen.getByText('Restrategized after a setback'),
        ).toBeInTheDocument();
        expect(screen.getByText('“kept missing it”')).toBeInTheDocument();
    });

    it('shows an empty state when there are no strategies', () => {
        render(<StrategyTimeline strategies={[]} />);

        expect(screen.getByText(/no strategy yet/i)).toBeInTheDocument();
    });
});
