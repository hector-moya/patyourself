import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnimationName } from '@/patyourself/companion-animations';
import {
    BlobRenderer,
    FLOOR,
    PALETTE,
    SpriteBlobRenderer,
    SvgBlobRenderer,
} from './blob-renderer';
import type { BlobRendererProps } from './blob-renderer';
import { CELL, FORMS, anchorFor, columnsOf } from './sprite-layout';

/** Parses a `transform="translate(x y)"` attribute back into numbers. */
function parseTranslate(value: string | null): [number, number] {
    const match = /translate\(([-\d.]+) ([-\d.]+)\)/.exec(value ?? '');

    if (match === null) {
        throw new Error(`not a translate(): ${value}`);
    }

    return [Number(match[1]), Number(match[2])];
}

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
     *
     * Drawn wearing and holding everything, which is the whole point of the
     * guard: with `items: []` it walked only the root, the translate group,
     * the nested `<svg>` and the `<image>` — never a `.blob-layer`. Those are
     * the layers whose transform changes every frame, so a transition on one
     * is exactly the interpolation this rule bans, and adding one to the item
     * group left all 277 tests green.
     */
    it('applies no transition to anything it draws', () => {
        const container = drawSprite({
            animation: 'jump',
            frame: 2,
            items: [
                { type: 'shoes', variant: null },
                { type: 'scarf', variant: 'coral' },
                { type: 'hat', variant: null },
                { type: 'glasses', variant: null },
            ],
            abilities: ['read', 'carry'],
            arriving: { type: 'hat', variant: null },
        });

        // The layers the guard was blind to have to actually be there, or it
        // is walking four elements again.
        expect(
            container.querySelectorAll('.blob-layer').length,
        ).toBeGreaterThanOrEqual(6);
        expect(
            container.querySelectorAll('.blob-layer--arriving'),
        ).toHaveLength(1);

        for (const node of container.querySelectorAll('*')) {
            const style = (node as HTMLElement).getAttribute('style') ?? '';

            expect(style).not.toContain('transition');
        }
    });

    /**
     * `form.foot` is the entirety of the spec's promise that "the body lands
     * with its feet on FLOOR and the room needs no changes whatsoever" —
     * every other placement number hangs off the anchor tables, but this one
     * decides where the whole body sits. Changing `arms.foot` from 53 to 40
     * left all 277 tests green while Blob floated 13 units above the floor.
     *
     * The expected row is a literal per form, not `form.foot` read live: an
     * expectation derived from the table under test moves with a regression
     * in it instead of catching one. Both halves are pinned here — the
     * constant, and the renderer subtracting it from `FLOOR` at all.
     */
    it('stands each form on the floor by its own measured foot row', () => {
        /** The lowest opaque row of each form's art (sprites/README.md). */
        const footRow: Record<string, number> = {
            blob: 51,
            legs: 53,
            arms: 53,
        };

        for (const form of FORMS) {
            expect(form.foot, form.feature).toBe(footRow[form.feature]);

            const container = drawSprite({ features: [form.feature] });
            const placed = container.querySelector(
                '.blob-anim > g',
            ) as SVGGElement;

            expect(parseTranslate(placed.getAttribute('transform'))).toEqual([
                -CELL / 2,
                FLOOR - footRow[form.feature],
            ]);
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

    /**
     * The enclosing group is `translate(-CELL / 2, …)` so the sprite's own
     * centre column sits at x=0 — but every anchor's `x` is measured from
     * that same centre line (sprites/README.md), not from the cell's left
     * edge. This layer has to undo the enclosing shift for the anchor's x
     * to land where the anchor table says, hence `x + CELL / 2` rather than
     * `x` alone. (Review round 1, finding 1: this test previously asserted
     * the un-corrected value and stayed green on an item drawn 32px off the
     * body.)
     */
    it('draws a layer per worn item, at that form-and-frame anchor', () => {
        const container = drawSprite({
            items: [{ type: 'hat', variant: null }],
        });
        const layer = container.querySelector('.blob-layer') as SVGGElement;
        const form = FORMS[FORMS.length - 1];
        const [x, y] = anchorFor(form, 'head', 'idle', 0);

        expect(layer.getAttribute('transform')).toBe(
            `translate(${x + CELL / 2} ${y})`,
        );
    });

    /**
     * Verifies the placement through the actual rendered SVG output, by
     * composing every transform between an item's rect and the sprite
     * root the way a browser would — rather than overlaying the art in an
     * external tool, which checks the art but never exercises this
     * component's transform math at all. The sprite image itself spans
     * exactly `[-CELL / 2, CELL / 2]` in this composited space (that is
     * what its own `-CELL / 2` shift means), so an item whose composed
     * position falls outside that span is drawn off the character's own
     * canvas — which is exactly what finding 1 above was.
     */
    it("keeps every worn item within the sprite image's own span, not shifted off it", () => {
        for (const type of ['hat', 'glasses', 'scarf', 'shoes']) {
            const container = drawSprite({ items: [{ type, variant: null }] });
            const layer = container.querySelector('.blob-layer') as SVGGElement;
            const [layerX] = parseTranslate(layer.getAttribute('transform'));

            for (const rect of layer.querySelectorAll('rect')) {
                const left =
                    -CELL / 2 + layerX + Number(rect.getAttribute('x'));
                const right = left + Number(rect.getAttribute('width'));

                expect(left).toBeGreaterThanOrEqual(-CELL / 2);
                expect(right).toBeLessThanOrEqual(CELL / 2);
            }
        }
    });

    /**
     * `arms`'s own walk table (sprite-layout.ts) only lifts the foot on
     * frame 2 — frames 0, 1 and 3 all sit at the base anchor, mid-stride —
     * so frames 1 and 2 are the pair guaranteed to differ, not 0 and 1.
     */
    it('moves the shoes as the walk cycle steps', () => {
        const at = (frame: number) =>
            drawSprite({
                animation: 'walk',
                frame,
                items: [{ type: 'shoes', variant: null }],
            })
                .querySelector('.blob-layer')!
                .getAttribute('transform');

        expect(at(1)).not.toBe(at(2));
    });

    /**
     * `SPRITE_ITEMS` names its default colour by PALETTE key rather than by
     * value (review round 1, finding 4) — a plain string, resolved back
     * into a colour only here, where PALETTE lives. This exercises both
     * kinds of default a key can name: an accessory shade in PALETTE
     * (hat's `slate`) and blob-renderer.tsx's own ink constant, which is
     * not a tail-recolourable PALETTE entry at all (glasses' `ink`).
     */
    it("resolves an item's default colour by key", () => {
        const hat = drawSprite({ items: [{ type: 'hat', variant: null }] });
        const glasses = drawSprite({
            items: [{ type: 'glasses', variant: null }],
        });

        expect(
            hat.querySelector('.blob-layer rect')!.getAttribute('fill'),
        ).toBe(PALETTE.slate);
        expect(
            glasses.querySelector('.blob-layer rect')!.getAttribute('fill'),
        ).toBe('#2A2622');
    });

    it('recolours an item the tail has renamed', () => {
        const container = drawSprite({
            items: [{ type: 'hat', variant: 'amber' }],
        });

        expect(
            container.querySelector('.blob-layer rect')!.getAttribute('fill'),
        ).toBe('#D4942E');
    });

    it('skips an item type it has no sprite geometry for', () => {
        const container = drawSprite({
            items: [{ type: 'umbrella', variant: null }],
        });

        expect(container.querySelectorAll('.blob-layer')).toHaveLength(0);
    });

    /**
     * The live record already carries `read`, so a renderer that drew no prop
     * would make the book vanish the moment sprites became the default —
     * while the rung still said Blob holds the page the right way up. A rung
     * that announces something and changes nothing is the exact failure this
     * phase exists to correct.
     */
    it('draws a prop for each ability that has one', () => {
        const container = drawSprite({ abilities: ['read', 'carry'] });

        expect(container.querySelector('.blob-ability--read')).not.toBeNull();
        expect(container.querySelector('.blob-ability--carry')).not.toBeNull();
    });

    it('draws no prop before the ability is unlocked', () => {
        expect(
            drawSprite().querySelector('[class*="blob-ability--"]'),
        ).toBeNull();
    });

    /**
     * `wave` is a pose, not a prop: it has frames rather than a
     * `SPRITE_ABILITIES` entry. Naming a thing must never break the screen,
     * so this draws the body and no gap — the same contract an item type with
     * no geometry gets.
     */
    it('renders the body and no prop for an ability nothing draws', () => {
        const container = drawSprite({ abilities: ['wave'] });

        expect(container.querySelector('.blob-sprite')).not.toBeNull();
        expect(container.querySelector('[class*="blob-ability--"]')).toBeNull();
        expect(container.querySelectorAll('.blob-layer')).toHaveLength(0);
    });

    /**
     * `play` is the strongest case: the body leaves the ground and comes
     * back, and a prop pinned to the resting anchor hangs in the air beside
     * it for three of the six frames — about 17 CSS px at room scale on
     * frame 3, which is the whole reason `hand` earned a per-frame table.
     *
     * Rows are literals rather than `anchorFor` read back: an expectation
     * derived from the table under test moves with a regression in it. These
     * are `arms`'s base hand row, 37, plus its own derived deltas
     * `[0, -1, -3, -5, -3, -1]`.
     */
    it('moves an ability prop with the body through an animation that swells', () => {
        const rows = [0, 1, 2, 3, 4, 5].map((frame) => {
            const layer = drawSprite({
                animation: 'play',
                frame,
                abilities: ['read'],
            }).querySelector('.blob-ability--read')!;

            return parseTranslate(layer.getAttribute('transform'))[1];
        });

        expect(rows).toEqual([37, 36, 34, 32, 34, 36]);
    });

    /**
     * A prop hangs off `hand` and nothing else, the same rule every worn
     * layer follows — including the `+ CELL / 2` correction, without which it
     * would draw 32px to the left of the body.
     */
    it('hangs an ability prop off the hand anchor for this form and frame', () => {
        const container = drawSprite({
            animation: 'notice',
            frame: 1,
            abilities: ['read'],
        });
        const form = FORMS[FORMS.length - 1];
        const [x, y] = anchorFor(form, 'hand', 'notice', 1);

        expect(
            container
                .querySelector('.blob-ability--read')!
                .getAttribute('transform'),
        ).toBe(`translate(${x + CELL / 2} ${y})`);
    });
});

/**
 * The `<image>` is drawn at `columnsOf(form) * CELL` by
 * `form.animations.length * CELL` whatever the PNG actually measures, and the
 * browser scales the file to fit. So a row added to `FORMS` without
 * regenerating the sheet — the next planned work is animations for the `blob`
 * and `legs` forms — rescales every cell on that sheet by a fraction, moving
 * the body and every anchor with it, with nothing else in the suite able to
 * see it.
 *
 * Read straight out of the PNG header rather than from a table: bytes 16–24
 * of any PNG are the IHDR width and height, big-endian.
 */
describe('the sheets are the size the layout draws them at', () => {
    it('matches each PNG to its own row and column count', () => {
        for (const form of FORMS) {
            const png = readFileSync(resolve(process.cwd(), `.${form.sheet}`));

            expect(
                [png.readUInt32BE(16), png.readUInt32BE(20)],
                form.feature,
            ).toEqual([columnsOf(form) * CELL, form.animations.length * CELL]);
        }
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

/**
 * Shoes are the first item drawn from generated art instead of rects, and the
 * mechanism is what these guard: a cell lifted out of a character state, drawn
 * at the cell's own origin, following only the anchor's per-frame movement.
 */
describe('SpriteBlobRenderer worn sheets', () => {
    const shoes = (overrides: Partial<BlobRendererProps> = {}) =>
        drawSprite({
            items: [{ type: 'shoes', variant: null }],
            ...overrides,
        }).querySelector('.blob-item-sheet');

    it('draws shoes from a sheet rather than from rects', () => {
        const sheet = shoes();

        expect(sheet).not.toBeNull();
        expect(sheet?.querySelector('image')).not.toBeNull();
        expect(sheet?.querySelectorAll('rect')).toHaveLength(0);
    });

    it('crops the cell the variant names', () => {
        // slate is cell 0, coral cell 1 — see SHOE_VARIANTS.
        expect(shoes()?.getAttribute('viewBox')).toBe('0 0 64 64');
        expect(
            shoes({
                items: [{ type: 'shoes', variant: 'coral' }],
            })?.getAttribute('viewBox'),
        ).toBe('64 0 64 64');
    });

    /**
     * Naming a colour must never be able to blank an item — the same contract
     * an unknown item type and an unknown scene already follow.
     */
    it('falls back to the first cell for a colour it has no art for', () => {
        expect(
            shoes({
                items: [{ type: 'shoes', variant: 'chartreuse' }],
            })?.getAttribute('viewBox'),
        ).toBe('0 0 64 64');
    });

    /**
     * The whole reason the art is lifted out of a character state: at rest the
     * cell already sits exactly where it belongs, so the layer is translated by
     * nothing at all. A layer that arrived pre-offset would be double-placed.
     */
    it('translates a resting frame by nothing', () => {
        const layer = drawSprite({
            items: [{ type: 'shoes', variant: null }],
        }).querySelector('.blob-layer');

        expect(layer?.getAttribute('transform')).toBe('translate(0 0)');
    });

    /**
     * ...and on a frame where the feet lift, it follows them. `jump` frame 2
     * carries the largest foot movement any animation has.
     */
    it('follows the feet on a frame where they leave the ground', () => {
        const layer = drawSprite({
            animation: 'jump',
            frame: 2,
            items: [{ type: 'shoes', variant: null }],
        }).querySelector('.blob-layer');

        expect(layer?.getAttribute('transform')).toBe('translate(0 -4)');
    });

    it('picks a different sheet for a form with different feet', () => {
        const href = (features: string[]) =>
            drawSprite({
                features,
                items: [{ type: 'shoes', variant: null }],
            })
                .querySelector('.blob-item-sheet image')
                ?.getAttribute('href');

        expect(href(['blob', 'legs'])).toBeTruthy();
        expect(href(['blob', 'legs'])).not.toBe(href(['blob', 'legs', 'arms']));
    });

    /**
     * The one the transform tests could not catch, and a render did.
     *
     * The enclosing group is already translated to the cell's top-left, so the
     * cell draws at 0,0 exactly as the body's own `.blob-sprite` does. Shifting
     * it again by half a cell — which looked right, since that is what every
     * anchor-relative layer does — put the boots in the bottom-left corner of
     * the viewBox with the feet left bare, and every assertion still green.
     */
    it('draws the cell at the cell origin, not shifted again', () => {
        const sheet = shoes();

        expect(sheet?.getAttribute('x')).toBe('0');
        expect(sheet?.getAttribute('y')).toBe('0');
        expect(sheet?.getAttribute('width')).toBe(String(CELL));
        expect(sheet?.getAttribute('height')).toBe(String(CELL));
    });

    /** A sheet keeps the same hard-edged rule the rects do, by this instead. */
    it('draws the sheet without smoothing it', () => {
        expect(
            shoes()?.querySelector('image')?.getAttribute('style') ?? '',
        ).toContain('pixelated');
    });
});
