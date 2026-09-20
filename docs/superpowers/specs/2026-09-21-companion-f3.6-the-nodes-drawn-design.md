# F3.6 — the clearing drawn, part two: the four nodes

**Status:** design, approved 21 Sep 2026.

**Precedence.** `docs/BLOB.md` is the current-state reference and outranks this file wherever the
two disagree. This is a frozen design record: accurate for the day it was written, and superseded in
places the moment something is rendered and looked at.

Reads before this one: `docs/BLOB.md` §7 (the four art pipelines) and §10 (rulings not to reopen);
`resources/js/patyourself/scenes/README.md`, whose "Running this pipeline again" section is the
runbook this phase executes eleven more times; and
`docs/superpowers/specs/2026-09-20-companion-f3.5-the-shelter-drawn-design.md` §6, which names the
seam this phase picks up.

---

## 1. Why this exists

F3.5 drew the shelter and left the four nodes as text labels. This phase draws them, at three
amounts each, and deletes the last text from the clearing.

Two things follow that are worth stating as goals rather than as consequences:

- **The art carries the standing amount.** No number is printed in the clearing. The picture is the
  readout.
- **The label-sizing problem closes rather than moves.** `BLOB.md` §12 has carried an open item
  since F1: hotspot labels are a fixed 8px font that does not scale with the SVG, so below roughly a
  300px stage a label is wider than the gap it has to sit in, and the failure mode is reflow rather
  than overflow. Deleting the labels retires it. The replacement hit region is measured in room
  units and scales with the stage, so the fixed-pixel box does not come back in a new costume. The
  trunk's width-limited residual, recorded in the same section, goes with it.

## 2. The band model

### Three bands, and the numbers live in config

```php
'node_bands' => [
    // name => the least `available` that reaches it, ascending.
    'bare'   => 0,
    'some'   => 1,
    'plenty' => 5,   // = capacity.base
],
```

Resolved by sorting on the floor and taking the last band that has begun — **the same shape
`partOfDay()` already uses**, which is how night wraps past midnight without a fifth state
describing 3am. Reusing it is deliberate: this project already has one answer to "map a scalar onto
named bands", and a second one with different edge behaviour would be a second opinion.

**Why 5.** It is `config('companion.capacity.base')`, and it means *one trip can no longer take it
all*. Three properties decided it over the alternatives:

- It is the one threshold a player could in principle infer from play, without being told.
- It is **absolute, not per-account.** Thresholds relative to the account's own capacity were
  considered and rejected: buying a crate would make the clearing look *emptier*, which is the
  picture going backwards as a reward for progress. The look of the world must not depend on the
  size of the bag.
- It makes the heap's whole arc visible. 26 planks drains in five base-bag trips, so the heap reads
  `plenty` → `some` → gone rather than sitting at one band until it vanishes.

### The thresholds are authored; the art is semantic

The sprites are drawn for **meaning** — bare, some, plenty — and the numbers are mapped onto them in
config. This is the load-bearing split of the phase. Getting a threshold wrong costs a config edit.
Getting the art wrong costs an eight-minute generation and a candidate judgement. They must not be
welded together, which is also why the thresholds may not be duplicated into TypeScript: see §5.

### Saturation is the answer to 300

`StockCompanionNodes` increments with no ceiling, so a long-absent account can stand hundreds at a
node. `plenty` is open-ended and 5 looks exactly like 300.

That is the intended behaviour, not a limitation accepted. A pile past a certain size stops reading
as a number to the eye as well. The precision is **demoted from the picture, not destroyed**: the
bag's take field still carries the exact amount as its `max`, and Blob's own `took` line still says
what it came back with. The accessible name keeps it too — §5.

### The invariant this phase adds

> **The node object never shrinks. Only what is standing at it grows.**

The reeds, the fallen branches and the fallen trunk persist at `bare`. A node whose art disappeared
when it was drained would be the world regressing, and would re-create the invisible-control bug
F3.5 fixed in `15e893a` — a hit region standing over nothing.

`bare` is therefore **the most-seen art of the eleven**, not an edge case: all three world nodes
stand in the clearing from the start, unmet, at `available: 0`, and stay there until their skill is
bought. It has to read as the node well enough to be worth clicking.

**The heap is the stated exception, and the rule it follows is already ruled on.** `BLOB.md` §10: *a
node without a skill is a heap, not the world* — absent until something places it, never restocked,
and deleted once drained. `HarvestNode:112` does the delete, and `CompanionBag::nodes():225` skips a
skill-less node with no row, so the heap never reaches the client at zero and never renders at
`bare`.

