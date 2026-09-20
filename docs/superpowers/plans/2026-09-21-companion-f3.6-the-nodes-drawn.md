# F3.6 — the clearing drawn, part two: the four nodes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Draw the four nodes in the clearing at three standing amounts each, so the art carries the amount and no text label is left outdoors.

**Architecture:** Three semantic bands — `bare`, `some`, `plenty` — with the thresholds authored in `config/companion.php` and resolved server-side, so the art stays semantic and a threshold can move without a re-roll. The sprites are generated square at 48×48 (the tool forces square), then cropped to per-node `[w, h]` cells with all bands of one node sharing one crop box, so registration survives. `ShelterLayer` generalises to `NodeLayer`; `NodeSpot` becomes what `ShelterSpot` already is — a transparent control over the art.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit 12, Inertia v3 + React 19, Vitest, Tailwind v4, Pixel Lab MCP.

**Spec:** `docs/superpowers/specs/2026-09-21-companion-f3.6-the-nodes-drawn-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **`docs/BLOB.md` outranks every spec.** Where this plan and that file disagree, that file is right.
- **Banned vocabulary**, enforced by `CompanionVocabularyTest` over a file list, **comments included**: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` is a substring trap — *appoints* and *disappoints* trip it. `resources/js/patyourself/scenes/README.md` and `scenes.ts` are both on that list. `docs/BLOB.md` is deliberately **not** and must stay off.
- **Thresholds live in `config/companion.php` only.** Never duplicated into TypeScript.
- **`cn()`, never template literals**, for conditional class names — `` `base${x ? 'mod' : ''}` `` yields `baseis-quiet`. Assert with `toHaveClass`, because `toContain` passes on the mangled string.
- **`Object.hasOwn` for every new record lookup.** A bare lookup resolves `'constructor'` to a truthy function through the prototype chain.
- **Never run `npm run format`** (`prettier --write resources/`) or **`npm run lint`** (`eslint . --fix`). The first reformats `resources/css/patyourself.css`, which is hand-formatted and already fails on main — it turned a 94-line addition into a 3782-line diff once. The second descends into `.claude/worktrees/*` and silently rewrote 118 files once. Always scope to named files: `npx prettier --write <file>`, `npx eslint <file> --fix`.
- **Tests are SQLite; production is MySQL.** A green suite is not evidence raw SQL works. `assertDatabaseMissing` on a column that does not exist is a constant-false predicate under SQLite.
- **Eager loads:** never `->with('rel:id,title')` — a column-limited eager load returns null for a new column forever, with the suite green.
- **Run the minimum tests.** `php artisan test --compact --filter=name`, `npx vitest run <path>`.
- **Run `vendor/bin/pint --dirty --format agent`** after touching PHP.
- **Herd never serves a worktree.** To look at the page, dump to HTML and `php -S 127.0.0.1:8899 -t public`.
- **Blob's frames freeze under browser automation** — the clock stops on `document.hidden`, so `data-frame` reads 0 forever. Trust `data-animation`, `data-scene`, `data-band`.
- **Every test must name the mutation that turns it red.** If you cannot, it is decoration. This is where F3 and F3.5's review budget went, and it is where it goes again.

---

## File Structure

**Created:**

| File | Responsibility |
| --- | --- |
| `resources/js/patyourself/companion.harness.tsx` | `renderClearing()` — builds the outdoor payload, renders, and asserts the clearing is on screen. Separate from `companion.fixture.ts` so that file stays pure data and so its other consumers do not pull in the whole page. `.tsx` because it holds JSX. |
| `resources/js/patyourself/scenes/node-reeds-{bare,some,plenty}.png` | The reeds, three bands. |
| `resources/js/patyourself/scenes/node-deadfall-{bare,some,plenty}.png` | The fallen branches, three bands. |
| `resources/js/patyourself/scenes/node-trunk-{bare,some,plenty}.png` | The fallen trunk, three bands. |
| `resources/js/patyourself/scenes/node-salvage-{some,plenty}.png` | The heap, two bands. No `bare`: `HarvestNode` deletes a drained heap. |

**Modified:**

| File | Change |
| --- | --- |
| `config/companion.php` | `node_bands` block, after `nodes`. |
| `app/Services/Companion/CompanionBag.php:210-246` | `nodes()` emits `band`; new private `bandFor()`; array shape in the `forUser()` docblock. |
| `resources/js/patyourself/companion.tsx:54` | `BagNodeData.band`. |
| `resources/js/patyourself/companion.fixture.ts` | `band` on every node row; a `salvage` row. |
| `resources/js/patyourself/scenes.ts` | `NODE_SPRITES`, `NODE_CELLS`, `nodeSprite()`, `nodeCell()`; `NodeSpec.at` changes meaning to base centre. |
| `resources/js/patyourself/companion-room.tsx` | `roomSize()` beside `roomOffset()`; `NodeLayer`; a `nodes` prop. |
| `resources/js/pages/companion.tsx` | `NodeSpot` becomes a control over art; focus recovery on `.c-stage`; `ShelterSpot` uses `roomSize()`. |
| `resources/css/patyourself.css:1200-1203` | `.c-shelter--art` becomes `.c-node--art`, sizing removed, 24px floor added. **Hand-edited only.** |
| `resources/js/patyourself/scenes/README.md` | The eleven sprites: job ids, candidates, crop boxes, cells. |
| `docs/BLOB.md` | §7 the fifth pipeline run; §12 the reeds' `[50, 44]`; §12 the label-sizing item retired. |

---

# Batch 0 — the seam

No art. Land this before anything else: the next twenty tests all target the branch it protects.

## Task 1: `renderClearing()`

**Files:**
- Create: `resources/js/patyourself/companion.harness.tsx`
- Test: `resources/js/pages/companion.test.tsx` (migrate two existing cases onto it)

**Interfaces:**
- Consumes: `companion()` and `bag()` from `@/patyourself/companion.fixture`; `CompanionPage` (the default export of `@/pages/companion`).
- Produces: `renderClearing(opts?: { companion?: Partial<CompanionData>; bag?: Partial<CompanionBagData> }): { companion: CompanionData; bag: CompanionBagData }` — every later task's clearing test goes through it.

**Why this exists.** `companion.fixture.ts` defaults `scene: 'cabin'`, and `companion-room.tsx:331` computes `indoors = inside || scene.name === 'cabin'`. A test that omits `scene: 'forest'` exercises the indoor branch and asserts nothing about the clearing. It has bitten five times, twice in test code written hours after reading the warning. The failure is never a red test — it is an assertion passing vacuously.

`companion.harness.tsx` is **not** added to `CompanionVocabularyTest::sourceFiles()`: that list scans shipped source for user-facing copy, and no test-support file is on it — `companion.fixture.ts` is not either.

- [ ] **Step 1: Write the harness**

Create `resources/js/patyourself/companion.harness.tsx`:

```tsx
/**
 * Rendering the clearing, with the one mistake this suite keeps making made
 * impossible.
 *
 * `companion.fixture.ts` defaults `scene: 'cabin'`, and `companion-room.tsx`
 * computes `indoors = inside || scene.name === 'cabin'` — so a test that
 * forgets `scene: 'forest'` silently renders the INTERIOR and asserts nothing
 * about the clearing at all. That has shipped five times across F3 and F3.5,
 * twice in tests written hours after someone read the warning. It is never a
 * red test; it is an assertion passing for the wrong reason.
 *
 * So this builds the payload with the scene baked in, renders, AND CHECKS
 * THE CLEARING IS ACTUALLY THERE before handing back. A test that uses it
 * cannot under-specify the scene. A test that hand-rolls its payload and
 * renders directly is not protected — nothing can protect that — but every
 * new clearing test is expected to come through here.
 *
 * Separate from `companion.fixture.ts` on purpose, twice over: that file is
 * pure data and says so, and five other suites import it without wanting the
 * whole companion page dragged in behind them.
 */
import { render, screen } from '@testing-library/react';

import type {
    CompanionBagData,
    CompanionData,
} from '@/patyourself/companion';
import { bag, companion } from '@/patyourself/companion.fixture';
import CompanionPage from '@/pages/companion';

export function renderClearing(
    opts: {
        companion?: Partial<CompanionData>;
        bag?: Partial<CompanionBagData>;
    } = {},
): { companion: CompanionData; bag: CompanionBagData } {
    const payload = {
        companion: companion({ scene: 'forest', ...opts.companion }),
        bag: bag(opts.bag),
    };

    render(<CompanionPage {...payload} />);

    const scene = screen.getByRole('img');

    if (
        scene.dataset.scene !== 'forest' ||
        scene.dataset.interior !== undefined
    ) {
        throw new Error(
            'the clearing did not render: got scene=' +
                `${scene.dataset.scene} interior=${scene.dataset.interior}. ` +
                "companion.fixture defaults to scene: 'cabin'.",
        );
    }

    return payload;
}
```

- [ ] **Step 2: Write the failing test that proves the guard fires**

Add to `resources/js/pages/companion.test.tsx`, importing `renderClearing` from `@/patyourself/companion.harness`:

```tsx
describe('the clearing harness', () => {
    /**
     * The guard's own guard. Without this, `renderClearing` could stop
     * checking and nothing would notice — which is precisely the failure
     * mode it exists to end.
     *
     * The mutation that turns this red: delete the `if` block in
     * `companion.harness.tsx`.
     */
    it('refuses to hand back an interior', () => {
        expect(() => renderClearing({ companion: { scene: 'cabin' } })).toThrow(
            /the clearing did not render/,
        );
    });

    it('renders the clearing without being told the scene', () => {
        renderClearing();

        expect(screen.getByRole('img')).toHaveAttribute('data-scene', 'forest');
    });
});
```

- [ ] **Step 3: Run it and watch it fail**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "clearing harness"`
Expected: FAIL — `Cannot find module '@/patyourself/companion.harness'` before Step 1 exists, or a type error if `CompanionPage` is not the default export. Confirm the reason is one of those, not something else.

- [ ] **Step 4: Run it and watch it pass**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "clearing harness"`
Expected: PASS, 2 tests.

