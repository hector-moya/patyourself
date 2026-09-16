import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CompanionGlyph } from './companion-glyph';

describe('CompanionGlyph', () => {
    it('draws a different mark for each kind of arrival', () => {
        const cells = (kind: string) =>
            [
                ...render(
                    <CompanionGlyph kind={kind} />,
                ).container.querySelectorAll('rect'),
            ]
                .map(
                    (rect) =>
                        `${rect.getAttribute('x')},${rect.getAttribute('y')}`,
                )
                .join(' ');

        const body = cells('body');
        const item = cells('item');
        const ability = cells('ability');

        expect(body).not.toBe(item);
        expect(item).not.toBe(ability);
        expect(ability).not.toBe(body);
    });

    /** Colour carries the same distinction as the shape, for anyone who cannot
        resolve an 8×8 silhouette at 18px. */
    it('colours each kind differently', () => {
        const fill = (kind: string) =>
            render(<CompanionGlyph kind={kind} />)
                .container.querySelector('rect')
                ?.getAttribute('fill');

        expect(
            new Set([fill('body'), fill('item'), fill('ability')]).size,
        ).toBe(3);
    });

    /**
     * The record is history, and a line of it must never lose its mark because
     * the ladder learned a fourth word — the same contract scenes, item types
     * and room objects already follow.
     */
    it('falls back to the body mark for a kind it does not know', () => {
        const unknown = render(<CompanionGlyph kind="constellation" />);
        const body = render(<CompanionGlyph kind="body" />);

        expect(unknown.container.querySelector('svg')?.dataset.glyph).toBe(
            'body',
        );
        expect(unknown.container.innerHTML).toBe(body.container.innerHTML);
    });

    /**
     * Inherited properties are not kinds. A plain object's lookup walks the
     * prototype chain, so without `Object.hasOwn` this name resolves to a
     * function, and the fall happens downstream on `.rows` instead of here.
     */
    it('treats an inherited property name as unknown, not as a glyph', () => {
        const { container } = render(<CompanionGlyph kind="constructor" />);

        expect(container.querySelector('svg')?.dataset.glyph).toBe('body');
        expect(container.querySelectorAll('rect').length).toBeGreaterThan(0);
    });

    it('is hidden from screen readers — the line beside it already says it', () => {
        const { container } = render(<CompanionGlyph kind="item" />);

        expect(container.querySelector('svg')).toHaveAttribute(
            'aria-hidden',
            'true',
        );
    });
});
