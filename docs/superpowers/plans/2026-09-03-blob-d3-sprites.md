# Blob D3 — Sprites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Draw Blob from committed pixel-art sprite sheets, in three body forms, and make `sprite` the default renderer.

**Architecture:** `SpriteBlobRenderer` composites layers exactly as the SVG renderer does — a body `<image>` cropped to one sheet cell, then accessory layers positioned by anchors. Alignment lives in a data table (`sprite-layout.ts`), not in the art, because no in-scope item hangs off a limb. Motion is baked into the cells and nothing is ever tweened.

**Tech Stack:** React 19, TypeScript, Vitest, Laravel 13, PHPUnit. Art from Pixel Lab, committed as PNGs.

**Spec:** `docs/superpowers/specs/2026-09-03-blob-d3-sprites-design.md`

## Global Constraints

- **Frame counts and rates in `companion-animations.ts` are the contract and do not change.** `idle` 2f@2, `blink` 2f@8, `walk` 4f@6, `wave` 4f@8, `jump` 4f@8, `pet` 4f@8, `play` 6f@8, `notice` 4f@8.
- **No easing in sprite mode.** No `transition`, no `transitionDuration`, on any element `SpriteBlobRenderer` produces. Tweening between swapped frames is what makes pixel art look wrong.
- **Never name or preview a form, item or ability that is not yet unlocked**, in any surface.
- **Copy rules:** sentence case, one or two sentences, no exclamation marks, never congratulating, no second person keeping score. Say what Blob can now do, never how well the user is performing.
- **`CompanionVocabularyTest` scans companion source files, comments included**, for: streak, congratulation, well done, completion rate, percent, points, level up, lonely, hungry, misses you, neglect, cooldown. Watch `points` — "appoints"/"disappoints" trip it. Every new sprite source file must be added to its `sourceFiles()` list.
- **The SVG renderer stays untouched and working.** It is the fallback.
- Tests are PHPUnit, not Pest: `php artisan test --compact --filter=Name`, `npx vitest run`.
- Run `vendor/bin/pint --dirty --format agent` after touching PHP. Never `pint --test`.
- Exactly one pre-existing TypeScript error, at `catch-up.tsx:132`. **That count must not grow.** Check with `npx tsc --noEmit`.
- Every feature test that renders a view needs `$this->withoutVite()` in `setUp()`.
- **Prove every new guard goes red before trusting it.** If you cannot name the mutation that turns a test red, the test is decoration.

---

### Task 1: Build and commit the sprite sheets

**Executed by the orchestrator, not a subagent** — it needs Pixel Lab MCP access and visual judgement about which frames to keep.

**Files:**
- Create: `resources/js/patyourself/sprites/humus-blob.png` (2 rows × 2 cols, 128×128)
- Create: `resources/js/patyourself/sprites/humus-legs.png` (2 rows × 2 cols, 128×128)
- Create: `resources/js/patyourself/sprites/humus-arms.png` (8 rows × 6 cols, 384×512)
- Create: `resources/js/patyourself/sprites/README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: three PNG sheets on a uniform 64px grid, and measured constants (`foot` row and four anchor positions per form) that Task 2 hard-codes into `sprite-layout.ts`.

**Sheet format.** Cell is 64×64, matching the canvas Pixel Lab returns animation frames on. One row per animation in the order declared by that form's `animations` array. Columns run left to right, one per frame, `ANIMATIONS[name].frames` of them; any remaining cells in a row stay transparent.

Row order for `humus-arms.png`: `idle`, `blink`, `walk`, `wave`, `jump`, `pet`, `play`, `notice`.
Row order for `humus-blob.png` and `humus-legs.png`: `idle`, `blink`.

- [ ] **Step 1: Download every generated frame**

For each animation group on each of the three characters, fetch each frame PNG. Frames live at
`https://backblaze.pixellab.ai/file/pixellab-characters/<account>/<character>/animations/<animation>/south/<n>.png`,
which `get_character` returns in full. All frames arrive on a 64×64 canvas already registered to a common origin — do **not** re-crop or re-centre them, because that registration is what makes accessory alignment a per-form table rather than a per-frame one.

- [ ] **Step 2: Select frames down to the contract counts**

`idle` and `blink` are generated at four frames because Pixel Lab's minimum is four, and the contract wants two. Choose by looking:

- `idle`: the most-expanded frame and the most-settled frame — the two extremes of the breath. Not two adjacent frames, which read as a twitch.
- `blink`: the fully-open frame and the most-closed frame. If no frame closes the eyes convincingly, the animation is a re-roll, not a selection problem.

