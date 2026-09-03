import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

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
         * Movement only. The reaction lands beside the app's most protected
         * interaction, so it has to be visibly smaller than pet or play — and it
         * has to actually move, or the whole half of D2 is a no-op.
         */
        it('gives notice its own pose, and returns to standing', () => {
            const at = (frame: number) =>
                renderBody('notice', frame).getAttribute('style');

            expect(at(0)).toContain('translateY(0px)');
            expect(at(1)).toContain('translateY(-3px)');
            expect(at(3)).toContain('translateY(0px)');
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
                (frame) =>
                    renderBody('wave', frame).getAttribute('style') ?? '',
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
                (frame) =>
                    renderBody('jump', frame).getAttribute('style') ?? '',
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
     * The seam is real rather than hypothetical: the flag picks a genuinely
     * different implementation, not a fallback that quietly draws the same
     * thing twice.
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
        expect(container.querySelector('.blob-sprite')).not.toBeNull();
    });
});

function drawSprite(overrides: Partial<BlobRendererProps> = {}) {
    const props: BlobRendererProps = {
        animation: 'idle',
        frame: 0,
        features: ['blob', 'legs', 'arms'],
        items: [],
        abilities: [],
        ...overrides,
    };

    return render(
        <svg>
            <SpriteBlobRenderer {...props} />
        </svg>,
    ).container;
}

describe('SpriteBlobRenderer', () => {
    it('draws one image cropped to the cell for this animation and frame', () => {
        const cell = drawSprite({ animation: 'play', frame: 3 }).querySelector(
            '.blob-sprite',
        ) as SVGSVGElement;

        // play is row 6, frame 3 is column 3, on a 64px grid.
        expect(cell.getAttribute('viewBox')).toBe('192 384 64 64');
    });

    it('moves to a different cell when the frame advances', () => {
        const first = drawSprite({ animation: 'walk', frame: 0 })
            .querySelector('.blob-sprite')!
            .getAttribute('viewBox');
        const second = drawSprite({ animation: 'walk', frame: 1 })
            .querySelector('.blob-sprite')!
            .getAttribute('viewBox');

        expect(first).not.toBe(second);
    });

    /**
     * The one rule the renderer docblock singles out. A sprite sheet swaps
     * whole frames, and tweening between them is what makes pixel art look
     * wrong.
     */
    it('applies no transition to anything it draws', () => {
        const container = drawSprite({ animation: 'jump', frame: 2 });

        for (const node of container.querySelectorAll('*')) {
            const style = (node as HTMLElement).getAttribute('style') ?? '';

            expect(style).not.toContain('transition');
        }
    });

    it('holds the first idle frame for an animation this form has no art for', () => {
        const container = drawSprite({
            features: ['blob'],
            animation: 'play',
            frame: 4,
        });
        const cell = container.querySelector('.blob-sprite') as SVGSVGElement;

        expect(cell.getAttribute('viewBox')).toBe('0 0 64 64');
        expect(
            container
                .querySelector('.blob-anim')!
                .getAttribute('data-fallback'),
        ).toBe('idle');
    });

    it('draws the form the record has earned', () => {
        expect(
            drawSprite({ features: ['blob'] })
                .querySelector('.blob-anim')!
                .getAttribute('data-form'),
        ).toBe('blob');
        expect(
            drawSprite({ features: ['blob', 'legs'] })
                .querySelector('.blob-anim')!
                .getAttribute('data-form'),
        ).toBe('legs');
    });

    it('keeps the animation and frame readable from the DOM', () => {
        const anim = drawSprite({ animation: 'wave', frame: 2 }).querySelector(
            '.blob-anim',
        )!;

        expect(anim.getAttribute('data-animation')).toBe('wave');
        expect(anim.getAttribute('data-frame')).toBe('2');
    });

    /**
     * `blob-anim` alone is what the SVG renderer's transition rule in
     * patyourself.css keys off. This second class is what lets that rule
     * exclude the sprite root instead of relying on every duration downstream
     * staying at 0ms — see the CSS guard below, which checks the stylesheet
     * side of the same contract.
     */
    it("marks its root so the stylesheet can exempt it from the SVG renderer's easing", () => {
        const anim = drawSprite().querySelector('.blob-anim')!;

        expect(anim.classList.contains('blob-anim--sprite')).toBe(true);
    });
});

/**
 * The class alone is inert without the stylesheet honouring it. This reads
 * the real file rather than the DOM, because the class-name test above
 * cannot see whether patyourself.css actually excludes `blob-anim--sprite`
 * from the rule that eases the SVG renderer's transform — jsdom does not
 * apply an external stylesheet to a rendered component at all.
 */
describe("the stylesheet leaves sprite mode unaffected by the SVG renderer's easing", () => {
    it('excludes .blob-anim--sprite from the transition rule that eases .blob-anim', () => {
        const css = readFileSync(
            resolve(process.cwd(), 'resources/css/patyourself.css'),
            'utf8',
        );

        const transitionRule = css
            .split('}')
            .find(
                (rule) =>
                    rule.includes('.blob-anim') &&
                    rule.includes('transition-property:transform'),
            );

        expect(transitionRule).toBeDefined();
        expect(transitionRule).toContain('.blob-anim:not(.blob-anim--sprite)');
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
