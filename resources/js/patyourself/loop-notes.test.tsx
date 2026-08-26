import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { NoteData } from '@/patyourself/types';
import { LoopNotes } from './loop-notes';

function note(overrides: Partial<NoteData> = {}): NoteData {
    return {
        id: 1,
        body: 'Worse on the days I skip lunch',
        noted_at: '2026-08-24T10:00:00+00:00',
        ...overrides,
    };
}

describe('LoopNotes', () => {
    it('renders each note with the day it was made', () => {
        render(
            <LoopNotes
                notes={[
                    note({ id: 1, body: 'Newer observation' }),
                    note({
                        id: 2,
                        body: 'Older observation',
                        noted_at: '2026-08-20T10:00:00+00:00',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Newer observation')).toBeInTheDocument();
        expect(screen.getByText('Older observation')).toBeInTheDocument();
        expect(screen.getByText(/24 Aug/i)).toBeInTheDocument();
    });

    it('renders the body exactly as it was written', () => {
        const body = "  noticed it's WORSE when I skip lunch.  ";

        render(<LoopNotes notes={[note({ body })]} />);

        expect(
            screen.getByText((_, element) => element?.textContent === body),
        ).toBeInTheDocument();
    });

    it('states the empty case plainly', () => {
        render(<LoopNotes notes={[]} />);

        expect(screen.getByText(/no notes yet/i)).toBeInTheDocument();
    });
});
