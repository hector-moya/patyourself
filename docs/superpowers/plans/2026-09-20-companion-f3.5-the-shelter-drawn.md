# F3.5 — the shelter, drawn: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the shelter's text label with three drawn structures standing in the forest clearing, so the thing Blob built is visible.

**Architecture:** Three single-frame 48×48 PNGs, generated as one Pixel Lab *object* and two state-edits of it so the footprint is preserved structurally. A static `<image>` layer in `CompanionRoom` draws the standing stage over the backdrop and under Blob, inheriting the light wash. `ShelterSpot` stops being the art and becomes a transparent, accessibly-named control sized to it.

**Tech Stack:** React 19, Inertia v3, Vitest + Testing Library, TypeScript, Pixel Lab MCP, `sips` (macOS, for crops).

**Spec:** `docs/superpowers/specs/2026-09-20-companion-f3.5-the-shelter-drawn-design.md`

## Global Constraints

- **Never run `npm run lint` from the repo root.** It descends into `.claude/worktrees/` and auto-rewrites files — 118 silently rewritten once. Use `npx vitest run` and `npx tsc --noEmit`.
- **Never run `prettier --write`** on anything. Both CSS files and two test files are hand-formatted and already fail `format:check` on main.
- **Never use bare `git stash` / `git stash pop`** — the stash stack is shared across worktrees.
- **Any new companion source file must join `CompanionVocabularyTest::sourceFiles()`.** A file absent from that list is scanned by nothing. `docs/BLOB.md` is deliberately absent and must stay absent.
- **`points` is a substring trap** in that scanner — `appoints`, `disappoints`, and SVG's `<polygon points=…>`, which fired for real during F3. Use `<path d="…">`.
- **Anchor asserted rows by name, never by index.**
- **`cn()` for classNames, never template literals**; assert with `toHaveClass`, because `toContain` passes on a mangled class.
- **jsdom has no layout engine.** It proves what a component emits, never that two things do not overlap.
- **Copy rules:** never show a total, a completion percentage, or an end state. Never state a plan. Copy says what Blob did, never how the person is doing.
- **Room coordinates:** viewBox is `-72 -38 144 114`. PNG col = x + 72, PNG row = y + 38. Ground line is PNG row 62 → room y 24. `FLOOR` is 52.
- **Measure against Blob with the vector body's ±22** (`BODY = { w: 44 }` in `blob-renderer.tsx`), not the sprite's ~±15. It is the conservative bound and a declared constant.

---

# BATCH 1 — the art

Art first, unlike F1–F3. The phase's whole risk is here, and Batch 2's tests assert against real files.

**Two tasks in this batch stop for the owner.** Choosing the candidate is theirs, exactly as the tree's candidate 8 was chosen by the owner from a composite. Do not pick one and proceed.

---

### Task 1: Generate the lean-to, and present the candidates

**Files:**
- Create (scratch, not committed): one 48×48 style crop in your scratch directory.

**Interfaces:**
- Produces: a Pixel Lab `object_id` for the lean-to, and the owner's chosen candidate index. Task 2 consumes both.

- [ ] **Step 1: Make the style crop**