- [ ] **Step 5: Migrate two existing clearing tests onto the harness**

Pick the two cases at `companion.test.tsx:112` and `:197` (both build `companion({ scene: 'forest' })` by hand). Replace their `render(<CompanionPage ... />)` with `renderClearing({ ... })`, keeping every assertion byte-identical. Leave the other 28 alone — this is a proof the harness carries real cases, not a migration.

- [ ] **Step 6: Run the whole page suite**

Run: `npx vitest run resources/js/pages/companion.test.tsx`
Expected: PASS, no count change other than the 2 added.

- [ ] **Step 7: Typecheck and commit**

```bash
npx tsc --noEmit
npx prettier --write resources/js/patyourself/companion.harness.tsx
git add resources/js/patyourself/companion.harness.tsx resources/js/pages/companion.test.tsx
git commit -m "test(companion): make a forgotten scene loud instead of silent"
```

---

## Task 2: Focus recovery when a hotspot unmounts

**Files:**
- Modify: `resources/js/pages/companion.tsx` (the `.c-stage` wrapper at :292, `ShelterSpot`'s caller at :376)
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `renderClearing` from Task 1.
- Produces: a `recoverFocus` ref-flag pattern on the page that Task 13's `NodeSpot` reuses for the heap.

**The defect.** `ShelterSpot` unmounts on click — going inside removes it — and focus falls to `<body>`, so the next Tab restarts from the top of the document. After this phase the heap's hotspot unmounts too, because `HarvestNode:112` deletes a drained heap. Fixing it once here is why it is in Batch 0.

The recovery target is the `.c-stage` wrapper, not the `<svg>`: it is an `HTMLElement` (so `focus()` is reliable under jsdom), the page already owns it, and it contains the hotspots, so focus lands where the removed control was.

- [ ] **Step 1: Write the failing test**

```tsx
/**
 * A control that removes itself must not take the keyboard with it. Going
 * inside unmounts `ShelterSpot`, and focus lands on <body>, so the next Tab
 * restarts at the top of the document.
 *
 * Asserting "not body" rather than naming an element on purpose: the defect
 * IS focus falling to the document, and pinning the replacement element
 * would re-encode the implementation and fail the next time it moves.
 *
 * The mutation that turns this red: delete the `useEffect` block.
 */
it('keeps the keyboard in the clearing when a hotspot unmounts under it', () => {
    renderClearing({
        bag: { shelter: { built: 'hut', label: 'hut', offer: null } },
    });

    const spot = screen.getByRole('button', { name: /go inside the hut/i });
    spot.focus();
    expect(document.activeElement).toBe(spot);

    fireEvent.click(spot);

    expect(screen.queryByRole('button', { name: /go inside the hut/i })).toBeNull();
    expect(document.activeElement).not.toBe(document.body);
});

/**
 * And the other half, which is the half that makes the fix safe: focus that
 * SURVIVED the click is never stolen. The plinth's own control stays mounted
 * across the toggle — it only changes its label — so activating it must
 * leave focus exactly where it was.
 *
 * The mutation that turns this red: drop the
 * `document.activeElement === document.body` condition and focus
 * unconditionally.
 */
it('does not steal focus that survived the click', () => {
    renderClearing({
        bag: { shelter: { built: 'hut', label: 'hut', offer: null } },
    });

    const plinth = screen.getByRole('button', { name: 'Go inside' });
    plinth.focus();

    fireEvent.click(plinth);

    expect(document.activeElement).toBe(
        screen.getByRole('button', { name: 'Go outside' }),
    );
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "unmounts under it"`
Expected: FAIL — `expected <body> not to be <body>`.

- [ ] **Step 3: Implement**

In `resources/js/pages/companion.tsx`, inside the component that renders `.c-stage` (add `useEffect`, `useRef` to the existing `react` import):

```tsx
    const stage = useRef<HTMLDivElement>(null);
    /**
     * Set by a hotspot that is about to remove itself — going inside unmounts
     * `ShelterSpot`, and draining the heap deletes its node row and with it
     * `NodeSpot`. A ref rather than state: this must not cause a render of
     * its own, it only has to survive until the next commit.
     */
    const recoverFocus = useRef(false);

    /**
     * No dependency array: this has to run after EVERY commit, because what it
     * is watching for is an element disappearing rather than a value changing.
     * The ref is the gate, so it costs a boolean check on renders that are not
     * about focus.
     *
     * The condition is the whole safety of it. Focus is recovered only when it
     * was actually LOST — a click that left focus somewhere real must not have
     * it dragged back to the stage, which would be worse than the bug.
     */
    useEffect(() => {
        if (!recoverFocus.current) {
            return;
        }

        recoverFocus.current = false;

        if (document.activeElement === document.body) {
            stage.current?.focus();
        }
    });
```

Give the wrapper the ref and a programmatic-only tab stop — `tabIndex={-1}` keeps it out of the Tab order while making it a legal `focus()` target:

```tsx
            <div className="c-stage" ref={stage} tabIndex={-1}>
```

And have `ShelterSpot`'s handler raise the flag:

```tsx
                            onClick={() => {
                                recoverFocus.current = true;
                                onToggleInside();
                            }}
```

- [ ] **Step 4: Run both tests**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "focus"`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run the whole page suite, then commit**

```bash
npx vitest run resources/js/pages/companion.test.tsx
npx tsc --noEmit
git add resources/js/pages/companion.tsx resources/js/pages/companion.test.tsx
git commit -m "fix(companion): keep the keyboard in the clearing when a hotspot goes"
```

---

## Task 3: One sizing arithmetic for every hotspot over art

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx:49-57` (beside `roomOffset`)
- Modify: `resources/js/pages/companion.tsx` (`ShelterSpot`)
- Modify: `resources/css/patyourself.css:1200-1203`
- Test: `resources/js/patyourself/companion-room.test.tsx` (Step 1), `resources/js/pages/companion.test.tsx` (Step 7)

Add `roomSize` to `companion-room.test.tsx`'s existing `./companion-room` import, and `renderClearing` to `companion.test.tsx`'s imports from `@/patyourself/companion.harness`.

**Interfaces:**
- Produces: `roomSize(w: number, h: number): { width: string; height: string }`, exported from `@/patyourself/companion-room`. Task 13's `NodeSpot` consumes it.

**Why now.** `.c-shelter--art` hardcodes `width: 33.333%; height: 42.105%` — 48/144 and 48/114, computed by hand and written down. With four per-node cells arriving in Batch 2 there would be five such pairs, each able to go stale on its own. Derive it once, with one caller, while there is still only one caller to break.

**Do not run prettier on the CSS file.** Hand-edit it. It is hand-formatted, already fails `format:check` on main, and `--write` over it produced a 3782-line diff once.

- [ ] **Step 1: Write the failing test**

Add to `resources/js/patyourself/companion-room.test.tsx`:

```ts
/**
 * The same arithmetic `roomOffset` does for a point, for a box. Both exist so
 * a real HTML button can be laid over a scaling `<svg>` and track it: a
 * percentage follows the picture at every width, and an absolute pixel size
 * would be right at exactly one.
 *
 * The mutation that turns this red: divide by ROOM.h in the width (the two
 * are 144 and 114, so a swap is not caught by a square case — which is why
 * the case below is deliberately NOT square).
 */
it('sizes a box over the picture as a share of the room', () => {
    expect(roomSize(48, 114)).toEqual({ width: '33.33333333333333%', height: '100%' });
    expect(roomSize(144, 57)).toEqual({ width: '100%', height: '50%' });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx -t "share of the room"`
Expected: FAIL — `roomSize is not defined`.

- [ ] **Step 3: Implement `roomSize`**

In `resources/js/patyourself/companion-room.tsx`, immediately after `roomOffset`:

```ts
/**
 * A box in the room's own units, as a CSS size over the drawing.
 *
 * The companion of `roomOffset` above, and exported for the same reason: the
 * hotspots are laid out by the PAGE, over an `<svg>` that scales with its
 * container, so their size has to be a share of that box rather than a pixel
 * count that is only right at one width.
 *
 * It replaces four hand-computed magic numbers — `.c-shelter--art` carried
 * `33.333%` and `42.105%`, which are 48/144 and 48/114 worked out by hand and
 * written down. Four node cells were about to add eight more.
 */
export function roomSize(
    width: number,
    height: number,
): { width: string; height: string } {
    return {
        width: `${(width / ROOM.w) * 100}%`,
        height: `${(height / ROOM.h) * 100}%`,
    };
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx -t "share of the room"`
Expected: PASS.

- [ ] **Step 5: Move `ShelterSpot` onto it**

In `resources/js/pages/companion.tsx`, import `roomSize` alongside `roomOffset`, and in `ShelterSpot`:

```tsx
        <button
            type="button"
            className={cn('c-node', 'c-shelter', 'c-node--art')}
            style={{ ...at, ...roomSize(SHELTER_CELL, SHELTER_CELL) }}
```

- [ ] **Step 6: Edit the CSS by hand**

Replace lines 1200–1203 of `resources/css/patyourself.css`:

```css
/* Sized to the art it covers, not to a word — and sized in JS, from the cell
   the sprite is drawn on, because `roomSize` already knows that arithmetic and
   four node cells would otherwise put eight more magic percentages here.

   The picture is the affordance; the outline is how a pointer and a keyboard
   find it. The floor is WCAG 2.5.8 (AA), 24x24 CSS px: a net, not a target,
   because a fixed-pixel box that grows in room units as the stage shrinks is
   exactly the label problem this phase exists to retire. */
.c-node--art{padding:0;background:transparent;border-color:transparent;min-width:24px;min-height:24px;}
.c-node--art:hover{background:rgba(15,12,9,.28);border-color:#FFD9A8;}
```

- [ ] **Step 7: Prove the shelter's control still has a box**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "go inside"`
Expected: PASS. Then add the regression that the class rename could otherwise lose silently:

```tsx
/**
 * `toHaveClass`, not `toContain` on the class string: a template literal that
 * eats its leading space ships `class="c-nodec-node--art"`, and a substring
 * check passes on exactly that.
 *
 * The mutation that turns this red: drop `roomSize(...)` from the style.
 */
it('sizes the shelter control to its art', () => {
    renderClearing({
        bag: { shelter: { built: 'hut', label: 'hut', offer: null } },
    });

    const spot = screen.getByRole('button', { name: /go inside the hut/i });

    expect(spot).toHaveClass('c-node', 'c-shelter', 'c-node--art');
    expect(spot.style.width).not.toBe('');
    expect(spot.style.height).not.toBe('');
});
```

- [ ] **Step 8: Run everything touched, then commit**

```bash
npx vitest run resources/js/patyourself/companion-room.test.tsx resources/js/pages/companion.test.tsx
npx tsc --noEmit
npx prettier --write resources/js/patyourself/companion-room.tsx resources/js/pages/companion.tsx
git add resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx resources/js/pages/companion.tsx resources/js/pages/companion.test.tsx resources/css/patyourself.css
git commit -m "refactor(companion): derive a hotspot's box from the cell it covers"
```

---

## Task 4: The two debts F3.5 left

**Files:**
- Modify: `docs/BLOB.md` §12
- Modify: the three F3.5 files that newly fail `prettier --check`

No test: this is documentation and formatting. The verification is `git diff --stat` staying small and `format:check` improving.

- [ ] **Step 1: Record the reeds' position in `docs/BLOB.md`**

§12 carries a bullet list of coordinates "rendered this session, found wrong, and corrected", holding the trunk's and the shelter's. `[50, 44]` never reached it; `scenes.ts` is the source of truth and is correct. Add a third bullet, in the same voice:

```markdown
  - **`THE REEDS` moved `[44, 36]` → `[50, 44]`.** The shelter's move to `y=34` put its 48-wide cell
    over the reeds' old position, and the clearing's right-hand column is too narrow to separate the
    two sideways — so `y` carries the clearance and always did. `x` moved for a different reason and
    was measured, not computed: pushed toward the right wall the label does not overflow it, it
    **wraps to a second line**, and no box arithmetic on this branch models reflow. Sweeping `x` on
    the rendered page, the label wrapped at 58, 56, 54 and 52 and sat on one line from 50 leftward,
    so 50 is the rightmost value that keeps it whole. F3.6 deletes the label, which retires the
    constraint that decided `x` — `y`'s clearance from the shelter outlives it.
```

- [ ] **Step 2: Find the three files that newly fail formatting**

F3.5 touched seven `.ts`/`.tsx`/`.css` files and all seven fail `prettier --check` today, against 34 across the repo that already did. Establish which three regressed rather than guessing, one command per file:

```bash
git show 62dbe4d~1:resources/js/pages/companion.tsx | npx prettier --check --stdin-filepath resources/js/pages/companion.tsx
```

Repeat for `companion.test.tsx`, `companion-room.tsx`, `companion-room.test.tsx`, `scenes.ts`, `scenes.test.ts`. A file that already failed before `62dbe4d~1` is not this phase's debt and is left alone.

- [ ] **Step 3: Format only those, and never the CSS**

```bash
npx prettier --write <only the files Step 2 identified>
```

`resources/css/patyourself.css` is **excluded whatever Step 2 says about it**: it is hand-formatted, it already failed on main, and `--write` over it produced a 3782-line diff from a 94-line change.

- [ ] **Step 4: Confirm nothing but formatting moved**

Run: `npx vitest run resources/js/pages/companion.test.tsx resources/js/patyourself/companion-room.test.tsx resources/js/patyourself/scenes.test.ts`
Expected: PASS, identical counts.

- [ ] **Step 5: Commit, separately**

Two commits, because a formatting diff buried under a prose change is unreviewable:

```bash
git add docs/BLOB.md
git commit -m "docs(blob): record the reeds' position and why x was measured"
git add <the formatted files>
git commit -m "style(companion): format what F3.5 left unformatted"
```

---

**STOP. Batch boundary.** Report to the owner before Batch 1.

---

# Batch 1 — the art

**These tasks stay in the controller session.** Do not dispatch them to a subagent: `SendUserFile` is not in a subagent's toolset, jobs are ~8-minute polls that a subagent will burn tokens on (one cost 107k), and the candidate judgement needs the owner's eyes.

Read `resources/js/patyourself/scenes/README.md`'s **"Running this pipeline again"** before the first call. It is the runbook and it was written for this batch.

**The traps, restated because each cost a round:**

- `create_1_direction_object` **forces square output**, derived from the largest style image, and `style_images` cannot be combined with `size`. Asked for 48×40 it returns 48×48.
- **Candidates fall as size rises**: 64 at 32px, 16 at 48px. Expect 16.
- **Base64 style arguments truncate** past roughly 1KB. Quantise to ~32 colours with Pillow's `Image.quantize(colors=32)` first — and **quantise the composited reference, never a transparent cutout**, because quantising transparency turns it black and teaches the model to draw a black backing.
- **`sips --cropOffset` takes ROW then COLUMN**, the reverse of how prose writes a position. `PNG col = x + 72`, `PNG row = y + 38`. A room `x` is not a PNG column; that confusion reached a dispatch in F3.5.
- **Judge composited** over the real `forest-day.png`, at the real position, with the real Blob sprite. Never cutouts on transparency. Publish with **`Artifact`**, not `SendUserFile`.
- **A state edit preserves registration but NOT scale.** The generated cabin came back ~17% narrower and was hand-composited over the hut. Expect to composite.
- **The review lifecycle is manual:** `get_object` inspects, `select_object_frames` promotes the chosen candidate, `dismiss_review` discards the rest. An object left in review stays there.

Tasks 5–8 are the same five steps with different subjects. They are separate tasks because the owner gates each node's art independently.

## Task 5: The reeds, three bands

**Files:**
- Create: `resources/js/patyourself/scenes/node-reeds-bare.png`, `node-reeds-some.png`, `node-reeds-plenty.png`
- Modify: `resources/js/patyourself/scenes/README.md`

- [ ] **Step 1: Cut the style crop from the reeds' own patch of ground**

The reeds stand at `[50, 44]`. The 48×48 cell's top-left is `(50 − 24, 44 − 48) = (26, −4)`, which is PNG col `26 + 72 = 98`, row `−4 + 38 = 34`. Row first:

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 34 98 forest-day.png --out /tmp/style-reeds.png
```

Check the crop is the ground the reeds stand on, not sky or treeline, by looking at it. If the cell runs past the image, clamp the offset and say so in the README — do not silently shift it.

- [ ] **Step 2: Quantise the crop**

```bash
python3 -c "from PIL import Image; Image.open('/tmp/style-reeds.png').quantize(colors=32).save('/tmp/style-reeds-q.png')"
```

Confirm the base64 of the result is roughly 1,200 characters, not ~4,850. A call that fails with `broken data stream ... TRUNCATED` means this step did not take.

- [ ] **Step 3: Generate `bare`**

`create_1_direction_object` with `view: sidescroller`, `style_images: [<base64 of the quantised crop>]`, and a description of **a reed bed with nothing gathered from it** — the plant, standing, not a pile of fibre. This is the most-seen sprite of the eleven: all three world nodes sit at `available: 0` from the start and stay there until their skill is bought, so `bare` must read as *the reeds* well enough to be worth clicking.

Expect ~8 minutes and 16 candidates. Start the job and do something else; do not poll in a loop.

- [ ] **Step 4: Judge on composites and promote one**

Composite every candidate over the real `forest-day.png` at PNG col 98, row 34, with the real Blob sprite at its real position. Publish the sheet with `Artifact`. The owner picks. Then `select_object_frames` the chosen one and `dismiss_review` the rest.

- [ ] **Step 5: `create_object_state` for `some`, then `plenty`**

In sequence — `bare` → `some` → `plenty` — so each continues the last. Growth is additive, which is the direction a state edit handles well (an overlay can only add, never remove). After each: check every opaque pixel of the higher band falls inside or beside the lower band's silhouette at the same registration, and **check the width has not shrunk**. If it has, hand-composite the new band over its predecessor rather than re-rolling. Judge each on composites as in Step 4.

- [ ] **Step 6: Crop all three to one box**

Measure the union of the three bands' opaque bounds, then crop all three to that single box. Cropping each to its own bounds loses registration and the pile jumps sideways as it grows.

```bash
python3 - <<'PY'
from PIL import Image
paths = ['node-reeds-bare.png', 'node-reeds-some.png', 'node-reeds-plenty.png']
boxes = [Image.open(p).getbbox() for p in paths]
box = (min(b[0] for b in boxes), min(b[1] for b in boxes),
       max(b[2] for b in boxes), max(b[3] for b in boxes))
print('crop box', box, 'cell', (box[2]-box[0], box[3]-box[1]))
for p in paths:
    Image.open(p).crop(box).save(p)
PY
```

Record the printed cell — Task 11 needs it.

- [ ] **Step 7: Record it in the README and commit**

Add a row per band to a new "The nodes" table in `scenes/README.md`: file, object id, state, candidate, review id. Note the crop box and the resulting cell. **Watch the vocabulary**: that file is scanned, comments included, and a pipeline write-up reaches for `percent` and the `points` substring naturally. Write "share" and "candidates" instead.

```bash
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/scenes/node-reeds-*.png resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): draw the reeds at three standing amounts"
```

---

## Task 6: The fallen branches, three bands

**Files:**
- Create: `resources/js/patyourself/scenes/node-deadfall-{bare,some,plenty}.png`
- Modify: `resources/js/patyourself/scenes/README.md`

Identical to Task 5 in every step; the subject and the crop change. Repeated rather than cross-referenced because a task may be read on its own.

- [ ] **Step 1: Cut and quantise the style crop**

The branches stand at `[-46, 40]`. Cell top-left `(-46 − 24, 40 − 48) = (-70, -8)` → PNG col `-70 + 72 = 2`, row `-8 + 38 = 30`. Row first:

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 30 2 forest-day.png --out /tmp/style-deadfall.png
python3 -c "from PIL import Image; Image.open('/tmp/style-deadfall.png').quantize(colors=32).save('/tmp/style-deadfall-q.png')"
```

