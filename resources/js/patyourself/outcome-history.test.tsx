import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { OutcomeEntryData } from '@/patyourself/types';
import { OutcomeHistory } from './outcome-history';

function entry(overrides: Partial<OutcomeEntryData> = {}): OutcomeEntryData {
    return {
        id: 1,
        occurred_at: '2026-08-24T19:00:00+00:00',
        logged_at: '2026-08-26T21:00:00+00:00',
        action_id: 1,
        action_title: 'Put the pan back on the stove',
        outcome: 'completed',
        reason: null,
        context: null,
        context_fields: null,
        strategy_version: 1,
        ...overrides,
    };
}

describe('OutcomeHistory', () => {
    it('renders a failure reason exactly as the user wrote it', () => {
        const reason = "didn't Think about it AT ALL.";

        render(
            <OutcomeHistory
                outcomes={[entry({ outcome: 'failed', reason })]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        // Verbatim: this is the raw material the next experiment is written
        // from, and tidying it here is the same mistake as tidying it on the
        // way in.
        expect(screen.getByText(`“${reason}”`)).toBeInTheDocument();
    });

    it('describes a failure as the strategy not holding, never as the user failing', () => {
        render(
            <OutcomeHistory
                outcomes={[entry({ outcome: 'failed', reason: 'ran late' })]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText(/did not hold/i)).toBeInTheDocument();
        expect(screen.queryByText(/you failed/i)).not.toBeInTheDocument();
    });

    it('renders a skip as its own thing rather than a softer failure', () => {
        render(
            <OutcomeHistory
                outcomes={[entry({ outcome: 'skipped' })]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText(/did not happen/i)).toBeInTheDocument();
        expect(screen.queryByText(/did not hold/i)).not.toBeInTheDocument();
    });

    it('dates an entry by the occasion, not by when it was typed', () => {
        render(
            <OutcomeHistory
                outcomes={[entry()]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText(/24 Aug/i)).toBeInTheDocument();
        expect(screen.queryByText(/26 Aug/i)).not.toBeInTheDocument();
    });

    it('names the version that was running at the time', () => {
        render(
            <OutcomeHistory
                outcomes={[entry({ strategy_version: 3 })]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText('v3')).toBeInTheDocument();
    });

    it('renders the context and its structured fields', () => {
        render(
            <OutcomeHistory
                outcomes={[
                    entry({
                        context: 'Standing at the bench',
                        context_fields: {
                            place: 'kitchen',
                            with_others: false,
                            preceded_by: 'skipped lunch',
                        },
                    }),
                ]}
                total={1}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText('Standing at the bench')).toBeInTheDocument();
        expect(
            screen.getByText(/kitchen · alone · after skipped lunch/i),
        ).toBeInTheDocument();
    });

    it('offers the full history only when there is more to show', () => {
        const { rerender } = render(
            <OutcomeHistory
                outcomes={[entry()]}
                total={40}
                showingAll={false}
                loopId={7}
            />,
        );

        expect(
            screen.getByRole('link', { name: /show the full history/i }),
        ).toHaveAttribute('href', '/loops/7?history=all');

        rerender(
            <OutcomeHistory
                outcomes={[entry()]}
                total={1}
                showingAll={false}
                loopId={7}
            />,
        );

        expect(
            screen.queryByRole('link', { name: /show the full history/i }),
        ).not.toBeInTheDocument();
    });

    it('states the empty case plainly, without exhorting', () => {
        render(
            <OutcomeHistory
                outcomes={[]}
                total={0}
                showingAll={false}
                loopId={1}
            />,
        );

        expect(screen.getByText(/nothing logged yet/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/start now|get going|keep it up/i),
        ).toBeNull();
    });
});