`create_1_direction_object` takes `style_images` as base64 and **cannot be combined with `size`** — the largest style image determines the output size. A 48×48 crop therefore fixes the output at 48×48 *and* carries the clearing's palette. The tool **forces the output square** — asked for 48×40 it returns 48×48.

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 14 92 forest-day.png --out /tmp/style-spot.png
sips -g pixelWidth -g pixelHeight /tmp/style-spot.png
```

Expected: `pixelWidth: 48`, `pixelHeight: 48`.

The offset is verified: PNG col 92, row 14 is the patch the building actually stands in. **PNG col = x + 72**, so the cell's left edge at room x=20 is col 92 — an earlier draft said col 28, conflating the room x with the column, and it reached a dispatch before a composite caught it.

Write it outside the repo. It is an input, not an asset.

- [ ] **Step 2: Generate the lean-to as an object**

Use `mcp__claude_ai_Pixel_Lab__create_1_direction_object` with:

- `view: 'sidescroller'`
- `style_images`: the one crop, as `{ "base64": "<png bytes>", "format": "png" }`. **Quantise it first** — base64 arguments get truncated in transit and the tool has no URL variant; 32 colours takes it from ~4,850 characters to ~1,200.
- **no `size`** — it is mutually exclusive with `style_images`
- `description`: a lean-to of rough planks leaned against each other, open on one side, standing on grass at the edge of a forest clearing. **State neutral, even, flat daylight with no cast shadows and no time-of-day colour** — the scene's light is one overlay drawn last over backdrop, foliage and Blob alike, so anything lit for dusk gets lit twice. Nothing in the tool enforces this; it lives in the prose.

At 48px (the ≤85 tier) this returns **16 candidates** in `review` status. Candidate count falls as size rises: 32px would give 64.

- [ ] **Step 3: Inspect the candidates**

`mcp__claude_ai_Pixel_Lab__get_object` with the returned `object_id`. Do not call `select_object_frames` or `dismiss_review` yet.

- [ ] **Step 4: Composite the candidates over the real scene**

**This step is the one that matters and it is easy to skip.** The tree's candidate 8 was chosen from a composite over the real `forest-day.png` with the real Blob sprite at its real position, and the README records why: *a tree that will never read correctly in place still looks fine on cutouts over transparency.* The shelter sits at the treeline where it competes with backdrop detail, so it matters more here.

Build a contact sheet placing each candidate at its real position — top-left `(20, -24)` in room coordinates, which is **PNG col 92, row 14** — PNG col = x + 72, so do not mistake the room x for the column — over `forest-day.png`, with Blob's sprite at its real position. Scale the sheet up (5× or more) so 48px art is judgeable.

- [ ] **Step 5: Send the sheet to the owner and STOP**

Use `SendUserFile`. Say which candidate indices you would shortlist and why, in terms of: does it read as a shelter at 48px, does it sit on the ground line, is it lit flat, and does its silhouette leave room to be filled in twice without changing footprint.

**Do not choose. Do not proceed to Task 2.** The choice is the owner's.

- [ ] **Step 6: Record the job**

Once they answer, note the `object_id` and chosen index in your report. No commit in this task — nothing durable has been produced yet.

---

### Task 2: Fill the lean-to in, twice

**Files:**
- None committed. Produces two more Pixel Lab states.

**Interfaces:**
- Consumes: Task 1's `object_id` and chosen candidate.
- Produces: `object_id`s for the hut and cabin states. Task 3 downloads all three.

- [ ] **Step 1: Keep the chosen candidate**

`mcp__claude_ai_Pixel_Lab__select_object_frames` with the object id and `indices: [<the owner's choice>]`.

- [ ] **Step 2: State-edit to the hut**

`mcp__claude_ai_Pixel_Lab__create_object_state`:

- `object_id`: the lean-to's
- `state_name`: `hut`
- `edit_description`: close the open sides in with plank walls, keeping the existing roof line and the exact same footprint and position; add a small shuttered gap in the front wall. Keep the flat neutral daylight.

**Why a state edit and not a second generation.** `docs/BLOB.md` §7 records the equivalent finding for bodies: the forms are subtractions from the fullest one, and *two `create_character` attempts could not produce a body without legs at all; the state edit could.* A state edit preserves registration — the property that lets worn items need no anchor of their own — which is what makes "same footprint" structural rather than hoped for.

- [ ] **Step 3: State-edit the hut to the cabin**

`create_object_state` on **the hut's** id (not the lean-to's — the arc is cumulative):

- `state_name`: `cabin`
- `edit_description`: replace the shuttered gap with a proper window with a frame, and add a plank door; keep the same roof line, footprint and position. Keep the flat neutral daylight.

