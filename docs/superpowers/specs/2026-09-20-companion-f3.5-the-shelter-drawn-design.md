# F3.5 — the shelter, drawn

**Status:** designed, not yet planned. Completes the art F3
(`2026-09-18-companion-f3-the-shelter-design.md`) deferred, and sits before F4 in
`2026-09-17-companion-progression-arc-design.md`. F1, F2 and F3 are built and merged. Read the arc
and F3 first; this document argues from them and does not restate their reasoning.

`docs/BLOB.md` is the current-state reference and **claims precedence over this file**. Where they
disagree later, it is right.

When this ships, the thing Blob built is visible in the clearing instead of being a word.

## 1. Why this exists, and why it is half a job

F3 shipped the shelter as a text label. Its §7 said so plainly and called the cost: *until the
objects are drawn, the shelter is a label in a clearing and reads as unfinished.* F1 and F2 accepted
the same trade. That is three phases of deferral, and the reason each time was the same — BLOB.md §7
records backdrops as the slowest and least predictable work in the feature, and every visual
judgement stalls on someone being able to look at it.

The deferral has now been paid off once (the four backdrops, the tree, the grass) and the pipeline
that worked is written down. This phase spends it.

**The decision taken at brainstorming is larger than the shelter:** every object in the clearing
becomes drawn, and **the art carries the standing amount** — a full reed bed reads differently from
a sparse one, and the salvage heap visibly shrinks as it is drawn off. No numbers, no labels, no
text in the clearing at all.

That is roughly fifteen sprites plus a new layer, which is two phases by this project's own shape.
So it splits the way F1 split from F2 — **the whole machine, minimal content**, then **pure content
on rails that already work**:

| | Delivers |
| --- | --- |
| **F3.5, this spec** | The mechanism, proven on the shelter alone. Three sprites, a structure layer, the hotspot becoming a control over art, accessible names. |
| **F3.6, not yet specced** | The four nodes — trunk, reeds, branches, heap — at three bands each. Twelve sprites. Retires the last text in the clearing. |

**F3.5 ships one drawn building among four labels.** That is an intermediate and not an end state,
and it is strictly more than exists today.

## 2. Two things deliberately not in this phase

Recorded so a later reader does not think they were forgotten.

### Band logic is F3.6's, not this one's

The obvious-looking move is to build "amount → which sprite" here, with the shelter as a trivial
one-band case. It is the wrong call. The shelter has **stages, not bands** — `stage → sprite` is a
lookup over three authored keys, where `amount → band` is a threshold problem over an **uncapped**
integer (`StockCompanionNodes` increments with no ceiling, so a long-absent account can stand
hundreds at a node). Those are different problems, and the thresholds have no consumer until there
is something to band. Building them now would be guessing at numbers nothing reads.

### `NodeSpot` keeps its label

Reworking the node hotspot into a control over art, while it has no art to sit over, is churn that
F3.6 would immediately redo. It gets one change here and only one: an accessible name (§5).

## 3. The sprite and its frame

Three single-frame PNGs in `resources/js/patyourself/scenes/` — `shelter-lean-to.png`,
`shelter-hut.png`, `shelter-cabin.png`.

**Single frame, and that is a departure from how every other scene asset works.** The tree and the
grass are sheets read by the one shared clock, because they exist to carry wind. A structure exists
to be permanent. It subscribes to no clock, owns no `phase`, and has no row in
`companion-animations.ts`.

**Cell 32×32, bottom-aligned, base on the measured ground line.**

Everything below is derived from numbers already recorded in `scenes/README.md`, not chosen:

```
room viewBox        -72 -38 144 114      so  PNG col = x + 72,  PNG row = y + 38
backdrop ground     PNG row 62           identical in all four, measured
                    -> room y = 24
```

The cell's **bottom edge sits at y = 24**, so with the shelter standing at x = 44 the cell spans
**x 28..60, y −8..24**. Two clearances, both checked:

- the room's right edge is at 72, so twelve units spare;
- the reeds stand at y = 36, twelve units **in front of** the building, which is the correct
  relationship for something at the treeline.

Art need not fill the cell. Transparent rows at the top are expected, exactly as the tree's art
occupies only x 3..44 of its 48-wide cell.

**Scale sanity, and a trap worth naming while measuring against Blob.** There are two renderers and
they do not agree on how wide Blob is. `blob-renderer.tsx` declares `BODY = { w: 44, h: 40 }` and
`FLOOR = BODY.h + LEG_LENGTH = 52`, so the **vector** Blob spans x −22..22. The **sprite** renderer
— which is what `config('companion.renderer')` defaults to and therefore what ships — draws a
visible silhouette closer to 30 units, about x −15..15. BLOB.md §6 already warns the inverse of
this: *a test asserting on `.blob-body` may never touch the renderer production ships.*

