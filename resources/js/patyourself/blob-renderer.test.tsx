import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnimationName } from '@/patyourself/companion-animations';
import {
    BlobRenderer,
    SpriteBlobRenderer,
    SvgBlobRenderer,
} from './blob-renderer';
import type { BlobRendererProps } from './blob-renderer';

function draw(overrides: Partial<BlobRendererProps> = {}) {
    const props: BlobRendererProps = {
        animation: 'idle',
        frame: 0,
        features: ['blob'],
        items: [],
        abilities: [],
        ...overrides,
    };

    return render(
        <svg>
            <BlobRenderer {...props} />
        </svg>,
    ).container;
}

function renderBody(animation: AnimationName, frame: number) {
    const container = draw({ animation, frame });

    return container.querySelector('.blob-anim') as Element;
}

describe('SvgBlobRenderer', () => {
    it('draws a body and two eyes', () => {
        const container = draw();

        expect(container.querySelector('.blob-body')).not.toBeNull();
        expect(container.querySelectorAll('.blob-body circle')).toHaveLength(2);
        expect(container.querySelector('.blob-legs')).toBeNull();
    });

    it('adds legs only once they have been earned', () => {
        const container = draw({ features: ['blob', 'legs'] });

        expect(container.querySelectorAll('.blob-legs rect')).toHaveLength(2);
    });

    /**
     * The layering rule: an accessory is positioned by its anchor and never by
     * the body's own geometry, so adding one cannot move Blob.
     */
    it('hangs each item off its anchor', () => {
        const container = draw({
            features: ['blob', 'legs'],
            items: [
                { type: 'shoes', variant: null },
                { type: 'hat', variant: null },
            ],
        });

        const layers = Array.from(
            container.querySelectorAll('.blob-layer'),
        ).map((layer) => layer.getAttribute('transform'));

        expect(layers).toEqual(['translate(0 40)', 'translate(0 0)']);
    });

    it('recolours an item when the unlock names a variant', () => {
        const container = draw({
            items: [{ type: 'scarf', variant: 'coral' }],
        });

        expect(
            container.querySelector('.blob-layer rect')?.getAttribute('fill'),
        ).toBe('#E8836B');
    });

    it('fades in only the item earned most recently', () => {
        const container = draw({
            items: [
                { type: 'shoes', variant: null },
                { type: 'scarf', variant: null },
            ],
            arriving: { type: 'scarf', variant: null },
        });

        const arriving = container.querySelectorAll('.blob-layer--arriving');

        expect(arriving).toHaveLength(1);
        expect(arriving[0].getAttribute('transform')).toBe('translate(0 31)');
    });

    /**
     * The ladder can name an item before anyone draws it — that is what keeps
     * adding a stage a config edit. An undrawn one is skipped, not left as a
     * hole.
     */
    it('skips an item type it has not drawn yet', () => {
        const container = draw({
            items: [{ type: 'umbrella', variant: null }],
        });

        expect(container.querySelectorAll('.blob-layer')).toHaveLength(0);
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });

    /**
     * Everything Blob wears sits inside the animated group, so an accessory
     * follows the body rather than sliding off it.
     */
    it('animates one group, with the accessories inside it', () => {
        const container = draw({
            features: ['blob', 'legs'],
            items: [{ type: 'shoes', variant: null }],
        });

        const animated = container.querySelectorAll('.blob-anim');

        expect(animated).toHaveLength(1);
        expect(animated[0].querySelector('.blob-body')).not.toBeNull();
        expect(animated[0].querySelector('.blob-layer')).not.toBeNull();
    });

    describe('poses', () => {
        it('squashes toward the feet on the second idle frame', () => {
            const rest = draw({ animation: 'idle', frame: 0 });
            const squashed = draw({ animation: 'idle', frame: 1 });

            expect(
                rest.querySelector('.blob-anim')?.getAttribute('style'),
            ).toContain('scaleY(1)');
            expect(
                squashed.querySelector('.blob-anim')?.getAttribute('style'),
            ).toContain('scaleY(0.975)');
        });

        it('shuts the eyes on the closed frame of a blink', () => {
            const shut = draw({ animation: 'blink', frame: 0 });
            const open = draw({ animation: 'blink', frame: 1 });

            expect(
                shut.querySelector('[data-testid="blob-eyes-closed"]'),
            ).not.toBeNull();
            expect(
                open.querySelector('[data-testid="blob-eyes-closed"]'),
            ).toBeNull();
        });

        /** Mirrored from one table, so the legs cannot drift apart. */
        it('swings the legs in opposite directions while walking', () => {
            const container = draw({
                animation: 'walk',
                frame: 0,
                features: ['blob', 'legs'],
            });

            expect(
                container
                    .querySelector('.blob-legs__left')
                    ?.getAttribute('style'),
            ).toContain('rotate(7deg)');
            expect(
                container
                    .querySelector('.blob-legs__right')
                    ?.getAttribute('style'),
            ).toContain('rotate(-7deg)');
        });

        it('leaves the legs alone when Blob is not walking', () => {
            const container = draw({
                animation: 'idle',
                frame: 0,
                features: ['blob', 'legs'],
            });

            expect(
                container
                    .querySelector('.blob-legs__left')
                    ?.getAttribute('style'),
            ).toBeNull();
        });

        it('leans and shuts its eyes for a pet', () => {
            const container = draw({ animation: 'pet', frame: 2 });

            expect(
                container.querySelector('.blob-anim')?.getAttribute('style'),
            ).toContain('rotate(6deg)');
            expect(
                container.querySelector('[data-testid="blob-eyes-closed"]'),
            ).not.toBeNull();
        });

        it('hops for play', () => {
            const container = draw({ animation: 'play', frame: 3 });

            expect(
                container.querySelector('.blob-anim')?.getAttribute('style'),
            ).toContain('translateY(-8px)');
        });

        /**
         * A blink is the eyes and nothing else. A body that moved too would
         * read as a flinch.
         */
        it('holds the body still through a blink', () => {
            const container = draw({ animation: 'blink', frame: 0 });

            expect(
                container.querySelector('.blob-anim')?.getAttribute('style'),
            ).toContain('scaleY(1)');
        });

        it('moves the body through a wave', () => {
            const styles = [0, 1, 2, 3].map(
                (frame) => renderBody('wave', frame).getAttribute('style') ?? '',
            );

            // Not every frame identical: a pose that never moves is the bug
            // this task exists to fix.
            expect(new Set(styles).size).toBeGreaterThan(1);

            // Pin the shape of the motion, not just that it moves: a wave
            // rocks the body via rotation, one way then further the other,
            // not a slide.
            expect(styles[1]).toContain('rotate(-7deg)');
            expect(styles[2]).toContain('rotate(9deg)');
        });

        it('moves the body through a jump', () => {
            const styles = [0, 1, 2, 3].map(
                (frame) => renderBody('jump', frame).getAttribute('style') ?? '',
            );

            expect(new Set(styles).size).toBeGreaterThan(1);

            // Pin the crouch-and-leap shape: a squash on the way down, then
            // height at the peak — not a plain translate.
            expect(styles[1]).toContain('scaleY(0.94)');
            expect(styles[2]).toContain('translateY(-7px)');
        });

        it('lands a jump where it started', () => {
            // The ladder's own words: "Both feet leave the ground, briefly,
            // and it lands where it started."
            expect(renderBody('jump', 0).getAttribute('style')).toBe(
                renderBody('jump', 3).getAttribute('style'),
            );
        });

        it('leaves the body still for a blink', () => {
            // The existing contract, which must survive: blink changes the
            // eyes and nothing else.
            expect(renderBody('blink', 0).getAttribute('style')).toBe(
                renderBody('blink', 1).getAttribute('style'),
            );
        });
    });
});

