import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { IntentionData } from '@/patyourself/types';

const page = { url: '/intentions/1', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import LoopShow from './show';

function intention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Read before bed',
        type: 'build',
        status: 'active',
        cue: 'Phone on the charger',
        craving: 'Wind down',
        response: 'Read ten pages',
        reward: 'Calmer sleep',
        description: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: null,
        ...overrides,
    };
}

describe('LoopShow', () => {
    it('offers to activate a paused loop', () => {
        render(<LoopShow intention={intention({ status: 'paused' })} strategies={[]} />);

        expect(screen.getByRole('button', { name: /activate/i })).toBeInTheDocument();
    });

    it('does not offer activation for an active loop', () => {
        render(<LoopShow intention={intention({ status: 'active' })} strategies={[]} />);

        expect(screen.queryByRole('button', { name: /activate/i })).not.toBeInTheDocument();
    });

    it('uses the design-system Button for the activate action', () => {
        render(<LoopShow intention={intention({ status: 'paused' })} strategies={[]} />);

        const button = screen.getByRole('button', { name: /activate/i });
        expect(button).toHaveClass('py-btn', 'py-btn--primary');
    });

    it('credits Claude only for a loop it authored', () => {
        render(
            <LoopShow
                intention={intention({
                    status: 'paused',
                    metadata: { authored_by: 'mcp-client' },
                })}
                strategies={[]}
            />,
        );

        expect(screen.getByText(/claude drafted this loop/i)).toBeInTheDocument();
    });

    it('does not credit Claude for a paused loop it did not author', () => {
        render(
            <LoopShow
                intention={intention({
                    status: 'paused',
                    metadata: { authored_by: 'user' },
                })}
                strategies={[]}
            />,
        );

        expect(screen.queryByText(/claude drafted this loop/i)).not.toBeInTheDocument();
        expect(
            screen.getByText(/activating it starts its schedule and notifications/i),
        ).toBeInTheDocument();
    });
});