This crop sits under the landmark tree, whose own cell runs `x -68..-20`. That is correct — the branches are what came down from it — but check the crop is ground and trunk rather than canopy.

- [ ] **Step 2: Generate `bare`**

`create_1_direction_object`, `view: sidescroller`, the quantised crop as `style_images`. **A few old branches, picked over** — not bare ground. The node stands in the clearing from the start and must be worth clicking at `available: 0`. The `empty` copy says *"Nothing has come down since"*, which is about new fall, not about the branches being gone.

- [ ] **Step 3: Judge on composites, promote, dismiss the rest**

Composite over `forest-day.png` at PNG col 2, row 30, with Blob. `Artifact`, owner picks, `select_object_frames`, `dismiss_review`.

- [ ] **Step 4: `create_object_state` for `some`, then `plenty`**

In sequence. Check registration and width after each; hand-composite over the predecessor if the width shrank.

- [ ] **Step 5: Crop all three to the union box**

```bash
python3 - <<'PY'
from PIL import Image
paths = ['node-deadfall-bare.png', 'node-deadfall-some.png', 'node-deadfall-plenty.png']
boxes = [Image.open(p).getbbox() for p in paths]
box = (min(b[0] for b in boxes), min(b[1] for b in boxes),
       max(b[2] for b in boxes), max(b[3] for b in boxes))
print('crop box', box, 'cell', (box[2]-box[0], box[3]-box[1]))
for p in paths:
    Image.open(p).crop(box).save(p)
PY
```

