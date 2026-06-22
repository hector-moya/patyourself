import * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { LoopProgressDetail, StrategyData } from '@/patyourself/types';

const page = { url: '/progress/3', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressShow from './show';

function detail(
    overrides: Partial<LoopProgressDetail> = {},
): LoopProgressDetail {
    return {
        id: 3,
        title: 'Morning walk',
        type: 'build',
        streak: { outcome: 'completed', length: 5 },
        completion_rate: 82,
        totals: { completed: 12, failed: 2, skipped: 1 },
        recent: ['completed', 'failed', 'completed'],
        last_logged_at: '2026-06-22T07:00:00Z',
        ...overrides,
    };
}

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

describe('ProgressShow', () => {
    it('renders the metric header, totals, and sparkline', () => {
        render(
            <ProgressShow
                intention={detail()}
                strategies={[strategy()]}
                summary={null}
            />,
        );

        expect(screen.getByText(/5 in a row/)).toBeInTheDocument();
        expect(screen.getByText(/82% complete/)).toBeInTheDocument();
        expect(
            screen.getByText(/12 done · 2 missed · 1 skipped/),
        ).toBeInTheDocument();
        expect(screen.getByTestId('outcome-strip')).toBeInTheDocument();
    });

    it('renders the reused strategy journey with its versions', () => {
        render(
            <ProgressShow
                intention={detail()}
                strategies={[
                    strategy({ id: 1, version: 1, status: 'superseded' }),
                    strategy({
                        id: 2,
                        version: 2,
                        status: 'active',
                        change_reason: 'stacked_on_success',
                    }),
                ]}
                summary="You complete most mornings."
            />,
        );

        expect(screen.getByText(/v1/)).toBeInTheDocument();
        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(screen.getByText('Stacked on success')).toBeInTheDocument();
        expect(
            screen.getByText('You complete most mornings.'),
        ).toBeInTheDocument();
    });

    it('shows the empty narrative line when there is no summary', () => {
        render(
            <ProgressShow
                intention={detail()}
                strategies={[strategy()]}
                summary={null}
            />,
        );

        expect(
            screen.getByText(/hasn't summarized this loop yet/i),
        ).toBeInTheDocument();
    });
});
