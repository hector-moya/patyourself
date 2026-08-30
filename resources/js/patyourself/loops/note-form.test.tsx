import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { NoteForm } from './note-form';

describe('NoteForm', () => {
    it('posts to the loop it belongs to', () => {
        const { container } = render(<NoteForm loopId={7} />);

        expect(
            container.querySelector('form')?.getAttribute('action'),
        ).toContain('/loops/7/notes');
    });

    it('does not chase the user for a note', () => {
        render(<NoteForm loopId={7} />);

        expect(
            screen.getByPlaceholderText(/something you noticed/i),
        ).toBeInTheDocument();
    });
});