- [ ] **Step 6: README row and commit**

```bash
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/scenes/node-deadfall-*.png resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): draw the fallen branches at three standing amounts"
```

---

## Task 7: The fallen trunk, three bands

**Files:**
- Create: `resources/js/patyourself/scenes/node-trunk-{bare,some,plenty}.png`
- Modify: `resources/js/patyourself/scenes/README.md`

- [ ] **Step 1: Cut and quantise the style crop**

The trunk stands at `[-40, 48]`. Cell top-left `(-40 − 24, 48 − 48) = (-64, 0)` → PNG col `-64 + 72 = 8`, row `0 + 38 = 38`. Row first:

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 38 8 forest-day.png --out /tmp/style-trunk.png
python3 -c "from PIL import Image; Image.open('/tmp/style-trunk.png').quantize(colors=32).save('/tmp/style-trunk-q.png')"
```

- [ ] **Step 2: Generate `bare`**

**The trunk itself never changes across the three bands — only the loose timber on and beside it does.** That is the invariant this phase adds: the node object never shrinks, only what is standing at it grows. A trunk that visibly got smaller as it was chopped would be the world regressing.

So `bare` is the felled trunk with nothing cut loose. `view: sidescroller`, quantised crop as `style_images`.

- [ ] **Step 3: Judge on composites, promote, dismiss the rest**

Composite at PNG col 8, row 38. This one sits nearest Blob of the four — `scenes.ts` records the trunk's label once overlapped Blob's left arm — so the composite must carry the real Blob sprite and the judgement must include "does it collide".

- [ ] **Step 4: `create_object_state` for `some`, then `plenty`**

Adding cut timber, never shrinking the trunk. Check registration and width; hand-composite if the width shrank.

- [ ] **Step 5: Crop all three to the union box**

```bash
python3 - <<'PY'
from PIL import Image
paths = ['node-trunk-bare.png', 'node-trunk-some.png', 'node-trunk-plenty.png']
boxes = [Image.open(p).getbbox() for p in paths]
box = (min(b[0] for b in boxes), min(b[1] for b in boxes),
       max(b[2] for b in boxes), max(b[3] for b in boxes))
print('crop box', box, 'cell', (box[2]-box[0], box[3]-box[1]))
for p in paths:
    Image.open(p).crop(box).save(p)
PY
```

- [ ] **Step 6: README row and commit**

```bash
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/scenes/node-trunk-*.png resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): draw the fallen trunk at three standing amounts"
```

---

## Task 8: The heap, two bands

**Files:**
- Create: `resources/js/patyourself/scenes/node-salvage-some.png`, `node-salvage-plenty.png`
- Modify: `resources/js/patyourself/scenes/README.md`

**Two bands, not three, and this is the one place the rule bends.** `HarvestNode:112` deletes a skill-less node once drained, and `CompanionBag::nodes():225` skips one with no row — so the heap never reaches the client at zero and can never render at `bare`. A third sprite would be art nothing can draw. `BLOB.md` §10 already rules it: *a node without a skill is a heap, not the world* — absent until something places it, never restocked, deleted once drained.

- [ ] **Step 1: Cut and quantise the style crop**

The heap stands at `[21, 62]`. Cell top-left `(21 − 24, 62 − 48) = (-3, 14)` → PNG col `-3 + 72 = 69`, row `14 + 38 = 52`. Row first:

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 52 69 forest-day.png --out /tmp/style-salvage.png
python3 -c "from PIL import Image; Image.open('/tmp/style-salvage.png').quantize(colors=32).save('/tmp/style-salvage-q.png')"
```

This is the lowest and nearest of the four, sitting in the gap the three grass tufts leave at `x 6..36`. Expect the crop to run to the image's bottom edge; if the 48-row cell overruns, clamp and record it.

- [ ] **Step 2: Generate `plenty` as the base object**

**The heap's base generation is `plenty`, not `bare`** — it is the band the heap is born in. A salvaged cabin is 26 planks, which is `plenty` under `node_bands`, and it only ever goes down from there.

A stack of reclaimed planks and beams, the cabin in pieces. `view: sidescroller`, quantised crop as `style_images`.

- [ ] **Step 3: Judge on composites, promote, dismiss the rest**

Composite at PNG col 69, row 52.

- [ ] **Step 4: `create_object_state` for `some`**

The one state edit in this batch that goes **downward** — a smaller stack. That is the removal direction, which is the direction an overlay cannot do and a state edit is weaker at. If the edit fails to read as fewer planks, generate `some` independently from the same style crop and accept that its registration is not guaranteed; then fix it by hand so both bands share a baseline. Record whichever route was taken in the README.

- [ ] **Step 5: Crop both to the union box**

```bash
python3 - <<'PY'
from PIL import Image
paths = ['node-salvage-some.png', 'node-salvage-plenty.png']
boxes = [Image.open(p).getbbox() for p in paths]
box = (min(b[0] for b in boxes), min(b[1] for b in boxes),
       max(b[2] for b in boxes), max(b[3] for b in boxes))
print('crop box', box, 'cell', (box[2]-box[0], box[3]-box[1]))
for p in paths:
    Image.open(p).crop(box).save(p)
PY
```

- [ ] **Step 6: README row and commit**

The README's "The nodes" section gains a paragraph on why the heap has two bands and the other three have three. That reasoning is the kind a later reader re-derives wrongly.

```bash
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/scenes/node-salvage-*.png resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): draw the heap at two standing amounts"
```

---

**STOP. Batch boundary.** Eleven sprites exist and their cells are measured. Report to the owner with the four cells before Batch 2 uses them.

---

# Batch 2 — the code

## Task 9: `node_bands`, and the server's answer

**Files:**
- Modify: `config/companion.php` (after the `nodes` block, ~line 575)
- Modify: `app/Services/Companion/CompanionBag.php:46-53` (the docblock shape), `:210-246` (`nodes()`), plus a new private method
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Produces: every row of the payload's `nodes` list gains `band: string`, one of the keys of `config('companion.node_bands')`. Tasks 10–13 consume it.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * The picture in the clearing is a band, not a number, so the band is what
     * the payload has to carry. It is computed HERE and not in the client
     * because the thresholds are authored in config and the client cannot read
     * config — the alternative is the numbers existing twice, and two opinions
     * about what "plenty" means will eventually disagree.
     */
    public function test_a_node_carries_the_band_its_amount_falls_in(): void
    {
        $user = $this->richUser();
        app(MeetNode::class)->handle($user, 'reeds');
        app(LearnSkill::class)->handle($user, 'gather-fibre');

        $companion = Companion::query()->where('user_id', $user->id)->sole();

        foreach ([0 => 'bare', 1 => 'some', 4 => 'some', 5 => 'plenty', 300 => 'plenty'] as $available => $band) {
            $companion->nodes()->updateOrCreate(
                ['node' => 'reeds'],
                ['available' => $available],
            );

            $reeds = collect($this->bag($user->fresh()))['nodes'];
            $row = collect($reeds)->firstWhere('node', 'reeds');

            $this->assertSame($band, $row['band'], "at {$available}");
        }
    }

    /**
     * The top threshold is `capacity.base`, and it means "one trip can no
     * longer take it all". Read from config rather than pasted, so moving the
     * threshold moves this case with it and only a wrong DERIVATION fails.
     */
    public function test_the_top_band_begins_at_a_bagful(): void
    {
        $this->assertSame(
            (int) config('companion.capacity.base'),
            (int) config('companion.node_bands.plenty'),
        );
    }

    /**
     * A band the table does not reach resolves to nothing rather than to the
     * lowest one. The client looks the name up in a sprite record, so an empty
     * string draws nothing — which is the safe failure. Guessing `bare` would
     * draw a full reed bed for an amount the author never described.
     */
    public function test_an_amount_below_every_band_names_none(): void
    {
        config()->set('companion.node_bands', ['some' => 1, 'plenty' => 5]);

        $user = $this->richUser();
        app(MeetNode::class)->handle($user, 'reeds');
        app(LearnSkill::class)->handle($user, 'gather-fibre');

        $row = collect($this->bag($user)['nodes'])->firstWhere('node', 'reeds');

        $this->assertSame('', $row['band']);
    }
```

Add `use App\Actions\LearnSkill;` to the file's imports if it is not already there.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=CompanionBagTest`
Expected: FAIL — `Undefined array key "band"`.