- [ ] **Step 4: Composite all three side by side, over the real scene**

Same treatment as Task 1 Step 4, all three at the same position, scaled up. The question this sheet answers is the one the spec's §4 exists to guarantee: **do the three share a footprint?**

- [ ] **Step 5: Send to the owner and STOP**

Say plainly whether the footprint held. If a stage drifted, say so and propose the fallback the spec names: hand-compositing that stage from the chosen still, the way the tree's shear was hand-built after `animate_image` was measured and rejected. **Do not hand-composite without asking.**

---

### Task 3: Commit the three PNGs and document them

**Files:**
- Create: `resources/js/patyourself/scenes/shelter-lean-to.png`
- Create: `resources/js/patyourself/scenes/shelter-hut.png`
- Create: `resources/js/patyourself/scenes/shelter-cabin.png`
- Modify: `resources/js/patyourself/scenes/README.md`

- [ ] **Step 1: Download all three at 48×48**

Fetch each state's PNG. Then verify, because the tool's output size is inferred rather than set:

```bash
cd resources/js/patyourself/scenes
for f in shelter-lean-to.png shelter-hut.png shelter-cabin.png; do
  sips -g pixelWidth -g pixelHeight "$f"
done
```

Expected: every one reports `pixelWidth: 48`, `pixelHeight: 48`. **If any differ, stop and report** — Task 4's test asserts 48×48 and a mismatch means the pipeline produced something other than what was specced. Do not resize to make it fit.

- [ ] **Step 2: Confirm transparency**

Each must have a transparent background — `no_background` behaviour. A PNG with a baked sky will paint a rectangle of daylight over the backdrop at night. Check the alpha channel is present:

```bash
sips -g hasAlpha shelter-lean-to.png shelter-hut.png shelter-cabin.png
```

- [ ] **Step 3: Document them in the README**

Add a `# The shelter` section after the foliage section, in the same table form the backdrops and foliage use. It must record, for each stage: the file, the Pixel Lab **object id and state name**, and the chosen candidate index for the lean-to. Pixel Lab keeps no images library, so the repo is their only durable home.

Also record, in prose, the three decisions a later reader would otherwise re-derive: that the two style crops came from `forest-day.png` at the offsets in Task 1 and why there were two; that the hut and cabin are **state edits rather than generations**, with the registration reasoning; and that they are single-frame because a structure is permanent where the tree and grass exist to carry wind.

- [ ] **Step 4: Commit**

```bash
git add resources/js/patyourself/scenes/shelter-lean-to.png \
        resources/js/patyourself/scenes/shelter-hut.png \
        resources/js/patyourself/scenes/shelter-cabin.png \
        resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): draw the three shelters Blob can build"
```

---

## Batch 1 review checkpoint

**Stop.** Report to the owner: the three PNGs at 48×48 with alpha, the composite showing the shared footprint, and every job id recorded. Nothing renders yet — Batch 2 wires it.

---

# BATCH 2 — the code

---

### Task 4: The registry, the cell, and the coordinate's new meaning

**Files:**
- Modify: `resources/js/patyourself/scenes.ts`
- Test: `resources/js/patyourself/scenes.test.ts`

