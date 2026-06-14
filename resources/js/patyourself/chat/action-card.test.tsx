import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { IntentionData } from '@/patyourself/types';
import { ActionCard } from './action-card';

function makeIntention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 7,
        title: 'Morning run',
        description: 'Get out the door before the day swallows it.',
        type: 'build',
        status: 'active',
        cue: 'Alarm goes off at 6am',
        craving: 'Feel awake and ahead',
        response: 'Lace up and run one block',
        reward: 'Coffee on the porch',
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: {
            intervention_point: 'response',
            approach: 'Just put the shoes on — one block counts',
            rationale: null,
            version: 2,
        },
        ...overrides,
    };
}

describe('ActionCard', () => {
    it('renders the loop title', () => {
        render(<ActionCard intention={makeIntention()} />);

        expect(screen.getByText('Morning run')).toBeInTheDocument();
    });

    it('labels a build loop as "Build"', () => {
        render(<ActionCard intention={makeIntention({ type: 'build' })} />);

        expect(screen.getByText('Build')).toBeInTheDocument();
    });

    it('labels a break loop as "Break"', () => {
        render(<ActionCard intention={makeIntention({ type: 'break' })} />);

        expect(screen.getByText('Break')).toBeInTheDocument();
    });

    it('summarises the habit anatomy with cue, response and reward', () => {
        render(<ActionCard intention={makeIntention()} />);

        expect(screen.getByText('Alarm goes off at 6am')).toBeInTheDocument();
        expect(
            screen.getByText('Lace up and run one block'),
        ).toBeInTheDocument();
        expect(screen.getByText('Coffee on the porch')).toBeInTheDocument();
    });

    it('shows the active strategy approach as the tactic', () => {
        render(<ActionCard intention={makeIntention()} />);

        expect(
            screen.getByText('Just put the shoes on — one block counts'),
        ).toBeInTheDocument();
    });

    it('falls back to the response when there is no strategy', () => {
        render(<ActionCard intention={makeIntention({ strategy: null })} />);

        // Response shows in the anatomy and as the tactic fallback.
        expect(
            screen.getAllByText('Lace up and run one block').length,
        ).toBeGreaterThanOrEqual(1);
    });

    it('links to the loop detail screen', () => {
        render(<ActionCard intention={makeIntention({ id: 42 })} />);

        const link = screen.getByRole('link', { name: /view loop/i });
        expect(link).toHaveAttribute('href', '/intentions/42');
    });

    it('omits quick-log buttons when no onLog handler is given', () => {
        render(<ActionCard intention={makeIntention()} />);

        expect(
            screen.queryByRole('button', { name: /done/i }),
        ).not.toBeInTheDocument();
    });

    it('renders quick-log buttons when an onLog handler is given', () => {
        render(<ActionCard intention={makeIntention()} onLog={vi.fn()} />);

        expect(
            screen.getByRole('button', { name: /done/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /missed/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /skip/i }),
        ).toBeInTheDocument();
    });

    it('fires onLog with the chosen outcome', async () => {
        const onLog = vi.fn();
        render(<ActionCard intention={makeIntention()} onLog={onLog} />);

        await userEvent.click(screen.getByRole('button', { name: /done/i }));
        expect(onLog).toHaveBeenCalledWith('completed');

        await userEvent.click(screen.getByRole('button', { name: /missed/i }));
        expect(onLog).toHaveBeenCalledWith('failed');

        await userEvent.click(screen.getByRole('button', { name: /skip/i }));
        expect(onLog).toHaveBeenCalledWith('skipped');
    });

    // Task 13: schedule chip tests
    it('renders a recurring clock schedule chip', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 5,
                        title: 'Walk',
                        description: null,
                        status: 'pending',
                        scheduled_for: '2026-06-15T11:00:00.000000Z',
                        recurrence: 'daily',
                        schedule_kind: 'clock',
                        anchor: null,
                    },
                })}
            />,
        );

        expect(screen.getByText(/Daily/)).toBeInTheDocument();
    });

    it('renders an anchored schedule chip', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 6,
                        title: 'Push-ups',
                        description: null,
                        status: 'pending',
                        scheduled_for: null,
                        recurrence: null,
                        schedule_kind: 'anchored',
                        anchor: 'after coffee',
                    },
                })}
            />,
        );

        expect(screen.getByText(/after coffee/)).toBeInTheDocument();
    });

    // Task 14: editor test
    it('submitting the editor calls onReschedule', async () => {
        const onReschedule = vi.fn();
        const intention = makeIntention({
            active_action: {
                id: 7,
                title: 'Walk',
                description: null,
                status: 'pending',
                scheduled_for: '2026-06-15T11:00:00.000000Z',
                recurrence: 'daily',
                schedule_kind: 'clock',
                anchor: null,
            },
        });

        render(
            <ActionCard intention={intention} onReschedule={onReschedule} />,
        );

        await userEvent.click(
            screen.getByRole('button', { name: /edit time/i }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: /save time/i }),
        );

        expect(onReschedule).toHaveBeenCalledWith(
            intention,
            expect.objectContaining({ kind: 'clock' }),
        );
    });

    it('shows a "Due now" badge when the active action has fired', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 8,
                        title: 'Walk',
                        description: null,
                        status: 'active',
                        scheduled_for: '2026-06-15T11:00:00.000000Z',
                        recurrence: 'daily',
                        schedule_kind: 'clock',
                        anchor: null,
                    },
                })}
            />,
        );

        expect(screen.getByText('Due now')).toBeInTheDocument();
    });

    it('omits the "Due now" badge when the action is only pending', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 9,
                        title: 'Walk',
                        description: null,
                        status: 'pending',
                        scheduled_for: '2026-06-15T11:00:00.000000Z',
                        recurrence: 'daily',
                        schedule_kind: 'clock',
                        anchor: null,
                    },
                })}
            />,
        );

        expect(screen.queryByText('Due now')).not.toBeInTheDocument();
    });
});