Every other animation keeps all its frames in generation order.

- [ ] **Step 3: Reject any frame where the body mass tilts or squashes**

Head and neck anchors ride the body. A hat cannot tilt with a head the compositor does not know has tilted. Compare each frame's silhouette against frame 0: the body outline may translate, and limbs may do whatever they like, but the body must not rotate or change height. A tilted frame is a re-roll of that animation, not something to compensate for in the table.

- [ ] **Step 4: Compose the sheets**

Paste each selected frame into its cell at `(col * 64, row * 64)`, preserving the frame's own 64×64 canvas position exactly. Write the three PNGs.

- [ ] **Step 5: Measure the constants Task 2 needs**

For each form, from the sheet's `idle` frame 0, record:

- `foot` — the cell row where the character's feet rest (the last row with a non-transparent pixel).
- `head` — the top-centre of the skull, where a hat sits. Not the top of the sprout.
- `neck` — where a scarf sits, at the widest point below the face.
- `feet` — the same row as `foot`, at centre.
- `hand` — the outer edge of one arm at rest. Form 1 and form 2 have no arms; give them the body's side edge so an ability prop still lands somewhere sane rather than at the origin.

Then measure the **offsets**, which are not optional garnish. The body breathes by changing height, so on any animation that swells or settles the head moves and a hat pinned to one fixed row will drift off it. For every frame of every animation, record how far `head`, `neck` and `feet` have moved from that form's base anchor. A frame that has not moved gets `[0, 0]` and that is a measurement, not a placeholder — note in the README which is which, so a later reader can tell a measured zero from an unmeasured one.

Record every number in `sprites/README.md` beside the character id it was measured from, so a later form can be measured the same way.

- [ ] **Step 6: Verify each sheet loads and has the expected dimensions**

Run: `python3 -c "from PIL import Image; [print(f, Image.open(f).size) for f in ['resources/js/patyourself/sprites/humus-blob.png','resources/js/patyourself/sprites/humus-legs.png','resources/js/patyourself/sprites/humus-arms.png']]"`
Expected: `(128, 128)`, `(128, 128)`, `(384, 512)`.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/sprites/
git commit -m "feat(blob): sprite sheets for three forms"
```

---

### Task 2: The layout module

**Files:**
- Create: `resources/js/patyourself/sprite-layout.ts`
- Test: `resources/js/patyourself/sprite-layout.test.ts`

**Interfaces:**
- Consumes: the three PNGs and the measured constants from Task 1.
- Produces:
  - `CELL: 64`
  - `interface SpriteForm { feature: string; sheet: string; animations: readonly AnimationName[]; foot: number; anchors: Readonly<Record<AnchorName, readonly [number, number]>>; offsets?: ... }`
  - `FORMS: readonly SpriteForm[]` — ordered earliest to latest
  - `formFor(features: readonly string[]): SpriteForm`
  - `rowFor(form: SpriteForm, animation: AnimationName): number | null`
  - `anchorFor(form: SpriteForm, anchor: AnchorName, animation: AnimationName, frame: number): readonly [number, number]`
  - `columnsOf(form: SpriteForm): number`
  - `type AnchorName = 'head' | 'neck' | 'feet' | 'hand'`

- [ ] **Step 1: Write the failing tests**

```ts
import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import type { AnimationName } from './companion-animations';
import {
    CELL,
    FORMS,
    anchorFor,
    columnsOf,
    formFor,
    rowFor,
} from './sprite-layout';