**Interfaces:**
- Produces: `SHELTER_CELL: number` (48), `SHELTER_SPRITES: Record<string, string>`, `shelterSprite(stage: string): string | undefined`. `SceneSpec.shelter` changes meaning to the base centre and its value becomes `[44, 24]`. Task 5 consumes all of these.

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/patyourself/scenes.test.ts`. **Read the file's existing `sheetSize()` helper first** — it reads a PNG's IHDR at byte offsets 16 and 20 and is already used for the foliage sheets; use it rather than writing a second one.

```ts
describe('the shelter sprites', () => {
    it('draws every stage on the same 48x48 cell', () => {
        for (const [stage, sheet] of Object.entries(SHELTER_SPRITES)) {
            const { width, height } = sheetSize(sheet);

            expect(`${stage}: ${width}x${height}`).toBe(`${stage}: ${SHELTER_CELL}x${SHELTER_CELL}`);
        }
    });

    it('has a sprite for every stage the shelter config can reach', () => {
        expect(Object.keys(SHELTER_SPRITES).sort()).toEqual(['cabin', 'hut', 'lean-to']);
    });

    it('does not resolve a stage it has no sprite for', () => {
        // `constructor` resolves to a truthy function through the prototype
        // chain on a bare lookup. BLOB.md §12 records that ROOM_OBJECTS and
        // SPRITE_ITEMS still carry that bug and only scenes.ts was fixed.
        expect(shelterSprite('constructor')).toBeUndefined();
        expect(shelterSprite('mansion')).toBeUndefined();
    });

    it('stands the shelter on the measured ground line', () => {
        // PNG row 62 is the backdrop's ground line in all four, measured
        // rather than chosen, and PNG row = y + 38.
        expect(SCENES.forest.shelter).toEqual([44, 24]);
    });
});
```

The first case interpolates the stage name into the compared string deliberately: a bare `expect(width).toBe(48)` inside a loop reports `expected 32 to be 48` without saying *which* stage failed.

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/patyourself/scenes.test.ts`
Expected: FAIL — `SHELTER_SPRITES` and `shelterSprite` are not exported, and `SCENES.forest.shelter` is `[44, 22]`.

- [ ] **Step 3: Implement**

In `scenes.ts`, beside the existing imports of the backdrop and foliage PNGs:

```ts
import shelterCabin from '@/patyourself/scenes/shelter-cabin.png';
import shelterHut from '@/patyourself/scenes/shelter-hut.png';
import shelterLeanTo from '@/patyourself/scenes/shelter-lean-to.png';
```

Then, near `SCENES`:

```ts
/**
 * One cell for all three stages, so the size is a constant rather than
 * per-scene data. If a later scene ever needs a different one, that is when
 * it earns a field on `SceneSpec`.
 */
export const SHELTER_CELL = 48;

const SHELTER_SPRITES: Record<string, string> = {
    'lean-to': shelterLeanTo,
    hut: shelterHut,
    cabin: shelterCabin,
};

/**
 * The sprite for a built stage, or nothing.
 *
 * `Object.hasOwn` rather than a bare lookup: `SHELTER_SPRITES['constructor']`
 * resolves to a function through the prototype chain, and a truthy value here
 * would be passed to `<image href>`. BLOB.md §12 records that `ROOM_OBJECTS`
 * and `SPRITE_ITEMS` still carry exactly that bug.
 */
export function shelterSprite(stage: string): string | undefined {
    return Object.hasOwn(SHELTER_SPRITES, stage) ? SHELTER_SPRITES[stage] : undefined;
}

export { SHELTER_SPRITES };
```

Change `SCENES.forest.shelter` from `[44, 22]` to `[44, 24]`.

- [ ] **Step 4: Rewrite the coordinate's docblock and its comment**

`SceneSpec.shelter`'s docblock currently says *where a structure stands* but the value has been the label's centre. It now means what it says — **the base centre** — and the docblock must state the divergence explicitly:

> Unlike `FoliageSpec.at`, which is a cell's **top-left**, this is the base centre — where the building *stands*. Foliage is a cell placed on a grid; a structure stands somewhere. The art's top-left derives as `(x − SHELTER_CELL/2, y − SHELTER_CELL)`.

And the inline comment above `shelter: [44, 24]` must be brought true: y is now the measured ground line (PNG row 62), not a label position. **A comment contradicting its own coordinate has cost this project two rounds** — do not leave the old arithmetic in place beside a new number.

- [ ] **Step 5: Run the tests**

Run: `npx vitest run resources/js/patyourself/scenes.test.ts` → PASS
Run: `npx tsc --noEmit` → clean