- [ ] **Step 3: Author the bands in config**

In `config/companion.php`, immediately after the `nodes` array closes:

```php
    /*
    |--------------------------------------------------------------------------
    | What a node looks like
    |--------------------------------------------------------------------------
    |
    | The clearing draws what is standing at a node rather than printing it, so
    | an amount has to land in one of a few named bands and each band has a
    | sprite. THE NUMBERS LIVE HERE AND THE ART IS SEMANTIC: getting a
    | threshold wrong costs an edit to this array, and getting the art wrong
    | costs a generation and a judgement. Welding them together would price
    | every adjustment at the higher of the two.
    |
    | Read as "the least `available` that reaches this band", ascending, and
    | resolved by taking the LAST band that has begun — the same shape
    | `partOfDay()` uses to let night wrap past midnight without a fifth state
    | describing 3am. One answer in this app to "map a scalar onto named
    | bands" is enough; a second with different edge behaviour would be a
    | second opinion.
    |
    | `plenty` is `capacity.base` and means ONE TRIP CAN NO LONGER TAKE IT
    | ALL — the one threshold a player could infer from play rather than be
    | told. It is deliberately ABSOLUTE rather than a share of the account's
    | own capacity: thresholds that moved with the bag would make the clearing
    | look emptier the moment a crate was built, which is the picture going
    | backwards as a reward for progress.
    |
    | `plenty` is open-ended, so 5 and 300 are drawn the same. That is the
    | intent, not a limit accepted — a pile past a certain size stops reading
    | as a number to the eye too. The exact amount is still carried by the
    | bag's take field and by Blob's own `took` line.
    |
    */

    'node_bands' => [
        'bare' => 0,
        'some' => 1,
        'plenty' => 5,
    ],
```

- [ ] **Step 4: Emit the band**

In `app/Services/Companion/CompanionBag.php`, add to the `$listed[]` array inside `nodes()`, after `'available'`:

```php
                'band' => $this->bandFor((int) ($standingHere?->available ?? 0)),
```

And a new private method after `nodes()`:

```php
    /**
     * Which band an amount falls in.
     *
     * Sorts by the floor and takes the last band that has begun — the same
     * shape `partOfDay()` uses, and the reason night wraps past midnight
     * without a fifth state describing 3am.
     *
     * An amount below every floor names NO band, rather than falling back to
     * the lowest. The client looks the name up in a sprite record and draws
     * nothing for a miss, which is the safe failure; guessing would draw a
     * full node for an amount no author described.
     */
    private function bandFor(int $available): string
    {
        /** @var array<string, int> $bands */
        $bands = (array) config('companion.node_bands', []);

        $named = collect($bands)
            ->sortBy(static fn (int $floor): int => $floor)
            ->filter(static fn (int $floor): bool => $floor <= $available)
            ->keys()
            ->last();

        return is_string($named) ? $named : '';
    }
```

Update the `forUser()` docblock's array shape at `:51`:

```php
     *     nodes: list<array{node: string, label: string, available: int, band: string, skill: string|null, met: bool, known: bool, usable: bool}>,
```

- [ ] **Step 5: Run them and watch them pass**

```bash
php artisan test --compact --filter=CompanionBagTest
vendor/bin/pint --dirty --format agent
```
Expected: PASS.

- [ ] **Step 6: Run the wider PHP companion suite, then commit**

The payload shape is asserted in more than one place — `CompanionScreenTest` and `CompanionClearingTest` both read it.

```bash
php artisan test --compact tests/Feature/Companion
git add config/companion.php app/Services/Companion/CompanionBag.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): band what is standing at a node, in config"
```

---

## Task 10: The band reaches the client

**Files:**
- Modify: `resources/js/patyourself/companion.tsx:54-75` (`BagNodeData`)
- Modify: `resources/js/patyourself/companion.fixture.ts:31-59`
- Test: typecheck, plus the existing suites

**Interfaces:**
- Consumes: `band` from Task 9's payload.
- Produces: `BagNodeData.band: string`, and a fixture whose three node rows carry it.

The `salvage` fixture row is **not** added here. It arrives in Task 12, in the same task that can prove the heap renders — `BLOB.md` §11 trap 9 is about a shared fixture widened without anything asserting the widening took.

- [ ] **Step 1: Add the field to the type**

In `resources/js/patyourself/companion.tsx`, inside `BagNodeData` after `available`:

```ts
    /**
     * Which band the amount falls in — what the clearing actually draws, since
     * the art carries the amount and no number is printed out there.
     *
     * A plain string, not a union of the three names. The bands are authored
     * in `config('companion.node_bands')`, and a union here would be a second
     * declaration of which bands exist, in a language that cannot read the
     * first. `nodeSprite()` resolves a name it does not know to `undefined`
     * and draws nothing, which is the same contract every other registry in
     * this feature follows.
     */
    band: string;
```

- [ ] **Step 2: Watch the fixture fail to typecheck**

Run: `npx tsc --noEmit`
Expected: FAIL — three errors in `companion.fixture.ts`, one per node row, `Property 'band' is missing`. This is the type doing the job the comment describes; confirm all three appear.

- [ ] **Step 3: Widen the fixture**

Add `band: 'bare',` to each of the three node rows in `resources/js/patyourself/companion.fixture.ts`, and extend that list's comment:

```ts
        // All three nodes stand in the clearing from the start, unmet and unusable —
        // the one list the server does NOT filter by what has happened, because
        // a node being there is not a preview of anything.
        //
        // `bare` is the band an unmet node sits in and stays in: nothing stocks
        // a node whose skill has not been bought, so this is what a new record
        // actually sees, for as long as it takes to buy one. A test that wants
        // a node with something at it must set BOTH `available` and `band` —
        // they are the payload's two views of one fact and the server keeps
        // them in step, so a fixture that moves one alone is describing a
        // payload the server cannot send.
```

- [ ] **Step 4: Typecheck clean, run the suites**

```bash
npx tsc --noEmit
npx vitest run resources/js/pages/companion.test.tsx resources/js/patyourself/companion-bag.test.tsx resources/js/patyourself/companion-room.test.tsx
```
Expected: PASS, no count change.

- [ ] **Step 5: Commit**

```bash
npx prettier --write resources/js/patyourself/companion.tsx resources/js/patyourself/companion.fixture.ts
git add resources/js/patyourself/companion.tsx resources/js/patyourself/companion.fixture.ts
git commit -m "feat(companion): carry a node's band to the client"
```

---

## Task 11: The sprite registry and the cells

**Files:**
- Modify: `resources/js/patyourself/scenes.ts`
- Test: `resources/js/patyourself/scenes.test.ts`

**Interfaces:**
- Consumes: the eleven PNGs and four cells from Batch 1.
- Produces: `nodeSprite(node: string, band: string): string | undefined`, `nodeCell(node: string): readonly [number, number] | undefined`, and `NODE_CELLS` / `NODE_SPRITES` exported for the tests.

**Substitute the four real cells measured in Batch 1** wherever this task writes a placeholder pair. They are not guessed here on purpose.

- [ ] **Step 1: Write the failing tests**

Add to `resources/js/patyourself/scenes.test.ts`, importing `NODE_CELLS`, `NODE_SPRITES`, `nodeCell` and `nodeSprite`:

```ts
describe('the node sprites', () => {
    /**
     * Every node the clearing stands somewhere has art for every band the
     * server can send it — except the heap, which has no `bare` because
     * `HarvestNode` deletes a drained heap and `CompanionBag::nodes()` skips a
     * skill-less node with no row. A `bare` heap is a state the system cannot
     * reach, and BLOB.md §12 records what asserting over an unreachable state
     * costs: the `blob` form's sleep row lost its breath to exactly that.
     */
    it('draws every band the server can send, and no band it cannot', () => {
        expect(Object.keys(NODE_SPRITES.reeds).sort()).toEqual(['bare', 'plenty', 'some']);
        expect(Object.keys(NODE_SPRITES.deadfall).sort()).toEqual(['bare', 'plenty', 'some']);
        expect(Object.keys(NODE_SPRITES.trunk).sort()).toEqual(['bare', 'plenty', 'some']);
        expect(Object.keys(NODE_SPRITES.salvage).sort()).toEqual(['plenty', 'some']);
    });

    /**
     * Every node `SCENES.forest` stands somewhere has both a sprite record and
     * a cell. A node with a place to stand and no art is an invisible control
     * over nothing — the defect F3.5 fixed in 15e893a for a retired shelter
     * stage, arriving from the other direction.
     */
    it('gives every node in the clearing art and a cell', () => {
        for (const spec of SCENES.forest.nodes) {
            expect(Object.hasOwn(NODE_SPRITES, spec.node)).toBe(true);
            expect(nodeCell(spec.node)).toBeDefined();
        }
    });

    /**
     * ALL BANDS OF ONE NODE SHARE ONE CROP BOX. Cropping each band to its own
     * bounds loses registration and the pile jumps sideways as it grows —
     * which is the failure the shelter's shared registration exists to
     * prevent, and why `shelter-cabin.png` and `shelter-hut.png` have
     * byte-identical alpha channels.
     *
     * The mutation that turns this red: re-crop one band to its own bbox.
     */
    it('draws every band of a node on one cell', () => {
        for (const [node, bands] of Object.entries(NODE_SPRITES)) {
            const cell = nodeCell(node)!;

            for (const [band, sheet] of Object.entries(bands)) {
                const { width, height } = sheetSize(sheet);

                expect(`${node}/${band}: ${width}x${height}`).toBe(
                    `${node}/${band}: ${cell[0]}x${cell[1]}`,
                );
            }
        }
    });

    /**
     * Which file, not just that one exists. The key set, the PNG headers
     * (identical within a node by construction) and `data-band` (which echoes
     * the name, not the file) all stay green if `bare` and `plenty` swap
     * sheets — the clearing would then empty as you logged and fill as you
     * harvested, the mechanic running backwards. The Vite-resolved URL is the
     * one thing carrying the filename.
     */
    it('draws the band it names, not a different one wearing its key', () => {
        expect(nodeSprite('reeds', 'bare')).toContain('node-reeds-bare');
        expect(nodeSprite('reeds', 'plenty')).toContain('node-reeds-plenty');
        expect(nodeSprite('salvage', 'some')).toContain('node-salvage-some');
    });

    /**
     * Both levels of the lookup, because both are records keyed by a string
     * that arrives from outside. `constructor` resolves to a truthy function
     * through the prototype chain on a bare lookup, and BLOB.md §12 records
     * that ROOM_OBJECTS and SPRITE_ITEMS still carry exactly that bug.
     */
    it('resolves neither a node nor a band it has no sprite for', () => {
        expect(nodeSprite('constructor', 'bare')).toBeUndefined();
        expect(nodeSprite('reeds', 'constructor')).toBeUndefined();
        expect(nodeSprite('reeds', '')).toBeUndefined();
        expect(nodeSprite('swamp', 'bare')).toBeUndefined();
        expect(nodeCell('constructor')).toBeUndefined();
    });

    /**
     * The heap has no `bare` sprite, so a heap that somehow reached the client
     * at zero draws nothing rather than throwing or drawing a full stack.
     */
    it('draws nothing for a heap at no amount at all', () => {
        expect(nodeSprite('salvage', 'bare')).toBeUndefined();
    });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/patyourself/scenes.test.ts -t "node sprites"`