**Eleven sprites, not twelve.** Reeds, branches and trunk get three bands each; the heap gets `some`
and `plenty`.

## 3. The sprites and their cells

### Generated square at 48, shipped cropped

`create_1_direction_object` forces a square output derived from the largest style image, and
`style_images` cannot be combined with `size`. So 48×48 is not a choice at generation time.

48 and not 32 is a choice, and F3.5 paid for the evidence: a 32px generation produced a review object
(`0d52db24-c750-4f2e-9d6c-9f7de6205e36`, 64 candidates) that was abandoned unpicked, because two
independent shortlists over the same set agreed on nothing — and that disagreement *was* the
finding. The art was too small to carry the distinctions being judged. Three bands that must differ
visibly is a harder judgement than the one 32px already failed, so the size that worked is the size
used.

**Shipped, each node's PNGs are cropped to the art's true bounds and the cell is a per-node
`[w, h]` pair.** Precedent exists and is already ruled on: `BLOB.md` §10 records *foliage `cell` is a
`[w, h]` pair* because the art is 48×64 and 32×24, neither square, and padding to square would push
the tree's cell four units outside the room's left edge. The same arithmetic applies harder here.
Four 48-wide cells plus the shelter's 48 need 240 units of a 144-unit room; at today's positions the
branches' cell would span x −70..−22 and the trunk's x −64..−16, and the reeds' would run past the
room's right wall at 72 and into the shelter's 20..68.

### One crop box per node, across all its bands

> All bands of one node crop to **one** box: the union of their opaque bounds.

Cropping each band to its own bounds would lose registration and the pile would jump sideways as it
grew — the exact failure the shelter's shared registration exists to prevent, and the reason
`shelter-cabin.png` and `shelter-hut.png` have byte-identical alpha channels. The pile grows *inside*
a fixed cell.

Cell sizes are therefore measured off the finished art in Batch 1, not chosen now. Recording
placeholder numbers here would be exactly the stale-value trap this project keeps paying for.

### `NodeSpec.at` changes meaning

`NodeSpec.at` is documented today as *the hotspot's centre*. It becomes **the base centre — where
the thing stands** — matching what F3.5 did to `SceneSpec.shelter` for the same reason: foliage is a
cell placed on a grid, and a node, like a structure, *stands* somewhere.

```
art top-left  = (x − w/2, y − h)
```

**The comment above each coordinate is rewritten in the same edit as the coordinate.** A comment
that contradicts its own coordinate has cost this project a round twice, and `scenes.ts`'s own
comments are long enough that a stale one is easy to miss.

The coordinates themselves are **not** decided in this spec. They are proposed from a render in
Batch 3 and the owner decides — `BLOB.md` §10 and F3's Task 18 ruling both bind: placement is
theirs, and a render only informs the proposal.

## 4. How the eleven are made

The fourth pipeline (`BLOB.md` §7, "the shelter — one object, edited through its states"), run four
more times. `scenes/README.md`'s "Running this pipeline again" is the runbook; what follows is only
what is specific to nodes.

**Per node, lowest band first:**

1. `create_1_direction_object`, `view: sidescroller`, style image a **48×48 crop of
   `forest-day.png` at that node's real position** — the patch of ground the thing actually stands
   on, not an arbitrary sample of forest.
2. `create_object_state` upward for each higher band.

**Growth is additive, which is the favourable direction for a state edit.** F3.5 established that an
overlay *can only add, never remove*: shoes worked, the hat will not, because its crown erases the
sprout stem. A pile getting bigger only adds. That is the reason to expect these edits to behave
better than the hut → cabin edit did.

**Expect to composite anyway.** A state edit preserves registration but **not scale** — the
generated cabin came back spanning 40 of the hut's 48 columns, about 17% narrower, and was
hand-composited over its predecessor rather than re-rolled. The check, per band, is the one already
used there: is every opaque pixel of the higher band a superset of the lower band's silhouette? If
not, layer it over the predecessor. This is the established fallback, not an improvisation — the
tree's rejected `animate_image` output set the precedent of *measured, found wanting, replaced by
something hand-built on the same grid*.

**Four traps this pipeline has already paid for**, restated because each has cost a round:

- `sips --cropOffset` takes **row then column**, the reverse of how the prose writes a position.
  `PNG col = x + 72`, `PNG row = y + 38`. A room `x` is not a PNG column, and that confusion reached
  a dispatch in F3.5.
- Base64 style arguments truncate in transit past roughly 1KB. Quantise to ~32 colours first — and
  **quantise the composited reference, never a transparent cutout**, because quantising transparency
  turns it black and teaches the model to draw a black backing.