- [ ] **Step 6: Prove the tests can fail**

Apply each mutation, confirm RED, revert, confirm `git diff` is empty for the mutated file:

1. `shelterSprite` → bare `return SHELTER_SPRITES[stage];` — the `constructor` case must redden.
2. `SHELTER_CELL` → `48` — the size case must redden and must **name the failing stage**.
3. `SCENES.forest.shelter` → `[44, 22]` — the ground-line case must redden.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/scenes.ts resources/js/patyourself/scenes.test.ts
git commit -m "feat(companion): give the shelter a sprite per stage"
```

---

### Task 5: Draw the standing stage in the clearing

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx`
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `SHELTER_CELL`, `shelterSprite` from Task 4; `CompanionRoom`'s existing `shelter?: string | null` and `inside?: boolean` props, which already exist and are already passed.
- Produces: an `<image data-shelter="<stage>">` inside the scene SVG.

- [ ] **Step 1: Write the failing tests**

Append to `companion-room.test.tsx`. **Read the existing `room(overrides, hour, props)` helper first** and use it — do not write a second render helper beside it.

```ts
describe('the shelter standing in the clearing', () => {
    it('draws the stage that is standing', () => {
        const container = room({}, 12, { shelter: 'hut' });
        const drawn = container.querySelector('[data-shelter]');

        expect(drawn).not.toBeNull();
        expect(drawn).toHaveAttribute('data-shelter', 'hut');
    });

    it('draws one stage and never two', () => {
        for (const stage of ['lean-to', 'hut', 'cabin']) {
            const container = room({}, 12, { shelter: stage });

            expect(container.querySelectorAll('[data-shelter]')).toHaveLength(1);
        }
    });

    it('draws nothing before anything is built', () => {
        const container = room({}, 12, { shelter: null });

        expect(container.querySelector('[data-shelter]')).toBeNull();
    });

    it('draws nothing indoors, because you are looking at the inside of it', () => {
        const container = room({}, 12, { shelter: 'cabin', inside: true });

        expect(container.querySelector('[data-shelter]')).toBeNull();
    });

    it('stands the cell on the ground line, bottom-aligned on the base centre', () => {
        const container = room({}, 12, { shelter: 'lean-to' });
        const drawn = container.querySelector('[data-shelter]');

        // shelter is [44, 24], CELL is 48; top-left is (x - CELL/2, y - CELL).
        expect(drawn).toHaveAttribute('x', '20');
        expect(drawn).toHaveAttribute('y', '-24');
        expect(drawn).toHaveAttribute('width', '48');
        expect(drawn).toHaveAttribute('height', '48');
    });
});
```

The second case is the clearing-side twin of the gap F3's final review found in this same file, where only the lean-to's interior was guarded and both the hut's and the cabin's could draw together with the whole suite green. Writing it now rather than having it found later.

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — no element carries `data-shelter`.

- [ ] **Step 3: Implement the layer**

In `companion-room.tsx`, import `SHELTER_CELL` and `shelterSprite` from `@/patyourself/scenes`, then add this component beside `FoliageLayer`:

```tsx
/**
 * What Blob built, standing where the scene says it stands.
 *
 * A plain `<image>` rather than a `FoliageLayer`: the tree and the grass are
 * sheets read by the one shared clock because they exist to carry wind, and a
 * structure exists to be permanent. One frame, no clock, no phase.
 *
 * Drawn in the outdoor branch so it inherits the light wash. The sprites are
 * generated in neutral light, so a layer that escaped the overlay would stay
 * at noon all night — the same reason the foliage sits here.
 */
function ShelterLayer({ stage, at }: { stage: string; at: readonly [number, number] }) {
    const sprite = shelterSprite(stage);

    if (sprite === undefined) {
        return null;
    }

    return (
        <image
            data-shelter={stage}
            href={sprite}
            // `at` is the base centre, so the cell hangs up and left of it.
            x={at[0] - SHELTER_CELL / 2}
            y={at[1] - SHELTER_CELL}
            width={SHELTER_CELL}
            height={SHELTER_CELL}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}
```