Expected: FAIL — `nodeSprite is not defined`.

- [ ] **Step 3: Implement the registry**

In `resources/js/patyourself/scenes.ts`, add the eleven imports beside the shelter's three, then after `shelterSprite`:

```ts
/**
 * The cell each node's art is drawn on, in the room's own units.
 *
 * Per-node and not square, following `FoliageSpec.cell` rather than
 * `SHELTER_CELL`: the sprites are GENERATED at 48x48 because
 * `create_1_direction_object` forces a square derived from its style image,
 * and then cropped to the art's real bounds before they ship. Four 48-wide
 * cells plus the shelter's 48 need 240 units of a 144-unit room, so square
 * cells were never going to stand in this clearing at once.
 *
 * Every band of one node shares one cell, because all of them were cropped to
 * one box — the union of their bounds. Cropping each to its own would lose
 * registration and the pile would jump sideways as it grew.
 */
export const NODE_CELLS: Record<string, readonly [number, number]> = {
    reeds: [<w>, <h>],
    deadfall: [<w>, <h>],
    trunk: [<w>, <h>],
    salvage: [<w>, <h>],
};

/**
 * Node, then band, to the art.
 *
 * THE HEAP HAS NO `bare`. A node without a skill is a heap rather than the
 * world (BLOB.md §10): it is absent until something places it, nothing
 * restocks it, and `HarvestNode` deletes it once drained — so
 * `CompanionBag::nodes()` never sends one at zero and a `bare` heap is a state
 * the system cannot reach. Drawing one would be art for a state nothing can
 * produce, and BLOB.md §12 records what guarding an unreachable state costs.
 */
const NODE_SPRITES: Record<string, Record<string, string>> = {
    reeds: { bare: nodeReedsBare, some: nodeReedsSome, plenty: nodeReedsPlenty },
    deadfall: { bare: nodeDeadfallBare, some: nodeDeadfallSome, plenty: nodeDeadfallPlenty },
    trunk: { bare: nodeTrunkBare, some: nodeTrunkSome, plenty: nodeTrunkPlenty },
    salvage: { some: nodeSalvageSome, plenty: nodeSalvagePlenty },
};

/**
 * The art for one node at one band, or nothing.
 *
 * `Object.hasOwn` at BOTH levels: each is a record keyed by a string that came
 * from the server, and a bare lookup resolves `'constructor'` to a truthy
 * function through the prototype chain — which would then be handed to
 * `<image href>`. BLOB.md §12 records that `ROOM_OBJECTS` and `SPRITE_ITEMS`
 * still carry that bug and only this file was fixed.
 */
export function nodeSprite(node: string, band: string): string | undefined {
    if (!Object.hasOwn(NODE_SPRITES, node)) {
        return undefined;
    }

    const bands = NODE_SPRITES[node];

    return Object.hasOwn(bands, band) ? bands[band] : undefined;
}

/** The cell a node's art is drawn on, or nothing. */
export function nodeCell(node: string): readonly [number, number] | undefined {
    return Object.hasOwn(NODE_CELLS, node) ? NODE_CELLS[node] : undefined;
}

export { NODE_SPRITES };
```

Then change `NodeSpec.at`'s docblock, which now describes the wrong thing:

```ts
export interface NodeSpec {
    /** Matches the key in `config('companion.nodes')`. */
    node: string;
    /**
     * Where the thing STANDS — its base centre, in the room's own coordinates.
     *
     * This was the hotspot's centre while a node was a label, and F3.6 changed
     * it along with the label, the same way `SceneSpec.shelter` changed in
     * F3.5 and for the same reason: foliage is a cell placed on a grid, and a
     * node, like a structure, stands somewhere. The art's top-left derives as
     * `(x − w/2, y − h)` from `NODE_CELLS`.
     */
    at: readonly [number, number];
}
```

Delete the `F1 SHIPS THESE AS LABELLED HOTSPOTS` paragraph above the interface — it is now false, and a comment that contradicts its own code has cost this project a round twice.

- [ ] **Step 4: Run them and watch them pass**

Run: `npx vitest run resources/js/patyourself/scenes.test.ts`
Expected: PASS, including the six new cases.

- [ ] **Step 5: Commit**

```bash
npx tsc --noEmit
npx prettier --write resources/js/patyourself/scenes.ts resources/js/patyourself/scenes.test.ts
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/scenes.ts resources/js/patyourself/scenes.test.ts
git commit -m "feat(companion): register the nodes' art, by node and by band"
```

---

## Task 12: `NodeLayer`

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx` (a `nodes` prop; `NodeLayer` beside `ShelterLayer` at :258)
- Modify: `resources/js/pages/companion.tsx` (pass `nodes={bag.nodes}`)
- Modify: `resources/js/patyourself/companion.fixture.ts` (the `salvage` row — **this task, per trap 9**)
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `nodeSprite`, `nodeCell` from Task 11; `BagNodeData.band` from Task 10.
- Produces: `CompanionRoom` accepts `nodes?: readonly { node: string; band: string }[]`, defaulting to `[]`. Each drawn node emits `data-node` and `data-band`.

- [ ] **Step 1: Write the failing tests**

Add to `resources/js/patyourself/companion-room.test.tsx`:

```tsx
describe('the nodes in the clearing', () => {
    const standing = (band: string) =>
        SCENES.forest.nodes.map((spec) => ({ node: spec.node, band }));

    /**
     * One sprite per node and exactly one. The "exactly one" half is the
     * clearing-side twin of the gap F3's final review found indoors, where
     * only the lean-to's interior was guarded and the hut's and the cabin's
     * could both draw with the whole suite green.
     *
     * The mutation that turns this red: render every band instead of the one
     * the payload names.
     */
    it('draws the band the payload names, and only that one', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ scene: 'forest' })}
                animation="idle"
                frame={0}
                hour={12}
                nodes={[{ node: 'reeds', band: 'plenty' }]}
            />,
        );

        const drawn = container.querySelectorAll('[data-node="reeds"]');

        expect(drawn).toHaveLength(1);
        expect(drawn[0]).toHaveAttribute('data-band', 'plenty');
    });

    /**
     * A node the payload does not carry is not drawn. This is how the heap
     * disappears when it is drained: `HarvestNode` deletes the row, so
     * `CompanionBag::nodes()` stops sending it, even though `scenes.ts` still
     * knows where one would stand.
     */
    it('draws nothing for a node the payload does not carry', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ scene: 'forest' })}
                animation="idle"
                frame={0}
                hour={12}
                nodes={[]}
            />,
        );

        expect(container.querySelectorAll('[data-node]')).toHaveLength(0);
    });

    /**
     * Nothing grows indoors. "Where Blob is" and "what Blob has" are separate
     * facts, and F3 §1 exists to keep them apart.
     */
    it('draws no node indoors', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ scene: 'forest' })}
                animation="idle"
                frame={0}
                hour={12}
                inside
                nodes={standing('plenty')}
            />,
        );

        expect(container.querySelectorAll('[data-node]')).toHaveLength(0);
    });

    /**
     * `at` is the base centre now, so the cell hangs UP and LEFT of it — the
     * same contract `ShelterLayer` follows. Derived from `NODE_CELLS` rather
     * than pasted, so a deliberate coordinate change survives and only a wrong
     * derivation fails.
     *
     * The mutation that turns this red: drop the `- height` and draw from the
     * point downward, which would plant every node's art below the ground it
     * stands on.
     */
    it('hangs a node cell above the point it stands on', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ scene: 'forest' })}
                animation="idle"
                frame={0}
                hour={12}
                nodes={[{ node: 'reeds', band: 'bare' }]}
            />,
        );

        const spec = SCENES.forest.nodes.find((node) => node.node === 'reeds')!;
        const [width, height] = nodeCell('reeds')!;
        const art = container.querySelector('[data-node="reeds"]')!;

        expect(art.getAttribute('x')).toBe(String(spec.at[0] - width / 2));
        expect(art.getAttribute('y')).toBe(String(spec.at[1] - height));
    });

    /**
     * A band with no art draws nothing rather than throwing — a heap at zero
     * is the real case, and it can only arrive through a payload the server
     * does not send, so the guard is cheap insurance rather than a live path.
     */
    it('draws nothing for a band it has no art for', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ scene: 'forest' })}
                animation="idle"
                frame={0}
                hour={12}
                nodes={[{ node: 'salvage', band: 'bare' }]}
            />,
        );

        expect(container.querySelectorAll('[data-node]')).toHaveLength(0);
    });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx -t "nodes in the clearing"`
Expected: FAIL — five cases, all finding zero `[data-node]` elements.

- [ ] **Step 3: Implement `NodeLayer` and the prop**

In `resources/js/patyourself/companion-room.tsx`, import `nodeCell` and `nodeSprite` from `./scenes`, and add after `ShelterLayer`:

```tsx
/**
 * What is standing at one node, at the amount it is standing in.
 *
 * The generalisation of `ShelterLayer` above, and a plain `<image>` for the
 * same reason: the tree and the grass are sheets read by the one shared clock
 * because they exist to carry wind, and a pile of timber does not sway. One
 * frame, no clock, no phase.
 *
 * `band` decides which of a node's sprites is drawn, and the band itself is
 * the server's answer — the thresholds are authored in
 * `config('companion.node_bands')` and are deliberately nowhere in this
 * language.
 */