- Candidate count falls as size rises: 64 at 32px, 16 at 48px. Expect 16.
- Budget ~8 minutes per job and **do not poll in a tight loop**. There is no working sleep primitive
  in this environment; a poll-wait loop cost 107k tokens once. These jobs stay in the controller
  session — a subagent has no `SendUserFile`, and the review step needs the owner's eyes.

**Candidates are judged composited over the real `forest-day.png`, at the real position, with the
real Blob sprite** — never as cutouts on transparency, where a shape that will never read correctly
in place still looks fine. `BLOB.md` §11 trap 4 is the general form: class-name assertions and
geometry maths cannot judge a visual. Composites are published with **`Artifact`**, not
`SendUserFile`.

**Review-object lifecycle is not automatic.** `get_object` inspects, `select_object_frames` promotes
the chosen one, `dismiss_review` discards the rest. An object left in review stays there.

## 5. The code

### The band comes from the server

`CompanionBag::nodes()` gains `'band' => ...` beside the existing `'available'`. Both stay: the bag
screen still needs `available` for its take field's `max` and its `available > 0` filter.

**The thresholds may not be duplicated into TypeScript.** The client cannot read PHP config, so the
only two options are "the server sends the band" or "the numbers exist twice". The second is two
opinions about what `plenty` means, and they will eventually disagree. This also matches the rule
`scenes.ts` already states: *this file knows where the reeds are, not that there are reeds.*

The band is a **name**, not an index — `'bare' | 'some' | 'plenty'`. It is what the sprite registry
keys on, and `data-band="plenty"` is readable in a test and in the DOM in a way `data-band="2"` is
not.

### The sprite registry and the layer

`scenes.ts` gains, following the pattern F3.5 established rather than the one `ROOM_OBJECTS` and
`SPRITE_ITEMS` still carry:

```ts
const NODE_SPRITES: Record<string, Record<string, string>>;   // node -> band -> png
export const NODE_CELLS: Record<string, readonly [number, number]>;
export function nodeSprite(node: string, band: string): string | undefined;
```

`Object.hasOwn` **at both levels**. `BLOB.md` §12 records that `ROOM_OBJECTS` and `SPRITE_ITEMS`
still carry the prototype-chain fallthrough that was fixed only in `scenes.ts`; a node or band named
`constructor` resolving to a truthy function passed to `<image href>` is that bug exactly.

`companion-room.tsx` gains `NodeLayer`, the generalisation of `ShelterLayer` that F3.5 §6 named as
the inheritance: a plain `<image>`, no `useSpriteClock`, no viewBox frame window, no `phase`. Drawn
in the existing `!indoors` block after the foliage map — over the backdrop, under Blob, **under the
wash**, because the sprites are generated in neutral light and a layer that escaped the overlay
would stay at noon all night.

### The hotspot

`NodeSpot` becomes what `ShelterSpot` already is: a transparent control over the art. It keeps its
`<button>`, keeps being a sibling of the `<svg>` rather than a shape inside it, and loses its text.

**Sizing is derived, for all five hotspots, and that fixes a magic number F3.5 left.**
`.c-shelter--art` hardcodes `width: 33.333%; height: 42.105%` — which is 48/144 and 48/114, computed
by hand and written down. With per-node cells there would be five such pairs. Instead the size is
computed where the cell is known:

```
width  = (w / ROOM.w) * 100 %
height = (h / ROOM.h) * 100 %
```

and `.c-node--art` carries only the transparent background, the hover and `:focus-visible`
affordance, and the floor below. The shelter moves onto the same derivation; one arithmetic, five
callers.

**Hit region is the art's box, with a 24px floor** — `min-width` and `min-height` both, on
`.c-node--art`. WCAG 2.5.8 (AA) is 24×24 CSS px.

The arithmetic, rather than a conclusion, because the cells are not measured yet: one room unit is
`stage / 144` px, so at a 350px stage a cell needs to be under 10 units in either direction before
the floor binds at all, and under 18 units before it drops below the 44px of WCAG 2.5.5 (AAA). A
node small enough to breach that is a node too small to read, which Batch 3 would catch first.

It is stated as a floor rather than as a target precisely so that it does *not* reintroduce a
fixed-pixel box growing in room units as the stage shrinks. That was §1's whole point, and §10 holds
it.

### The accessible name keeps the number

```
aria-label = available > 0 ? `${label}, ${available}` : label
```

Unchanged from today. F3.5 wrote the reasoning into `NodeSpot`'s own comment for this exact moment:
an explicit `aria-label` overrides the subtree, *"so the count has to be folded into it by hand or a
sighted user keeps seeing the number while a screen reader stops hearing it."* Replacing the number
with the band word would be that same regression pointed the other way. The number is already
reachable through the bag's take field, so nothing new is exposed.