Anything measured against "how close is this to Blob" must say which number it used. Use **the
vector's ±22**, as the conservative bound: it is the wider of the two, it is a declared constant
rather than a pixel measurement, and a thing that clears it clears both.

With that settled: a 32-unit building standing twenty-eight units further back than Blob reads as
larger in life and smaller on screen, which is what distance should do.

## 4. How the three are made

The progression chosen at brainstorming is **same footprint, filling in** — one silhouette across
all three, each stage continuing the last rather than replacing it, so the lean-to's slope is still
readable in the hut's roof. That is a *geometric* promise, and it is what rules out the obvious
pipeline.

**Rejected — three independent generations with a chained style reference.** It is what the four
backdrops did and it worked there. It does not transfer: a style reference carries outline, shading
and palette, and **not geometry**. Three generations will not land on one footprint.

**Chosen — generate the lean-to, then state-edit forward.**

1. `create_image_pro`, 32×32, `no_background: true`, for the **lean-to** — the simplest silhouette
   and the base the others fill into. Under the 170px threshold, so four candidates per call.
2. **Style reference `forest-day.png`, full default `style_copy` including the palette.**
   Deliberately unlike the backdrops, which referenced Blob's own sheet and *excluded*
   `color_palette` to stop the forest coming back earth-brown. The opposite is right here: a
   building standing in this clearing should be native to it.
3. **Generated in neutral light.** The scene's light is one overlay drawn last over backdrop,
   foliage and Blob alike; anything lit for noon would be lit twice.
4. **Chosen by eye from a composite** — the candidates over the real `forest-day.png` at the real
   position with the real Blob sprite, never on transparency. The tree's candidate 8 was chosen this
   way precisely because a thing that will never read right in place still looks fine as a cutout,
   and the shelter sits at the treeline where it competes with backdrop detail.
5. `create_object_state` on the chosen lean-to → **hut** (walls close it in), then hut → **cabin**
   (a window and a door).

**Why the state edit rather than generation.** BLOB.md §7 already records the equivalent finding for
bodies: the forms are subtractions from the fullest one, and *two `create_character` attempts could
not produce a body without legs at all; the state edit could.* A state edit preserves registration —
the property that lets worn items need no anchor of their own. Here that same property makes "same
footprint" **structural rather than hoped for**, and "filling in" becomes literally the operation
the tool performs.

**Fallback, named in advance.** Any stage the state edit mangles is hand-composited from the chosen
still. The tree is the precedent: `animate_image` was run, measured (canopy centroid moved 0.69px
while 20–40% of its pixels recoloured every frame), rejected, and replaced with a procedural shear.
Hand-built art is acceptable here; what the architecture requires is a committed PNG on a known
grid, not that Pixel Lab authored every pixel.

**Recorded in `scenes/README.md` as it goes** — every job id and candidate number, matching the
backdrops and foliage. Pixel Lab keeps no images library, so the repo is their only durable home.

## 5. The code

### A static layer, not a foliage layer

One `<image>`, drawn in the existing `!indoors` block immediately after the foliage map — over the
backdrop, under Blob, under the wash. No `useSpriteClock`, no viewBox frame window, no `phase`. The
nested-`<svg>` trick in `FoliageLayer` exists only to step along a row of frames and a building has
one.

### One coordinate, two derivations — and a change of meaning

`SceneSpec.shelter` is `[44, 22]` today and means *where the label's centre goes*. It becomes
**`[44, 24]`, meaning where the building stands — its base centre.** Same place on screen, different
definition. With a module constant `SHELTER_CELL = 32`:

```
art top-left   = (x − CELL/2, y − CELL)   = (28, −8)
hotspot bounds = the same pair
```

One source of truth; neither consumer stores its own copy.

**This diverges from `FoliageSpec.at`, which is a top-left, and the divergence is stated in the
type's docblock rather than left to be tripped over.** Foliage is a cell placed on a grid; a
structure *stands* somewhere. All three stages share one cell, so the size is a constant rather than
per-scene data — if a later scene needs a different one, that is when it earns a field.

The comment above the coordinate is rewritten with it. A comment that contradicts its own coordinate
has cost this project a round twice.

### The sprite registry