Render it immediately **after** the `scene.foliage.map(...)` block, inside the same outdoor branch:

```tsx
{shelter !== null && scene.shelter !== undefined && (
    <ShelterLayer stage={shelter} at={scene.shelter} />
)}
```

`shelter` is the existing prop. Being inside the outdoor branch is what makes "nothing indoors" structural rather than a second condition to keep in sync.

- [ ] **Step 4: Run the tests**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx` → PASS, and **all pre-existing cases in the file must pass unedited.** An edit to one means behaviour moved rather than was added.

Run: `npx tsc --noEmit` → clean

- [ ] **Step 5: Prove the tests can fail**

1. Drop the `sprite === undefined` guard and return the `<image>` regardless — the `constructor`-style safety is Task 4's, but here the *unbuilt* case must redden.
2. Change `y={at[1] - SHELTER_CELL}` to `y={at[1]}` — the ground-line case must redden.
3. Move the render outside the outdoor branch — the indoors case must redden.

Revert each and confirm `git diff -- resources/js/patyourself/companion-room.tsx` is empty.

- [ ] **Step 6: Commit**

```bash
git add resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx
git commit -m "feat(companion): stand what Blob built in the clearing"
```

---

### Task 6: The hotspot stops being the art

**Files:**
- Modify: `resources/js/pages/companion.tsx`
- Modify: `resources/css/patyourself.css`
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `SHELTER_CELL` from Task 4; the `[data-shelter]` image from Task 5.
- Produces: `ShelterSpot` rendering no text, sized to the art, named `Go inside the <label>`. `NodeSpot` gains an explicit accessible name.

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/pages/companion.test.tsx`.

```ts
it('names the way into what Blob built, without printing a word on the picture', () => {
    renderPage({ bag: bag({ shelter: { built: 'hut', label: 'hut', offer: null } }) });

    const control = screen.getByRole('button', { name: /go inside the hut/i });

    expect(control).toBeEnabled();
    // The art says what it is. The control must not also say it.
    expect(control).toHaveTextContent('');
});

it('still names each thing in the clearing for a screen reader', () => {
    renderPage({ bag: bag({ nodes: [standingReeds()] }) });

    expect(screen.getByRole('button', { name: /the reeds/i })).toBeInTheDocument();
});
```

**Read the file's existing render helper and bag/node factories first** and use their real names — the two above are placeholders for whatever the file already calls them, and inventing a second set beside the existing ones is how a suite grows two ways to do one thing. The assertions are what matter, not these names.

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/pages/companion.test.tsx`
Expected: FAIL — the shelter control's accessible name is currently its label text (`hut`), not `go inside the hut`.

- [ ] **Step 3: Rework `ShelterSpot`**

It keeps being a real `<button>` over the picture — §7 makes that an accessibility rule and it does not change. What changes is that it carries no text, is sized to the art, and names the action:

```tsx
function ShelterSpot({
    label,
    at,
    onClick,
}: {
    label: string;
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-shelter', 'c-shelter--art')}
            style={at}
            // The picture says what it is; the name says what pressing does.
            aria-label={`Go inside the ${label}`}
            onClick={onClick}
        />
    );
}
```

At its call site, the position must be the **art's centre**, not the base centre, because `.c-node` is translated by `-50%, -50%`. With `shelter` at `[44, 24]` and a 48 cell, the art's centre is `[44, 0]`:

```tsx
at={roomOffset(shelterAt[0], shelterAt[1] - SHELTER_CELL / 2)}
```

- [ ] **Step 4: Give `NodeSpot` an explicit accessible name**

`NodeSpot`'s accessible name is currently its text content. That is adequate while it renders a label and becomes a silent regression the moment F3.6 replaces the text with a sprite. Add `aria-label={label}` to its `<button>` now, in the phase that notices rather than the one that would break it. Leave everything else about it alone — it has no art to sit over yet.

- [ ] **Step 5: Add the CSS**

Append to `resources/css/patyourself.css`, matching the house style exactly — **no space after colons, semicolon before the closing brace**, and no `prettier --write`:

```css
/* Sized to the art it covers, not to a word. The picture is the affordance;
   the outline is how a pointer and a keyboard find it. */
