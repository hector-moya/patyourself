import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = { url: '/companion', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import type {
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';

import CompanionPage from './companion';

function unlock(
    overrides: Partial<CompanionUnlockData> = {},
): CompanionUnlockData {
    return {
        kind: 'body',
        name: 'blob',
        variant: null,
        message: 'Blob is here.',
        unlocked_at: '2026-08-20T09:00:00+00:00',
        ...overrides,
    };
}

function companion(overrides: Partial<CompanionData> = {}): CompanionData {
    return {
        log_count: 0,
        insight_count: 0,
        stage_index: 0,
        features: [],
        items: [],
        abilities: [],
        unlocks: [],
        latest_unlock: null,
        ...overrides,
    };
}

describe('Companion screen', () => {
    /**
     * Nothing yet is stated as a fact about the record, with nothing owed and
     * nothing to act on.
     */
    it('says what brings Blob out, without asking for it', () => {
        render(<CompanionPage companion={companion()} />);

        expect(screen.getByText(/blob turns up once/i)).toBeInTheDocument();
        expect(screen.queryByText(/locked|to unlock|remaining/i)).toBeNull();
    });

    it('lists what has happened, newest first, with the date each arrived', () => {
        render(
            <CompanionPage
                companion={companion({
                    stage_index: 3,
                    log_count: 5,
                    features: ['blob', 'legs'],
                    items: [{ type: 'shoes', variant: null }],
                    unlocks: [
                        unlock(),
                        unlock({
                            name: 'legs',
                            message: 'Blob has legs now.',
                            unlocked_at: '2026-08-22T09:00:00+00:00',
                        }),
                        unlock({
                            kind: 'item',
                            name: 'shoes',
                            message: 'Blob has shoes now.',
                            unlocked_at: '2026-08-25T09:00:00+00:00',
                        }),
                    ],
                    latest_unlock: unlock({ kind: 'item', name: 'shoes' }),
                })}
            />,
        );

        const rows = screen.getAllByRole('listitem');

        expect(rows[0]).toHaveTextContent('shoes');
        expect(rows[0]).toHaveTextContent('25 Aug 2026');
        expect(rows[2]).toHaveTextContent('blob');
        expect(screen.getByText('Blob has shoes now.')).toBeInTheDocument();
    });

    /**
     * The acceptance criterion for this screen. History, not a trophy case: no
     * locked slot, no remaining count, no preview of what comes next, and
     * nothing anywhere that reads as a score.
     */
    it('never shows what has not happened', () => {
        render(
            <CompanionPage
                companion={companion({
                    stage_index: 1,
                    log_count: 1,
                    features: ['blob'],
                    unlocks: [unlock()],
                    latest_unlock: unlock(),
                })}
            />,
        );

        expect(
            screen.queryByText(
                /locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i,
            ),
        ).toBeNull();
    });

    it('names a recoloured item by its variant', () => {
        render(
            <CompanionPage
                companion={companion({
                    stage_index: 1,
                    features: ['blob'],
                    items: [{ type: 'scarf', variant: 'coral' }],
                    unlocks: [
                        unlock({
                            kind: 'item',
                            name: 'scarf',
                            variant: 'coral',
                            message: 'Blob has another scarf, in coral.',
                        }),
                    ],
                    latest_unlock: unlock({
                        kind: 'item',
                        name: 'scarf',
                        variant: 'coral',
                    }),
                })}
            />,
        );

        expect(screen.getByText('scarf (coral)')).toBeInTheDocument();
    });
});