`SHELTER_SPRITES: Record<string, string>` in `scenes.ts`, read with **`Object.hasOwn`**. Not
optional: BLOB.md §12 records that `ROOM_OBJECTS` and `SPRITE_ITEMS` still carry the prototype-chain
fallthrough that was fixed only in `scenes.ts`, and a stage named `constructor` resolving to a
truthy function is that bug exactly. The new record follows `scenes.ts`'s lead, not the other two
files'.

### The hotspot stops being the art and becomes a control over it

`ShelterSpot` stays where it is in `companion.tsx` — a real `<button>` over the picture, never a
shape inside the SVG. §7 makes that an accessibility rule and it is unchanged.

What changes: it is sized to the art's bounds, renders **no text**, and carries
`aria-label="Go inside the lean-to"` — naming the action rather than the bare noun, which is a
better accessible name than the one it has today. A hover and `:focus-visible` outline replaces the
label box as the affordance, so it stays discoverable by mouse and by keyboard.

### `NodeSpot` gains an accessible name

Today `NodeSpot`'s accessible name **is its text content** — there is no `aria-label` on it. That is
adequate while it renders a label and becomes a silent regression the moment F3.6 replaces that text
with a sprite. It gains an explicit name now, in the phase that notices, rather than in the phase
that would break it.

## 6. What F3.6 inherits

Stated so the seam is deliberate:

- the structure layer, which generalises to any static scene object;
- `Object.hasOwn` lookup as the established pattern for a new sprite record;
- the hotspot-as-control-over-art shape, with `ShelterSpot` as the worked example;
- accessible names already on both hotspot components;
- and **an unsolved problem: banding an uncapped integer.** How many bands, at what thresholds, and
  what a node at 300 looks like are F3.6's to answer.

## 7. Testing

Vitest:

- The standing stage's sprite is drawn, and **only one** — the clearing-side twin of the gap found
  at F3's final review, where only the lean-to's interior was guarded and both the hut's and the
  cabin's could draw together with the whole suite green.
- Nothing is drawn when no shelter is built.
- Nothing is drawn indoors.
- The control is reachable as `getByRole('button', { name: /go inside/i })`, which fails if the
  accessible name is lost — the precise regression that removing the text would otherwise cause
  silently.
- `NodeSpot`'s accessible name survives its own change.
- `scenes.test.ts` asserts each new PNG's header is **32×32**. Precedent exists: it already reads
  the foliage sheets' headers and asserts width equals `frames × cell`.

**The shared fixture is widened in the same task that adds the sprite, not later.** This trap has
bitten three times on this branch — `available: 0`, then `offer: null`, then `built` and `scene`
together — and every time a guard went quiet rather than red. Both acceptance-guard runs must
actually render the sprite, and that must be demonstrated by mutation rather than asserted.

**Not provable, and it is the point of the phase:** whether it looks right. jsdom has no layout
engine. The visual check is its own task with the owner's eyes on it, and **the render happens
before any placement is proposed**, not after — F3 got that order wrong once and two coordinates
shipped unviewed.

## 8. Batches

| Batch | Delivers | Verifiable remotely |
| --- | --- | --- |
| **1** | The art: three PNGs through the pipeline in §4, chosen from composites, README updated. | No — this batch is the one that needs eyes |
| **2** | The code: the layer, the coordinate's new meaning, the registry, both hotspots, the tests. | Yes |
| **3** | Look at it, in place, at two widths. | No, by definition |

Art first, unlike F1–F3, which all deferred it. The reason is that batch 2's tests assert against
real files and a placeholder would have to be replaced and re-verified anyway — and because the
phase's whole risk is in batch 1. Finding out early is the point.

## 9. Out of scope

Named so they are not quietly absorbed: the four node sprites and their bands (F3.6); new backdrops;
anything Blob *says* about the shelter through `CompanionRemarks`; the interior, which
`CompanionRoom` already draws per stage and which this phase does not touch; and **the node-label
sizing problem**, which F3.6 retires by deleting the labels rather than by fixing the font.

## 10. What would make this wrong

- If two stages are ever drawn in the clearing at once, the upgrade-in-place model has been
  abandoned — the same rule F3 §11 sets for the interior, now binding on the outside too.
- If the shelter's sprite is drawn indoors, "where Blob is" and "what Blob built" have been
  re-conflated, which F3 §1 exists to separate.
- If the control loses its accessible name, the phase has traded a working affordance for a picture.
- If a sprite ships lit for a part of day, the neutral-light rule has broken and the wash is being
  applied twice.
- If the three stages do not share a footprint, the state-edit pipeline has silently fallen back to
  independent generation and §4's reasoning has been lost.
- If a coordinate is changed on a screenshot without the owner, F3's Task 18 ruling has been
  forgotten: placement is theirs, and the render only informs the proposal.