function NodeLayer({
    node,
    band,
    at,
}: {
    node: string;
    band: string;
    at: readonly [number, number];
}) {
    const sprite = nodeSprite(node, band);
    const cell = nodeCell(node);

    if (sprite === undefined || cell === undefined) {
        return null;
    }

    const [width, height] = cell;

    return (
        <image
            data-node={node}
            data-band={band}
            href={sprite}
            // `at` is the base centre, so the cell hangs up and left of it —
            // the same contract `ShelterLayer` follows.
            x={at[0] - width / 2}
            y={at[1] - height}
            width={width}
            height={height}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}
```

Add the prop to `CompanionRoom`'s signature and defaults:

```tsx
    nodes = [],
```
```tsx
    /**
     * What is standing at each node, by name and band. Placement is
     * `scenes.ts`'s; WHICH nodes are there and how much is at them is the
     * server's, exactly as `shelter` above is.
     *
     * Structurally satisfied by `BagNodeData`, so the page passes `bag.nodes`
     * straight through rather than mapping it into a new array on every
     * render.
     */
    nodes?: readonly { node: string; band: string }[];
```

And render, inside the `!indoors` block, **after** `ShelterLayer`:

```tsx
                    {/* After the shelter and over it: the building stands at
                        the treeline and the nodes are nearer the front, so
                        painting them later is what puts them in front.

                        Still under Blob and under the wash. The sprites are
                        drawn in neutral light, so a layer that escaped the
                        overlay would stay at noon all night. */}
                    {scene.nodes.map((spec) => {
                        const standing = nodes.find(
                            (candidate) => candidate.node === spec.node,
                        );

                        if (standing === undefined) {
                            return null;
                        }

                        return (
                            <NodeLayer
                                key={spec.node}
                                node={spec.node}
                                band={standing.band}
                                at={spec.at}
                            />
                        );
                    })}
```

In `resources/js/pages/companion.tsx`, pass it to `CompanionRoom`:

```tsx
                    shelter={bag.shelter.built}
                    nodes={bag.nodes}
```

- [ ] **Step 4: Widen the shared fixture — the heap, in this task**

`companion.fixture.ts`'s node list has **no `salvage` row at all**, so the heap renders in no test in the suite. Add it, after `trunk`:

```ts
            // The heap: absent from most clearings, and absent from this
            // fixture until F3.6 drew it. A node without a skill is not the
            // world — it is there only because something put it there, nothing
            // restocks it, and `HarvestNode` deletes it once drained — so
            // `skill` is null, `known` is true with nothing to learn, and there
            // is no `bare` band it can ever be in.
            //
            // Present with `available: 0` and no band on purpose: that is the
            // shape a test gets by default, and a test about the heap has to
            // say what is in it. The DEFAULT here must not draw a heap, or
            // every clearing case in the suite silently gains a fifth object.
            {
                node: 'salvage',
                label: 'the heap',
                available: 0,
                band: '',
                skill: null,
                met: true,
                known: true,
                usable: true,
            },
```

- [ ] **Step 5: Prove the widening took, by mutation**

Add the case that would have caught trap 9 the three times it landed. Note what it asserts against: passing `nodes={[...]}` by hand proves nothing about the **fixture**, which is the thing that was missing a `salvage` row — so this reads the fixture and then renders what the fixture gives:

```tsx
/**
 * BLOB.md §11 trap 9: a shared fixture widened without anything asserting the
 * widening took does not fail a guard, it silently drops out of what the guard
 * covers. Three sections were added to this surface without one.
 *
 * Reads `bag()` rather than a hand-written list on purpose. A test that
 * supplies its own `nodes` array proves the COMPONENT can draw a heap and
 * proves nothing about whether any other test in the suite ever sees one —
 * and that second thing is what was actually missing.
 *
 * The mutation that turns this red: delete the `salvage` row from the fixture.
 */
it('gives the whole suite a heap to reach', () => {
    const fixture = bag();
    const heap = fixture.nodes.find((node) => node.node === 'salvage');

    expect(heap).toBeDefined();

    const { container } = render(
        <CompanionRoom
            companion={companion({ scene: 'forest' })}
            animation="idle"
            frame={0}
            hour={12}
            nodes={fixture.nodes.map((node) =>
                node.node === 'salvage' ? { ...node, band: 'plenty' } : node,
            )}
        />,
    );

    expect(container.querySelector('[data-node="salvage"]')).toHaveAttribute(
        'data-band',
        'plenty',
    );
});
```

Import `bag` alongside `companion` from `./companion.fixture`.

Then **actually run the mutation**, twice: delete the `salvage` fixture row and confirm this case goes red; then set `nodeSprite` to return `undefined` and confirm the five band cases go red. Revert both. A test you have not seen fail is a test you have not written.

- [ ] **Step 6: Run everything and commit**

```bash
npx vitest run resources/js/patyourself/companion-room.test.tsx resources/js/pages/companion.test.tsx
npx tsc --noEmit
npx prettier --write resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx resources/js/patyourself/companion.fixture.ts resources/js/pages/companion.tsx
php artisan test --compact --filter=CompanionVocabularyTest
git add resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx resources/js/patyourself/companion.fixture.ts resources/js/pages/companion.tsx
git commit -m "feat(companion): draw what is standing at each node"
```

---

## Task 13: `NodeSpot` becomes a control over the art

**Files:**
- Modify: `resources/js/pages/companion.tsx:336-356` (the caller), `:498-543` (`NodeSpot`)
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `roomSize` (Task 3), `nodeCell` (Task 11), `renderClearing` (Task 1), the `recoverFocus` ref (Task 2).
- Produces: the finished clearing. No text outdoors.

**Three changes, and two of them are removals:**

1. The text goes. The art says what is standing there.
2. `is-known` goes with it. This **restores** a rule rather than dropping one — `NodeSpot`'s own docblock says *"A node you cannot use looks exactly like one you can, because that is the whole mechanic"*, and the `is-known` border quietly contradicts it today. Putting it on the art instead would be a fact about the record entering the picture of the world.
3. The `aria-label` keeps the **exact number**, unchanged. F3.5 wrote the reasoning into the code for this moment: an explicit `aria-label` overrides the subtree, *"so the count has to be folded into it by hand or a sighted user keeps seeing the number while a screen reader stops hearing it."* Replacing it with the band word is that regression pointed the other way.

- [ ] **Step 1: Write the failing tests**

```tsx
describe('the clearing has no words in it', () => {
    /**
     * The phase's own acceptance criterion. Every node's name was printed in
     * the clearing at a fixed 8px font that did not scale with the svg, which
     * is the label-sizing problem BLOB.md §12 has carried since F1. It is
     * retired by deletion, not by a font rule.
     *
     * The mutation that turns this red: put `{label}` back in the button.
     */
    it('prints no node name over the picture', () => {
        renderClearing();

        expect(screen.queryByText('the reeds')).toBeNull();
        expect(screen.queryByText('the fallen branches')).toBeNull();
        expect(screen.queryByText('the fallen trunk')).toBeNull();
    });

    /**
     * And no amount either — the art carries it. A digit over the picture is
     * the number this phase exists to stop printing.
     *
     * The mutation that turns this red: restore the `<i>{available}</i>`.
     */
    it('prints no amount over the picture', () => {
        renderClearing({
            bag: {
                nodes: [
                    {
                        node: 'reeds',
                        label: 'the reeds',
                        available: 27,
                        band: 'plenty',
                        skill: 'gather-fibre',
                        met: true,
                        known: true,
                        usable: true,
                    },
                ],
            },
        });

        expect(screen.queryByText('27')).toBeNull();
    });

    /**
     * What a screen reader hears is the one thing that does NOT change. The
     * picture is a band; the name is the number behind it, which AT users have
     * today and must not lose to a redraw they cannot see.
     *
     * The mutation that turns this red: drop `, ${available}` from the label,
     * or swap it for the band word.
     */
    it('still tells a screen reader exactly how much is standing', () => {
        renderClearing({
            bag: {
                nodes: [
                    {
                        node: 'reeds',
                        label: 'the reeds',
                        available: 27,
                        band: 'plenty',
                        skill: 'gather-fibre',
                        met: true,
                        known: true,
                        usable: true,
                    },
                ],
            },
        });

        expect(
            screen.getByRole('button', { name: 'the reeds, 27' }),
        ).toBeInTheDocument();
    });

    /**
     * A node with nothing at it is named without a number. A zero would be a
     * count of what you have not got — the rule the old `available > 0` guard
     * on the printed count already followed, carried over to the name.
     */
    it('names an empty node without a number', () => {
        renderClearing();

        expect(
            screen.getByRole('button', { name: 'the reeds' }),
        ).toBeInTheDocument();
    });

    /**
     * The control is sized to the art it covers, so it scales with the stage
     * rather than being right at one width. `toHaveClass`, not `toContain`,
     * because a template literal that eats its leading space ships
     * `class="c-nodec-node--art"` and a substring check passes on that.
     *
     * The mutation that turns this red: drop `roomSize(...)` from the style.
     */
    it('sizes each node control to its own art', () => {
        renderClearing();

        const spot = screen.getByRole('button', { name: 'the reeds' });

        expect(spot).toHaveClass('c-node', 'c-node--art');
        expect(spot).not.toHaveClass('is-known');
        expect(spot.style.width).not.toBe('');
    });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "no words in it"`
Expected: FAIL — the first two find the text, the last finds `is-known` or an empty width.

- [ ] **Step 3: Rewrite `NodeSpot`**

```tsx
/**
 * One thing standing in the clearing, as a control over its own picture.
 *
 * A real `<button>` laid over the svg rather than a shape inside it, so it is
 * focusable, announced, and reachable without a mouse — the same rule
 * `ShelterSpot` follows and the reason neither is a `<foreignObject>`.
 *
 * IT CARRIES NO TEXT. The art says what is standing there and how much of it,
 * so printing the name again would be this button narrating the picture. That
 * also retires the label-sizing problem BLOB.md §12 has carried since F1: the
 * labels were a fixed 8px font that did not scale with the svg, so below
 * roughly a 300px stage they wrapped to a second line. The box is measured in
 * room units now and scales with the picture.
 *
 * NO `is-known` EITHER, and that RESTORES a rule rather than dropping one: a
 * node you cannot use is supposed to look exactly like one you can, because
 * that sameness IS the mechanic, and the border this used to grow when a skill
 * was bought quietly contradicted it. Putting the distinction on the art
 * instead would be worse — a fact about the record drawn into the world.
 *
 * `aria-label` carries the one thing the picture cannot: the exact amount. It
 * overrides the subtree entirely, so the number has to be folded in by hand —
 * otherwise deleting the text takes it away from a screen reader while leaving
 * it in the picture for everyone else.
 */
function NodeSpot({
    label,
    available,
    cell,
    at,
    onClick,
}: {
    label: string;
    available: number;
    cell: readonly [number, number];
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-node--art')}
            style={{ ...at, ...roomSize(cell[0], cell[1]) }}
            // Only once there is something to take. A zero would be a count of
            // what you have not got.
            aria-label={available > 0 ? `${label}, ${available}` : label}
            onClick={onClick}
        />
    );
}
```

- [ ] **Step 4: Rewrite the caller**

```tsx
                {!inside &&
                    sceneFor(companion.scene).nodes.map((spec) => {
                        const node = bag.nodes.find(
                            (candidate) => candidate.node === spec.node,
                        );
                        const cell = nodeCell(spec.node);

                        // Gated on the cell resolving so this control agrees
                        // with `NodeLayer`, which draws nothing without one.
                        // Without this, a node whose art was retired leaves an
                        // invisible, unlabelled hit region standing in the
                        // clearing — the defect 15e893a fixed for the shelter.
                        if (node === undefined || cell === undefined) {
                            return null;
                        }

                        return (
                            <NodeSpot
                                key={spec.node}
                                label={node.label}
                                available={node.available}
                                cell={cell}
                                // The art's centre, not the base centre `at`
                                // names: `.c-node` is translated by -50%,-50%,
                                // and the art sits above the point the thing
                                // stands on rather than around it.
                                at={roomOffset(spec.at[0], spec.at[1] - cell[1] / 2)}
                                onClick={() => {
                                    // A drained heap is deleted, so this
                                    // control can remove itself — the same
                                    // shape `ShelterSpot` has.
                                    recoverFocus.current = true;
                                    onTouchNode(spec.node);
                                }}
                            />
                        );
                    })}
```

- [ ] **Step 5: Run them and watch them pass**

Run: `npx vitest run resources/js/pages/companion.test.tsx -t "no words in it"`
Expected: PASS, 5 cases.

- [ ] **Step 6: Widen BOTH acceptance-guard runs, and prove it by mutation**

`companion.test.tsx`'s *"never shows what has not happened"* runs twice — bag closed and bag open. Both must actually render node art now, or the newest surface in the feature sits outside the acceptance criterion, which is the exact shape of trap 9's three previous landings.

Give both runs a node with something standing at it. **Map over the fixture's rows rather than replacing the array** — an override that supplies one node silently drops the other three out of the guard, which is trap 9 committed while fixing trap 9:

```tsx
                bag={bag({
                    shelter: { built: 'hut', label: 'hut', offer: null },
                    nodes: bag().nodes.map((node) => {
                        if (node.node === 'salvage') {
                            return node;
                        }

                        return node.node === 'reeds'
                            ? { ...node, available: 27, band: 'plenty', known: true }
                            : { ...node, available: 3, band: 'some', known: true };
                    }),
                })}
```

The heap is passed through untouched, so it keeps the fixture's `band: ''` and stays undrawn — which is the right clearing for this guard to cover, since an account that never had a cabin has no heap. The other three carry both a `plenty` and a `some` between them, so the guard sees each band drawn at least once.

Then prove the guard actually covers it: temporarily make `NodeSpot` render `{available} of {bag.nodes.length}` as visible text, confirm **both** runs go red, and revert. Rendering `1 of 4` as visible text left 38/38 green in F3.5 — that is what this step exists to stop repeating.

- [ ] **Step 7: Full suite, then commit**

```bash
npx vitest run
npx tsc --noEmit
npx eslint resources/js/pages/companion.tsx --fix
npx prettier --write resources/js/pages/companion.tsx resources/js/pages/companion.test.tsx
php artisan test --compact tests/Feature/Companion
git add resources/js/pages/companion.tsx resources/js/pages/companion.test.tsx
git commit -m "feat(companion): make each node a control over its own picture"
```

---

**STOP. Batch boundary.** Report to the owner. Nothing about whether it LOOKS right has been established yet.

---

# Batch 3 — look at it

## Task 14: Render, propose, and let the owner place them

**Files:**
- Modify: `resources/js/patyourself/scenes.ts` (four coordinates, and the comment above each), **only after the owner decides**
- Modify: `docs/BLOB.md` §7 and §12

**jsdom has no layout engine and nothing in Batch 2 proved this looks right.** Four objects, a shelter and Blob now share a 144-unit clearing whose right-hand column is already narrow, and the positions in `scenes.ts` were chosen for *label boxes* that no longer exist.

**Placement is the owner's, not the render's.** `BLOB.md` §10 and F3's Task 18 ruling: a coordinate changed on a screenshot without the owner is the mistake, and the render only informs the proposal.

- [ ] **Step 1: Build and serve**

Herd never serves a worktree.

```bash
npm run build
php -S 127.0.0.1:8899 -t public
```

- [ ] **Step 2: Render the clearing at two widths and at two amounts**

Use the test account (`test@example.com`: 35 logs, ~8 insights, a 26-plank heap). Capture a desktop width and roughly 390px, each with the nodes at `bare` and at `plenty`, so the growth is visible rather than inferred. `data-band` is trustworthy under automation; `data-frame` is not — the sprite clock stops on `document.hidden`, so it reads 0 forever.

- [ ] **Step 3: Measure what the render shows, and say which Blob**

For each node, check: does the art overlap Blob, another node, the shelter's cell, or a grass tuft; does the cell stay inside the room's walls at `x ±72`; and does it still read as standing on the ground rather than floating.

**Any clearance measured against "Blob" must name which renderer.** `blob-renderer.tsx` declares `BODY.w = 44`, so the **vector** body spans ±22; the **sprite** renderer — `config('companion.renderer')`'s default, and so what ships — draws a visibly narrower silhouette at roughly ±15. The shelter's own `x=44` clears one and not the other, and `scenes.ts` says so candidly. Do the same here.

- [ ] **Step 4: Publish the renders and propose**

`Artifact`, with the measurements beside each image. Propose coordinates; do not apply them.

**Beware of three inferences this project has got wrong from correct measurements** — the ground line, the reeds' failure mode, and a point mistaken for its box. A measured number is not a conclusion.

- [ ] **Step 5: Apply what the owner chooses, and rewrite the comments with the numbers**

Every coordinate in `scenes.ts` carries a long comment arguing for it, and every one of those arguments is now about a label box that does not exist. Rewrite each to record **the render**, not the arithmetic — that is the standard `SceneSpec.shelter`'s comment set in F3.5.

```bash
npx vitest run resources/js/patyourself/scenes.test.ts
```

`scenes.test.ts`'s "keeps every node clear of the shelter's own cell" derives from `SCENES.forest.shelter` and `SHELTER_CELL`, so it survives a deliberate move and fails only on a wrong one.

- [ ] **Step 6: Update `docs/BLOB.md`**

- **§7** gains the fifth run of the fourth pipeline: eleven sprites, generated square at 48 and cropped to per-node cells, one crop box per node. Two or three sentences, with the detail left in `scenes/README.md`.
- **§12** retires *"Node hotspot labels do not scale with the stage"* and the trunk's width-limited residual with it — both were about a fixed 8px font that no longer exists outdoors. Say what replaced them: a box in room units, with a 24px floor.
- **§12** gains whatever the render corrected, in the voice the existing coordinate bullets use.
- **§2's list of source files** is unchanged: no new companion source file shipped. `companion.harness.tsx` is test support, and no test-support file is on that list.
- **`docs/BLOB.md` stays off `CompanionVocabularyTest::sourceFiles()`.**

- [ ] **Step 7: Final run and commit**

```bash
npm run test
php artisan test --compact
npx tsc --noEmit
git add resources/js/patyourself/scenes.ts docs/BLOB.md
git commit -m "feat(companion): stand the four nodes where the render puts them"
```

---

## Self-review notes

**Spec coverage.** §2 the band model → Tasks 9, 10. §3 cells and `NodeSpec.at` → Tasks 5–8, 11. §4 the pipeline → Tasks 5–8. §5 the code — server band → 9; registry → 11; layer → 12; hotspot sizing → 3; accessible name → 13; `is-known` → 13; focus → 2. §6 the seam → Tasks 1, 4. §7 testing — the mutation discipline is in every task; the fixture widening is split deliberately (`band` in 10 where the type forces it, `salvage` in 12 where it can be proved). §8 batches → the four batch headings.

**Known gap, stated rather than hidden.** `NODE_CELLS`'s four pairs are written as `[<w>, <h>]` in Task 11. They are measured in Batch 1, Step 6 of each art task, and cannot honestly be written before then — recording invented numbers here is the stale-value trap this branch has paid for six times. Task 11 will not typecheck until they are substituted, which is the intended forcing function.