### `is-known` goes with the label

`NodeSpot` currently adds `is-known` when the skill is known, which changes the label's border
colour. There is no label to border any more, and it is not recreated on the art.

This **restores** a rule rather than dropping one. `NodeSpot`'s own docblock says: *"A node you
cannot use looks exactly like one you can, because that is the whole mechanic."* The `is-known`
border quietly contradicts that today. Making the art depend on `known` would also put a fact about
the record into the picture of the world, which is a lock with better manners.

### Focus, fixed once

`ShelterSpot` unmounts on click — going inside removes it — and focus falls to `<body>`. After this
phase the heap's hotspot unmounts too, when `HarvestNode` deletes a drained heap.

The scene container gets `tabIndex={-1}` and a ref. A hotspot that can unmount calls a shared
handler that, after commit, focuses the scene **only if `document.activeElement === document.body`**.
The conditional is the point: it recovers focus that was actually lost and never steals focus that
survived.

## 6. The seam, and why it is Batch 0

`companion.fixture.ts` defaults `scene: 'cabin'`, and `companion-room.tsx:331` computes
`indoors = inside || scene.name === 'cabin'`. A test that omits `scene: 'forest'` therefore exercises
the indoor branch and asserts nothing about the clearing, silently.

This has bitten **five times** across F3 and F3.5, twice in test code written hours after reading the
warning. Vigilance has demonstrably failed, so the fix is mechanical rather than attentional. Note
the shape of the failure: it is never a red test. It is an assertion that passes vacuously —
`queryBy(...)` returning null for the wrong reason.

There is no shared render helper today: `companion.test.tsx` calls `render(...)` at 52 sites and
`companion-room.test.tsx` at 2, each assembling its own payload, which is why `scene: 'forest'` is a
per-site thing to forget. Thirty of those sites remember it.

```ts
export function renderClearing(opts: {
    companion?: Partial<CompanionData>;
    bag?: Partial<CompanionBagData>;
} = {}) {
    const payload = {
        companion: companion({ scene: 'forest', ...opts.companion }),
        bag: bag(opts.bag),
    };
    render(<CompanionPage {...payload} />);

    const scene = screen.getByRole('img');
    if (scene.dataset.scene !== 'forest' || scene.dataset.interior !== undefined) {
        throw new Error(
            "the clearing did not render — companion.fixture defaults to scene: 'cabin'",
        );
    }
    return payload;
}
```

It builds, renders **and asserts**. A test using it cannot under-specify the scene; a test that
hand-rolls its payload still fails loudly, with the reason named, instead of passing vacuously. The
~20 existing indoor cases are untouched — this is why the default is not flipped.

**Flipping the default was considered and rejected**, as was making `scene` required on the fixture:
both rewrite call sites that have nothing wrong with them, and neither makes a hand-rolled payload
loud.

Batch 0 also carries three small debts F3.5 left, grouped because they are all one-line-scale and
all in the same files:

- **`[50, 44]`, the reeds' current position, never reached `docs/BLOB.md` §12.** `scenes.ts` is the
  source of truth and is correct; the doc is silent. The bullet recording the trunk's and the
  shelter's corrections gains a third.
- **Three files newly fail `prettier --check`.** Seven of the files F3.5 touched fail, against 34
  across the repo that already did. CI auto-fixes, so the cost is a future noisy diff rather than a
  break. **The CSS is excluded from any `--write`:** `resources/css/patyourself.css` is hand-formatted
  and already failed on main, and running prettier over it once turned a 94-line addition into a
  3782-line diff. Only the TS/TSX files this phase touches are reformatted, and only in the batch
  that touches them.
- **The focus fix above**, landed before four more hotspots inherit the shape.

## 7. Testing

### Vitest

- **Per node, per band: the right sprite draws, and only one.** The "only one" half is the
  clearing-side twin of the gap found at F3's final review, where only the lean-to's interior was
  guarded and both the hut's and the cabin's could draw together with the whole suite green.
- **The band boundaries, named as mutations.** `available: 4` draws `some` and `available: 5` draws
  `plenty`; moving the config floor to 6 must turn the second red. `available: 0` draws `bare`.
- **The heap never draws at `bare`** — it is absent from the payload, so the hotspot is absent too.
- **The accessible name survives, with its number**:
  `getByRole('button', { name: /the reeds, 27/ })`. This is the precise regression that deleting the
  text would otherwise cause silently.