.c-shelter--art{width:33.333%;height:42.105%;padding:0;background:transparent;border-color:transparent;}
.c-shelter--art:hover{background:rgba(15,12,9,.28);border-color:#FFD9A8;}
```

`33.333%` is `SHELTER_CELL / 144` and `42.105%` is `SHELTER_CELL / 114` — the cell as a fraction of the room's own box, so it tracks the stage at any width exactly as `roomOffset` does. `.c-node:focus-visible` already supplies the focus outline and is inherited.

- [ ] **Step 6: Widen the shared fixture, in this task**

**This is the trap that has bitten three times on this branch** — `available: 0`, then `offer: null`, then `built` and `scene` together — and every time a guard went quiet rather than red instead of failing.

Both runs of the `never shows what has not happened` guard in `companion.test.tsx` must actually render the shelter art. That needs `bag.shelter.built` set **and** a scene whose `SCENES` entry carries a `shelter` coordinate: the fixture's default scene is `cabin`, which names none. Set both, and add a comment saying why both are needed.

- [ ] **Step 7: Prove the guard now covers it**

Render `{' 1 of 3'}` as visible text inside `ShelterSpot`. **Both** guard runs must go RED on the `\d+ of \d+` branch. Revert, and confirm `git diff -- resources/js/pages/companion.tsx` is empty.

Before this step that mutation would ship green. Record the RED output in your report.

- [ ] **Step 8: Run everything**

```
npx vitest run
npx tsc --noEmit
php artisan test --compact
```

Vitest and `tsc` must be clean. PHP is untouched by this task and must be unchanged.

- [ ] **Step 9: Commit**

```bash
git add resources/js/pages/companion.tsx resources/css/patyourself.css \
        resources/js/patyourself/companion.fixture.ts resources/js/pages/companion.test.tsx
git commit -m "feat(companion): make the way inside a control over the picture"
```

---

## Batch 2 review checkpoint

**Stop.** Report both suite counts and their deltas, the mutation evidence for every new case, and confirm `main` still fast-forwards.

---

# BATCH 3 — look at it

---

### Task 7: Look at it

**Files:**
- Temporary, deleted at the end: a throwaway dump test and the HTML it writes.

**Interfaces:** none. This task produces screenshots and a judgement.

**Why it is a task.** BLOB.md trap 4: class-name assertions and geometry maths cannot judge a visual — the shoes shipped into the bottom-left corner with 340 tests green. Every number in this plan is derived from measured values, and none of them proves the building looks like it is standing there.

- [ ] **Step 1: Build**

Run: `npm run build`. Without a fresh manifest the page throws `Unable to locate file in Vite manifest`. `public/build` is gitignored.

- [ ] **Step 2: Dump all three stages**

Write a temporary `tests/Feature/Companion/DumpTheClearingTest.php` that creates a user with a handful of logged outcomes, sets `shelter` to each stage in turn, stocks the three nodes plus `salvage`, and writes `public/clearing-<stage>.html` for each. Assert each file exists.

This is a verification script, which CLAUDE.md discourages — the exception is explicit and narrow: no test can judge whether a building reads as standing on the ground. It is deleted in Step 6 and never committed.

- [ ] **Step 3: Serve it**

Herd serves the main checkout, never a worktree:

```bash
php -S 127.0.0.1:8899 -t public
```

- [ ] **Step 4: Look, at two widths**

Open each stage. Screenshot at a desktop width, then at a narrow one. Judge, in order:

1. **Does the building read as standing on the ground**, or as floating or sunk?
2. **Do the three share a footprint** — does the hut look like the lean-to continued?
3. **Is it lit consistently with the scene** at day *and* at night? A sprite that shipped lit would look right at noon and wrong at 21:00, which is why both parts of day must be checked.
4. Does it read as behind the things you pick up?
5. Does anything overlap — the reeds hotspot sits at `[44, 36]`, twelve units in front.

Blob's frames freeze under browser automation (`document.hidden` stops the clock) so `data-frame` reads 0. That is expected. Trust `data-animation` and `data-scene`.

- [ ] **Step 5: Send the screenshots and propose, do not decide**

**Do not change a coordinate on your own judgement of a screenshot.** F3's Task 18 ruling stands: placement is the owner's, and the render only informs the proposal. If something is off, say which number you would change, by how much, and render the proposed value before sending it — F3 forwarded two unrendered suggestions and both were refuted.

- [ ] **Step 6: Clean up**

```bash
rm tests/Feature/Companion/DumpTheClearingTest.php public/clearing-*.html
git status --short
```

Expected: clean.

---

## Batch 3 review checkpoint

**Stop.** Report: the screenshots at both widths and both parts of day, a plain judgement on each of the five questions, both suite counts, and that `main` fast-forwards cleanly.

---

## Self-review

**Spec coverage.** Every section maps to a task:

| Spec | Task |
| --- | --- |
| §1 the split; F3.5 is the machine | the batch structure; §6's inheritance is untouched here |
| §2 band logic deferred; `NodeSpot` keeps its label | 6 (Step 4 is the only change it gets) |
| §3 the sprite, 48×48, base on the ground line | 1, 3, 4, 5 |
| §4 the pipeline, object-then-state-edit | 1, 2, 3 |
| §5 static layer, not a foliage layer | 5 |
| §5 one coordinate, two derivations, meaning change | 4 (Steps 3–4), 5, 6 (Step 3) |
| §5 `Object.hasOwn` registry | 4 |
| §5 hotspot as control over art | 6 |
| §5 `NodeSpot`'s accessible name | 6 |
| §7 every listed test | 4, 5, 6 |
| §7 fixture widened in the same task | 6 (Steps 6–7) |
| §7 not provable: whether it looks right | 7 |
| §8 three batches, stop after each | the three checkpoints |
| §10 what would make this wrong | two stages at once (5), drawn indoors (5), accessible name lost (6), lit wrong (3 alpha check, 7 night check), footprint drift (2), coordinate changed alone (7) |

**Placeholder scan.** No step says "add error handling", "TBD", or "similar to Task N". Three steps deliberately say *read the existing helper first* — `sheetSize()` in Task 4, `room()` in Task 5, and the page test's render and factory helpers in Task 6 — because inventing a second helper beside an existing one is how a suite grows two ways to do the same thing. Those are instructions, not gaps. Task 6 Step 1's factory names are explicitly flagged as stand-ins for whatever the file already calls them, with the assertions named as the real content.

**Type consistency.** `SHELTER_CELL: number` and `shelterSprite(stage: string): string | undefined` are defined in Task 4 and consumed under those exact names in Tasks 5 and 6. `SceneSpec.shelter` is `readonly [number, number]` throughout and is `[44, 24]` from Task 4 onward. `CompanionRoom`'s `shelter?: string | null` and `inside?: boolean` already exist and are already passed by `companion.tsx` — no task changes their types. The `data-shelter` attribute is produced in Task 5 and asserted only there.

**One risk the plan cannot remove.** Tasks 1 and 2 both stop for the owner, and Task 2's output may fail its own footprint check, in which case the spec's hand-composite fallback applies and Task 2 grows. That is inherent to generating art and is why Batch 1 comes first.