describe('sprite layout', () => {
    it('gives the fullest form a row for every animation the clock can play', () => {
        const fullest = FORMS[FORMS.length - 1];

        for (const name of Object.keys(ANIMATIONS) as AnimationName[]) {
            expect(fullest.animations).toContain(name);
        }
    });

    it('sizes each sheet by the widest animation it carries', () => {
        for (const form of FORMS) {
            const widest = Math.max(
                ...form.animations.map((name) => ANIMATIONS[name].frames),
            );

            expect(columnsOf(form)).toBe(widest);
        }
    });

    it('picks the latest form the record has earned', () => {
        expect(formFor(['blob']).feature).toBe('blob');
        expect(formFor(['blob', 'legs']).feature).toBe('legs');
        expect(formFor(['blob', 'legs', 'arms']).feature).toBe('arms');
    });

    /**
     * The resolver's ordering is its own business. The renderer must not
     * inherit a promise about it that nobody made.
     */
    it('ignores the order features happen to arrive in', () => {
        expect(formFor(['arms', 'blob', 'legs']).feature).toBe('arms');
    });

    it('falls back to the earliest form when it knows none of the features', () => {
        expect(formFor([]).feature).toBe(FORMS[0].feature);
        expect(formFor(['wings']).feature).toBe(FORMS[0].feature);
    });

    it('reports no row for an animation a form does not carry', () => {
        const earliest = FORMS[0];

        expect(rowFor(earliest, 'idle')).toBe(0);
        expect(rowFor(earliest, 'play')).toBeNull();
    });

    it('returns the form anchor when no offset is declared', () => {
        const form = FORMS[FORMS.length - 1];

        expect(anchorFor(form, 'head', 'idle', 0)).toEqual(form.anchors.head);
    });

    /**
     * Shoes are the one accessory that follows a limb, and only while
     * walking. A frame with no entry falls back to the base anchor rather
     * than to the origin — a missing number must not drop an accessory to
     * the top-left corner of the cell.
     */
    it('applies a walk offset to the feet and falls back past the table', () => {
        const form = FORMS[FORMS.length - 1];
        const base = form.anchors.feet;

        expect(anchorFor(form, 'feet', 'walk', 0)).not.toEqual(base);
        expect(anchorFor(form, 'feet', 'walk', 99)).toEqual(base);
        expect(anchorFor(form, 'feet', 'idle', 0)).toEqual(base);
    });

    it('draws every cell from the same square grid', () => {
        expect(CELL).toBe(64);
    });

    /**
     * The anchors and foot rows are measured off the art in Task 1 and
     * hand-copied here, which is exactly the kind of step that ships with
     * its placeholder zeros still in it. Nothing sits at the top-left
     * corner of a cell, and no character's feet rest on row zero.
     */
    it('carries measured constants rather than placeholder zeros', () => {
        for (const form of FORMS) {
            expect(form.foot).toBeGreaterThan(0);

            for (const [name, [x, y]] of Object.entries(form.anchors)) {
                expect(
                    x !== 0 || y !== 0,
                    `${form.feature}.${name} is still [0, 0]`,
                ).toBe(true);
            }
        }
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/sprite-layout.test.ts`
Expected: FAIL — cannot resolve `./sprite-layout`.

- [ ] **Step 3: Write the module**

Use the numbers recorded in `sprites/README.md` by Task 1 in place of every `0` below. Import the sheets as modules so Vite hashes them.

```ts
/**
 * Where the pixels are, and where things hang off them.
 *
 * The body sprite is one cell of a sheet. Everything worn on it is placed
 * by an anchor measured from that cell's top-left corner — per form,
 * because a form changes the body, and per frame only where a limb
 * actually carries the accessory.
 *
 * Alignment lives here rather than in the art. That is affordable only
 * because none of the four item types hangs off a hand: heads and necks
 * ride the body mass, and shoes are the single exception.
 */
import type { AnimationName } from '@/patyourself/companion-animations';
import { ANIMATIONS } from '@/patyourself/companion-animations';

import armsSheet from './sprites/humus-arms.png';
import blobSheet from './sprites/humus-blob.png';
import legsSheet from './sprites/humus-legs.png';

/** Every cell is this square, on every sheet. */
export const CELL = 64;

export type AnchorName = 'head' | 'neck' | 'feet' | 'hand';

type Offsets = Partial<
    Record<
        AnimationName,
        Partial<Record<AnchorName, readonly (readonly [number, number])[]>>
    >
>;

export interface SpriteForm {
    /** The `features` entry that brings this form about. */
    feature: string;
    sheet: string;
    /** One row per entry, in this order. */
    animations: readonly AnimationName[];
    /** The cell row the character's feet rest on. */
    foot: number;
    anchors: Readonly<Record<AnchorName, readonly [number, number]>>;
    /** Per-frame anchor overrides, for the anchors that follow a limb. */
    offsets?: Offsets;
}

/**
 * Earliest first. A record earns them in this order and never goes back:
 * a rounded mass, then legs, then arms.
 */
export const FORMS: readonly SpriteForm[] = [
    {
        feature: 'blob',
        sheet: blobSheet,
        animations: ['idle', 'blink'],
        foot: 0,
        anchors: { head: [0, 0], neck: [0, 0], feet: [0, 0], hand: [0, 0] },
    },
    {
        feature: 'legs',
        sheet: legsSheet,
        animations: ['idle', 'blink'],
        foot: 0,
        anchors: { head: [0, 0], neck: [0, 0], feet: [0, 0], hand: [0, 0] },
    },
    {
        feature: 'arms',
        sheet: armsSheet,
        animations: ['idle', 'blink', 'walk', 'wave', 'jump', 'pet', 'play', 'notice'],
        foot: 0,
        anchors: { head: [0, 0], neck: [0, 0], feet: [0, 0], hand: [0, 0] },
        // Measured, not guessed. `shoes` follow the legs through `walk`;
        // `head` and `neck` follow the body's height through any animation
        // that swells or settles, which `idle` and `play` both do. An
        // animation absent here needs no offsets at all.
        offsets: {
            walk: { feet: [[0, 0], [0, 0], [0, 0], [0, 0]] },
            idle: { head: [[0, 0], [0, 0]], neck: [[0, 0], [0, 0]] },
            play: {
                head: [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                neck: [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
            },
        },
    },
];

/** How wide a form's sheet is, in cells. Derived, so it cannot drift. */
export function columnsOf(form: SpriteForm): number {
    return Math.max(...form.animations.map((name) => ANIMATIONS[name].frames));
}

/**
 * The latest form this record has earned.
 *
 * Walked backwards over `FORMS` rather than forwards over `features`: the
 * order features arrive in belongs to the resolver, and leaning on it here
 * would make the drawing depend on something nobody promised.
 */
export function formFor(features: readonly string[]): SpriteForm {
    for (let index = FORMS.length - 1; index > 0; index -= 1) {
        if (features.includes(FORMS[index].feature)) {
            return FORMS[index];
        }
    }

    return FORMS[0];
}

/** Which row an animation sits on, or null when this form has no such row. */
export function rowFor(form: SpriteForm, animation: AnimationName): number | null {
    const row = form.animations.indexOf(animation);

    return row === -1 ? null : row;
}

/** Where an accessory hangs, for this form on this frame. */
export function anchorFor(
    form: SpriteForm,
    anchor: AnchorName,
    animation: AnimationName,
    frame: number,
): readonly [number, number] {
    const base = form.anchors[anchor];
    const offset = form.offsets?.[animation]?.[anchor]?.[frame];

    if (offset === undefined) {
        return base;
    }

    return [base[0] + offset[0], base[1] + offset[1]];
}
```

- [ ] **Step 4: Add the PNG module declaration if `tsc` complains**

Run: `npx tsc --noEmit`
If importing `.png` errors, add `resources/js/types/images.d.ts` with:

```ts
declare module '*.png' {
    const source: string;
    export default source;
}
```

Expected afterwards: exactly one error, at `catch-up.tsx:132`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/sprite-layout.test.ts`
Expected: PASS.

- [ ] **Step 6: Prove the guards go red**

Sabotage each, one at a time, confirm red, then revert:
- Drop `notice` from the `arms` form's `animations` → the "row for every animation" test fails.
- Change `formFor` to walk forwards → the "latest form" test fails.
- Return `base` unconditionally from `anchorFor` → the walk-offset test fails.
- Return `[0, 0]` when an offset is missing → the fallback half of that test fails.

Name each mutation in the commit body. A guard whose red you cannot produce is decoration.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/sprite-layout.ts resources/js/patyourself/sprite-layout.test.ts
git commit -m "feat(blob): sprite sheet layout and anchors"
```

---

### Task 3: Draw the body from a sheet

**Files:**
- Modify: `resources/js/patyourself/blob-renderer.tsx` — replace the `SpriteBlobRenderer` stub
- Modify: `resources/js/patyourself/blob-renderer.test.tsx` — two existing tests assert the stub returns null and must be replaced

**Interfaces:**
- Consumes: `CELL`, `FORMS`, `formFor`, `rowFor`, `columnsOf` from Task 2; `FLOOR` from `blob-renderer.tsx`.
- Produces: a working `SpriteBlobRenderer` emitting `.blob-anim > .blob-sprite` with `data-form`, and `data-fallback` when an animation has no row.

- [ ] **Step 1: Replace the two stub tests and write the new failing ones**

Delete `it('has a sprite renderer that is a stub and says so', ...)` — it asserts the very thing this task removes. Rewrite `it('switches implementation on the config flag', ...)` to assert the sprite path draws a sprite rather than nothing. Then add:

```ts
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
        const cell = drawSprite({ animation: 'play', frame: 3 })
            .querySelector('.blob-sprite') as SVGSVGElement;

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
            container.querySelector('.blob-anim')!.getAttribute('data-fallback'),
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
        const anim = drawSprite({ animation: 'wave', frame: 2 })
            .querySelector('.blob-anim')!;

        expect(anim.getAttribute('data-animation')).toBe('wave');
        expect(anim.getAttribute('data-frame')).toBe('2');
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/blob-renderer.test.tsx`
Expected: FAIL — `.blob-sprite` is null, because the stub returns null.

- [ ] **Step 3: Implement the renderer**

Replace the stub body in `blob-renderer.tsx`:

```tsx
/**
 * Blob as pixel art.
 *
 * One cell of a sheet per (animation, frame), drawn nearest-neighbour with
 * no interpolation between cells. The easing that makes the SVG renderer's
 * 2fps idle read as a breath is deliberately absent: a sprite sheet swaps
 * whole frames, and tweening between them is what makes pixel art look
 * wrong.
 *
 * An animation this form has no art for holds the first idle frame rather
 * than drawing an empty cell — the same rule as an item type no renderer
 * draws yet, and for the same reason.
 */
export function SpriteBlobRenderer({
    animation,
    frame,
    features,
    items,
    abilities,
    arriving = null,
}: BlobRendererProps) {
    const form = formFor(features);
    const row = rowFor(form, animation);
    const fallback = row === null;
    const drawnRow = fallback ? (rowFor(form, 'idle') ?? 0) : row;
    const drawnFrame = fallback ? 0 : frame;
    const columns = columnsOf(form);

    return (
        <g
            className="blob-anim"
            data-animation={animation}
            data-frame={frame}
            data-form={form.feature}
            data-fallback={fallback ? 'idle' : undefined}
        >
            <g transform={translate([-CELL / 2, FLOOR - form.foot])}>
                <svg
                    className="blob-sprite"
                    x={0}
                    y={0}
                    width={CELL}
                    height={CELL}
                    viewBox={`${drawnFrame * CELL} ${drawnRow * CELL} ${CELL} ${CELL}`}
                >
                    <image
                        href={form.sheet}
                        width={columns * CELL}
                        height={form.animations.length * CELL}
                        style={{ imageRendering: 'pixelated' }}
                    />
                </svg>
            </g>
        </g>
    );
}
```

Add the imports at the top of the file, and delete `void props;`. `items`, `abilities` and `arriving` are unused until Task 4 — reference them with `void items; void abilities; void arriving;` so lint stays quiet, and remove that line in Task 4.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/blob-renderer.test.tsx`
Expected: PASS.

- [ ] **Step 5: Prove the guards go red**

- Hard-code `drawnFrame` to `0` → "moves to a different cell" fails.
- Add `style={{ transitionDuration: '90ms' }}` to the outer `<g>` → the no-transition test fails.
- Make the fallback draw the requested row anyway → the fallback test fails.

- [ ] **Step 6: Run the whole JS suite and the type check**

Run: `npx vitest run && npx tsc --noEmit`
Expected: all tests pass; exactly one TypeScript error, at `catch-up.tsx:132`.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/blob-renderer.tsx resources/js/patyourself/blob-renderer.test.tsx
git commit -m "feat(blob): draw the sprite body from a sheet"
```

---

### Task 4: Accessory layers on the sprite

**Files:**
- Create: `resources/js/patyourself/sprite-items.tsx`
- Create: `resources/js/patyourself/sprite-items.test.tsx`
- Modify: `resources/js/patyourself/blob-renderer.tsx` — render item layers inside `SpriteBlobRenderer`

**Interfaces:**
- Consumes: `anchorFor`, `AnchorName`, `SpriteForm` from Task 2; `PALETTE` and `BlobItem` from `blob-renderer.tsx` (export `PALETTE` if it is not already exported).
- Produces: `SPRITE_ITEMS: Record<string, SpriteItemSpec>` where `interface SpriteItemSpec { anchor: AnchorName; colour: string; render: (colour: string) => ReactNode }`.

**Why a second dictionary.** `ITEMS` is what the shipping SVG renderer draws. Its shapes have rounded corners and a stroke, which are right there and wrong on a pixel grid. Editing it in place would change the live Blob, so sprite mode gets its own. Both read the same `PALETTE`, so a tail recolour lands in both without a second palette to keep in step.

- [ ] **Step 1: Write the failing tests**

```tsx
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SPRITE_ITEMS } from './sprite-items';

function drawItem(type: string, colour = '#123456') {
    return render(
        <svg>{SPRITE_ITEMS[type].render(colour)}</svg>,
    ).container;
}

describe('sprite items', () => {
    it('covers every item type the ladder can name', () => {
        for (const type of ['shoes', 'scarf', 'hat', 'glasses']) {
            expect(SPRITE_ITEMS[type]).toBeDefined();
        }
    });

    /**
     * Rounded corners and strokes are anti-aliased by the browser, which
     * is exactly the soft edge pixel art must not have. Hard rectangles at
     * integer coordinates are indistinguishable from pixels.
     */
    it('draws only hard-edged rectangles on integer coordinates', () => {
        for (const type of Object.keys(SPRITE_ITEMS)) {
            const container = drawItem(type);

            expect(container.querySelectorAll('circle')).toHaveLength(0);
            expect(container.querySelectorAll('path')).toHaveLength(0);

            for (const rect of container.querySelectorAll('rect')) {
                expect(rect.getAttribute('rx')).toBeNull();
                expect(rect.getAttribute('stroke')).toBeNull();

                for (const attribute of ['x', 'y', 'width', 'height']) {
                    expect(
                        Number.isInteger(Number(rect.getAttribute(attribute))),
                    ).toBe(true);
                }
            }
        }
    });

    it('paints with the colour it is handed', () => {
        const container = drawItem('hat', '#ABCDEF');

        expect(container.querySelector('rect')!.getAttribute('fill')).toBe(
            '#ABCDEF',
        );
    });

    it('hangs each type off the anchor that suits it', () => {
        expect(SPRITE_ITEMS.hat.anchor).toBe('head');
        expect(SPRITE_ITEMS.glasses.anchor).toBe('head');
        expect(SPRITE_ITEMS.scarf.anchor).toBe('neck');
        expect(SPRITE_ITEMS.shoes.anchor).toBe('feet');
    });
});
```

And in `blob-renderer.test.tsx`, inside the `SpriteBlobRenderer` describe. Add these imports to that file first:

```tsx
import { FORMS, anchorFor } from './sprite-layout';
```

```tsx
it('draws a layer per worn item, at that form-and-frame anchor', () => {
    const container = drawSprite({
        items: [{ type: 'hat', variant: null }],
    });
    const layer = container.querySelector('.blob-layer') as SVGGElement;
    const form = FORMS[FORMS.length - 1];
    const [x, y] = anchorFor(form, 'head', 'idle', 0);

    expect(layer.getAttribute('transform')).toBe(`translate(${x} ${y})`);
});

it('moves the shoes as the walk cycle steps', () => {
    const at = (frame: number) =>
        drawSprite({
            animation: 'walk',
            frame,
            items: [{ type: 'shoes', variant: null }],
        })
            .querySelector('.blob-layer')!
            .getAttribute('transform');

    expect(at(0)).not.toBe(at(1));
});

it('recolours an item the tail has renamed', () => {
    const container = drawSprite({
        items: [{ type: 'hat', variant: 'amber' }],
    });

    expect(container.querySelector('.blob-layer rect')!.getAttribute('fill'))
        .toBe('#D4942E');
});

it('skips an item type it has no sprite geometry for', () => {
    const container = drawSprite({
        items: [{ type: 'umbrella', variant: null }],
    });

    expect(container.querySelectorAll('.blob-layer')).toHaveLength(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/sprite-items.test.tsx resources/js/patyourself/blob-renderer.test.tsx`
Expected: FAIL — cannot resolve `./sprite-items`, and no `.blob-layer` in sprite output.

- [ ] **Step 3: Write `sprite-items.tsx`**

Coordinates are cell pixels relative to the item's anchor, chosen against the anchors Task 1 measured. Keep every shape a plain `<rect>` on whole numbers: no `rx`, no `stroke`, no `circle`, no `path`.

```tsx
/**
 * What Blob wears, drawn for the pixel renderer.
 *
 * A separate dictionary from `ITEMS` on purpose. That one draws the vector
 * Blob that ships today, with rounded corners and a stroke that are right
 * there and wrong here — a browser anti-aliases both, and a soft edge is
 * the one thing pixel art cannot have. Editing it in place would change a
 * drawing that is live.
 *
 * Both read the same PALETTE, so a recolour the tail names lands in both
 * without a second palette to keep in step.
 */
import type { ReactNode } from 'react';

import { PALETTE } from '@/patyourself/blob-renderer';
import type { AnchorName } from '@/patyourself/sprite-layout';

export interface SpriteItemSpec {
    anchor: AnchorName;
    /** The item's own colour when the unlock names no variant. */
    colour: string;
    render: (colour: string) => ReactNode;
}

export const SPRITE_ITEMS: Record<string, SpriteItemSpec> = {
    shoes: {
        anchor: 'feet',
        colour: PALETTE.slate,
        render: (colour) => (
            <>
                <rect x={-7} y={-2} width={5} height={3} fill={colour} />
                <rect x={2} y={-2} width={5} height={3} fill={colour} />
            </>
        ),
    },
    scarf: {
        anchor: 'neck',
        colour: PALETTE.rust,
        render: (colour) => (
            <>
                <rect x={-9} y={0} width={18} height={3} fill={colour} />
                <rect x={5} y={3} width={3} height={6} fill={colour} />
            </>
        ),
    },
    hat: {
        anchor: 'head',
        colour: PALETTE.slate,
        render: (colour) => (
            <>
                <rect x={-5} y={-6} width={10} height={5} fill={colour} />
                <rect x={-8} y={-1} width={16} height={2} fill={colour} />
            </>
        ),
    },
    glasses: {
        anchor: 'head',
        colour: '#2A2622',
        render: (colour) => (
            <>
                <rect x={-7} y={4} width={5} height={4} fill={colour} />
                <rect x={2} y={4} width={5} height={4} fill={colour} />
                <rect x={-2} y={5} width={4} height={1} fill={colour} />
            </>
        ),
    },
};
```

Export `PALETTE` from `blob-renderer.tsx` if it is not exported already.

- [ ] **Step 4: Render the layers in `SpriteBlobRenderer`**

Inside the anchored `<g>`, after the body `<svg>`, and delete the `void items;` line:

```tsx
{items.map((item, index) => {
    const spec = SPRITE_ITEMS[item.type];

    // An item type the ladder names but this renderer has no geometry
    // for is skipped rather than drawn as a gap — the SVG renderer's
    // existing contract, kept.
    if (spec === undefined) {
        return null;
    }

    const colour =
        item.variant === null
            ? spec.colour
            : (PALETTE[item.variant] ?? spec.colour);
    const [x, y] = anchorFor(
        form,
        spec.anchor,
        fallback ? 'idle' : animation,
        drawnFrame,
    );
    const isArriving =
        arriving !== null &&
        arriving.type === item.type &&
        arriving.variant === item.variant;

    return (
        <g
            key={`${item.type}-${item.variant ?? 'plain'}-${index}`}
            transform={`translate(${x} ${y})`}
            className={
                isArriving ? 'blob-layer blob-layer--arriving' : 'blob-layer'
            }
        >
            {spec.render(colour)}
        </g>
    );
})}
```

Leave `abilities` unused for now — ability props hang off the hand anchor and are out of scope. Keep `void abilities;`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx vitest run && npx tsc --noEmit`
Expected: all pass; exactly one TypeScript error, at `catch-up.tsx:132`.

- [ ] **Step 6: Prove the guards go red**

- Give `hat` an `rx={2}` → the hard-edges test fails.
- Ignore the `variant` and always use `spec.colour` → the recolour test fails.
- Render a `<g>` instead of `null` for an unknown type → the skip test fails.
- Pass `'idle'` to `anchorFor` unconditionally → the shoes-step test fails.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/sprite-items.tsx resources/js/patyourself/sprite-items.test.tsx resources/js/patyourself/blob-renderer.tsx resources/js/patyourself/blob-renderer.test.tsx
git commit -m "feat(blob): worn items on the sprite renderer"
```

---

### Task 5: The `arms` rung, and sprite by default

**Files:**
- Modify: `config/companion.php` — one new ladder entry, and the renderer default
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` — three new source files
- Test: `tests/Feature/Companion/CompanionLadderTest.php`

**Interfaces:**
- Consumes: the working sprite renderer from Tasks 3 and 4.
- Produces: `features` can now contain `arms`; `config('companion.renderer')` defaults to `sprite`.

**The rung.** Inserted between `legs` (`logs: 3`) and `shoes` (`logs: 5`), at `logs: 4`. It changes no existing entry's trigger or count, and it keeps the ladder's ordering monotonic — which matters, because the resolver walks in order and stops at the first unsatisfied entry, so a rung placed out of order would gate everything after it behind a count that has not been reached.

```php
[
    'trigger' => 'logs',
    'at' => 4,
    'kind' => 'body',
    'name' => 'arms',
    'message' => 'Blob has arms now. It has not decided what they are for.',
],
```

- [ ] **Step 1: Write the failing tests**

In `CompanionLadderTest`:

```php
public function test_the_arms_rung_sits_between_legs_and_shoes(): void
{
    $ladder = config('companion.ladder');
    $names = array_column($ladder, 'name');

    $this->assertSame(
        ['blob', 'legs', 'arms', 'shoes'],
        array_slice($names, 0, 4),
    );
}

/**
 * The resolver stops at the first unsatisfied entry, so an entry whose
 * count sits below its predecessor's would gate everything after it
 * behind a number the record has already passed.
 */
public function test_each_trigger_counts_upward_through_the_ladder(): void
{
    $seen = [];

    foreach (config('companion.ladder') as $rung) {
        $trigger = $rung['trigger'];
        $previous = $seen[$trigger] ?? 0;

        $this->assertGreaterThan(
            $previous,
            $rung['at'],
            "{$trigger} goes backwards at {$rung['name']}",
        );

        $seen[$trigger] = $rung['at'];
    }
}

public function test_sprites_are_what_ships(): void
{
    $this->assertSame('sprite', config('companion.renderer'));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CompanionLadderTest`
Expected: FAIL — no `arms` in the ladder, and the renderer is still `svg`.

- [ ] **Step 3: Make the config changes**

Insert the rung above, and change the renderer default:

```php
'renderer' => env('COMPANION_RENDERER', 'sprite'),
```

Update the `Renderer` comment block: `svg` is now the fallback and `sprite` is what ships.

- [ ] **Step 4: Add the new files to the vocabulary test**

In `CompanionVocabularyTest::sourceFiles()`, append:

```php
$root.'/resources/js/patyourself/sprite-layout.ts',
$root.'/resources/js/patyourself/sprite-items.tsx',
$root.'/resources/js/patyourself/sprites/README.md',
```

- [ ] **Step 5: Prove that list is live**

Temporarily add the word `cooldown` to a comment in `sprite-layout.ts`.

Run: `php artisan test --compact --filter=CompanionVocabularyTest`
Expected: FAIL, naming `sprite-layout.ts`. Then remove the word and confirm it passes. This is the trap that has bitten before — a file absent from the list is scanned by nothing.

- [ ] **Step 6: Run the affected suites**

Run: `php artisan test --compact --filter=Companion && vendor/bin/pint --dirty --format agent`
Expected: all pass, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add config/companion.php tests/Feature/Companion/
git commit -m "feat(blob): an arms rung, and sprites by default"
```

---

### Task 6: Look at it

**Files:**
- Create: `storage/app/blob-preview.html` (throwaway, not committed)

**Interfaces:**
- Consumes: everything above.
- Produces: a human judgement. Class-name assertions and geometry arithmetic cannot judge a drawing.

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: succeeds. Skipping this makes `PwaManifestTest` skip and the assertion count drop.

- [ ] **Step 2: Render every form, animation and frame to one page**

Write an HTML file that embeds the three sheets and draws, for each form, every animation across all its frames at both sizes that matter — 32px wide (the dashboard corner) and 448px (the room at `max-w-md`) — plus one Blob wearing all four items.

- [ ] **Step 3: Serve it and look**

Run: `php -S 127.0.0.1:8899 -t storage/app`
Herd serves the **main checkout**, never a worktree, so a static server is the only way to see this branch's output.

Judge, in this order:
1. **Does the sprout survive at 32px?** It is the visual identity and the thinnest part of the silhouette. If it disappears, say so — the fix is a stouter sprout on a later form, not more detail.
2. **Does any accessory float off the body, on any frame of any animation?** That is the anchor table being wrong, and it is what the whole layering decision rests on.
3. **Does any animation tilt or squash the body mass?** That frame is a re-roll, not a table adjustment.
4. **Do the three forms read as one creature growing?**

- [ ] **Step 4: Check the app itself**

Run the app and open `/companion` and the dashboard. Under browser automation `data-frame` reads 0 forever — the clock stops on `document.hidden` — so trust `data-animation` and check `data-form` changed.

- [ ] **Step 5: Full suite, then commit any fixes**

Run: `php artisan test --compact && npx vitest run && npx tsc --noEmit && vendor/bin/pint --dirty --format agent`
Expected: 836+ PHP tests and 243+ JS tests pass; exactly one TypeScript error, at `catch-up.tsx:132`.

---

## Notes for the executor

- **Do not re-crop Pixel Lab frames.** They arrive registered to a shared 64×64 canvas, and that registration is why anchors are a per-form table rather than a per-frame one.
- **Do not add a transition to sprite mode**, however much an animation looks like it wants one. That is the single rule the renderer's own docblock singles out.
- **Do not touch `ITEMS`, `SvgBlobRenderer`, `CompanionResolver` or `CompanionState`.** The SVG renderer is the fallback and must keep working exactly as it does now.
- If an animation's frames are wrong, the fix is regenerating that animation, not compensating in the anchor table. A table that apologises for bad art gets worse with every form.