- **Nothing is drawn indoors.**
- **Focus lands somewhere real** after a hotspot that unmounts is activated — asserted as
  `document.activeElement !== document.body`, which is the actual defect, rather than asserting a
  particular element and re-encoding the implementation.
- `scenes.test.ts`: **each PNG's header matches its declared `[w, h]` cell**, and all bands of one
  node share one size. Precedent exists — it already reads the foliage sheets' headers and asserts
  width equals `frames × cell`.

### The shared fixture is widened in the same task that needs it

`BLOB.md` §11 trap 9, which has bitten three times on one branch: when a task adds a conditionally
rendered section, the shared fixture must be widened in the same task or every guard downstream goes
quiet rather than red. Two fields are missing today and both are needed:

- the fixture's `nodes` list has **no `salvage` entry at all**, so the heap renders in no test;
- no node row carries a `band`.

Both acceptance-guard runs of `companion.test.tsx`'s *"never shows what has not happened"* — bag
closed and bag open — must actually render node art, and **that must be demonstrated by mutation,
not asserted**. Widening the obvious field is not proof a section renders.

### PHP

- `CompanionBag` band edges, at 0, 1, 4, 5 and a large value, against the authored config rather
  than against literals.
- `CompanionVocabularyTest::sourceFiles()` already lists `scenes/README.md` (line 93) and
  `scenes.ts` (92), and **it scans comments**. The runbook prose added in Batch 1 must dodge the
  banned list — `percent` and the `points` substring trap are the two a pipeline write-up would
  reach for. `docs/BLOB.md` stays deliberately off that list.
- Any new companion source file must be **added** to `sourceFiles()`. A file absent from it is
  scanned by nothing, and this project has been bitten by exactly that.

### Not provable here, and it is the point of the phase

jsdom has no layout engine, and **labels wrap before they overflow** — the reeds' threshold was
measured live at x=52 on a 522px stage. No box arithmetic on this branch models reflow. Whether the
eleven sprites read correctly, and where the four nodes stand, is Batch 3 with the owner's eyes on
it, and the render happens **before** any placement is proposed.

## 8. Batches

| Batch | Delivers | Verifiable remotely |
| --- | --- | --- |
| **0** | The seam: `renderClearing()`, the focus fix, `[50, 44]` into `BLOB.md`, the prettier debt. | Yes |
| **1** | The art: eleven sprites through §4, chosen from composites, `scenes/README.md` updated. | No — this is the batch that needs eyes |
| **2** | The code: config bands, the server's band, the registry, the cells, `NodeLayer`, `NodeSpot`, the tests. | Yes |
| **3** | Look at it, at two widths. Propose positions from the render; the owner decides. | No, by definition |

Batch 0 first because the next twenty tests all target the branch it protects, and because it is the
only batch that is cheap *and* prevents a class of silent failure in the three that follow. Art
before code otherwise, as in F3.5: Batch 2's tests assert against real files, and a placeholder would
have to be replaced and re-verified anyway.

## 9. Out of scope

Named so they are not quietly absorbed:

- **New backdrops.** The four stay as they are.
- **Anything Blob says about a node** through `CompanionRemarks`. The five copy lines per node
  (`met`, `took`, `empty`, `full`, `blunt`) are unchanged.
- **Animating a node.** These are single frames for the same reason the shelter is: a structure and
  a pile both hold still. The tree and the grass exist to carry wind.
- **Per-node thresholds.** One shared band table until a render shows it failing a specific node. It
  would be authored data, so it can earn its place later.
- **The scarf, hat and glasses**, still flat rects; and the hat still needs a different answer
  because it occludes.
- **`companion-glyph.tsx`'s absence from `sourceFiles()`**, open since 16 Sep and unrelated to this
  surface.

## 10. What would make this wrong

- If a node's **object** shrinks as it is harvested, the world has started regressing. Only what is
  standing at it may change — and the heap is the one exception, because a heap is not the world.
- If the thresholds end up in TypeScript as well as PHP, there are two opinions about what `plenty`
  means and they will disagree.
- If band art depends on `known`, a fact about the record has entered the picture of the world, and
  a lock has been drawn with better manners.
- If a node's bands do not share one crop box, registration is gone and the pile jumps as it grows.
- If any clearing test does not go through `renderClearing()`, the seam fix bought nothing and the
  sixth silent pass is already written.
- If a hit region is specified in pixels rather than room units, §1's claim that this phase *closes*
  the label-sizing problem is false — it moved it somewhere invisible, which is worse.
- If a position is changed on a screenshot without the owner, F3's Task 18 ruling has been
  forgotten: placement is theirs, and the render only informs the proposal.
- If a sprite ships lit for a part of day, the neutral-light rule has broken and the wash is being
  applied twice.