describe('BlobRenderer', () => {
    it('draws with the SVG renderer by default', () => {
        expect(draw().querySelector('.blob-body')).not.toBeNull();
    });

    /**
     * The seam is real rather than hypothetical: the flag already picks a
     * different implementation, and that one is deliberately empty.
     */
    it('switches implementation on the config flag', () => {
        const container = render(
            <svg>
                <BlobRenderer
                    renderer="sprite"
                    animation="idle"
                    frame={0}
                    features={['blob']}
                    items={[]}
                    abilities={[]}
                />
            </svg>,
        ).container;

        expect(container.querySelector('.blob-body')).toBeNull();
    });

    it('has a sprite renderer that is a stub and says so', () => {
        expect(
            SpriteBlobRenderer({
                animation: 'idle',
                frame: 0,
                features: ['blob'],
                items: [],
                abilities: [],
            }),
        ).toBeNull();
    });
});

function drawWithAbilities(abilities: string[]) {
    return render(
        <svg>
            <SvgBlobRenderer
                animation="idle"
                frame={0}
                features={['blob', 'legs']}
                items={[]}
                abilities={abilities}
            />
        </svg>,
    );
}

describe('ability props', () => {
    it('draws a book once Blob can read', () => {
        const { container } = drawWithAbilities(['read']);

        expect(container.querySelector('.blob-ability--read')).not.toBeNull();
    });

    it('draws nothing for read before it is unlocked', () => {
        const { container } = drawWithAbilities([]);

        expect(container.querySelector('.blob-ability--read')).toBeNull();
    });

    it('draws something to carry once Blob can carry', () => {
        const { container } = drawWithAbilities(['carry']);

        expect(container.querySelector('.blob-ability--carry')).not.toBeNull();
    });

    it('renders the body and no prop for an ability nothing draws', () => {
        // `wave` is a pose, not a prop: it has frames, not an ABILITIES entry.
        // The contract is that this cannot break the screen.
        const { container } = drawWithAbilities(['wave']);

        expect(container.querySelector('.blob-anim')).not.toBeNull();
        expect(container.querySelector('[class*="blob-ability--"]')).toBeNull();
    });
});
