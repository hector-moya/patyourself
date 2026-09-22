# The forest — backdrops

The clearing Blob arrives in, drawn four times for the four parts of the day.

| File | Part of day | Job | Candidate |
| --- | --- | --- | --- |
| `forest-sunrise.png` | `sunrise` | `186a44ab-934f-4fbf-8aa8-d9ecd8056387` | 3 |
| `forest-day.png` | `day` | `6a25d411-b198-4b79-9a7d-a24453dae4b7` | 0 |
| `forest-dusk.png` | `dusk` | `7f96bba1-c07c-4411-845d-a05557f28cff` | 2 |
| `forest-night.png` | `night` | `f7a547c9-f01f-4787-bc45-5882091e76af` | 0 |

## Why 144×114 and not larger

Blob's sheet maps **one sprite pixel to one viewBox unit** — `CELL` is 64 and spans 64 units. The
room's viewBox is 144×114 units. A backdrop at any other resolution puts the character and the world
on different pixel grids, and Blob reads as a sticker on a painting.

`create_image_pro` will happily produce 512×512 or 688×384. Do not take it. The size is decided by
Blob's grid, not by what the tool can do.

It also lands under the 170px threshold where `create_image_pro` returns **four candidates per call**
rather than one, so the constraint pays for itself.

## How they were made

`create_image_pro`, `no_background: false`, 144×114.

**Sunrise came first and was chosen by eye**, because `create_image_pro` has **no `view` parameter** —
the camera angle lives in the prose and nothing but looking will tell you whether it landed. The whole
phase exists to end the mismatch between a side-on room and a `low top-down` character, so the angle
is checked on the first backdrop and never assumed on the rest.

Its style reference was **Blob's own sheet** with `style_copy: ["outline", "shading", "detail"]` —
deliberately *not* `color_palette`, which would have made the forest earth-brown like the character.

The other three then used **the chosen sunrise as their style reference**, with the full default
`style_copy` including the palette. Describing the same forest four times and hoping is what failed
repeatedly in Phase D; a reference is what worked.

A first attempt asked for a "clearing" but framed it with trees "down both sides" and put the sun at
the vanishing point, and produced four corridors — a path *through* a forest rather than a place to
stay. The fix was to put the treeline across the back only and forbid a path in as many words: *no
path, no track, no trail, no road.*

## Known deviation: the treeline is not pixel-identical

The spec asks that the trees, the ground and the horizon not move between the four, because the scene
**swaps** rather than cross-fades. The ground line holds — it is row 62 in all four, measured rather
than eyeballed, and two otherwise-good candidates were rejected for sitting 18px off it.

The treeline is close but not exact: a yellow crown moves, a couple of silhouettes reshape. This is
accepted rather than fixed. The four are separated by hours in use, never seen side by side, and
chasing pixel-identical foliage across four independent generations costs more than the difference is
worth. If it ever does show, the fix is to generate one treeline and composite four skies behind it,
not to re-roll.

---

# The forest — foliage

The things that move, as separate layers over the static backdrops. `animate_image` caps at 256×256
with `width × height × frames ≤ 524288`, so a 144×114 backdrop could carry only a handful of frames —
and swaying every pixel at once reads as an earthquake anyway. Layers moving at different rates read
as wind, and layering buys parallax for free.

Both sheets are **one row, frames left to right, uniform cells** — the same format as Blob's sheets,
so the same clock reads them. Both are generated in **neutral light**; the scene's light is one
overlay drawn last, over the backdrop, the foliage and Blob alike, so an asset lit for noon would be
lit twice.

| File | Size | Frames | Cell | Source still | Animation |
| --- | --- | --- | --- | --- | --- |
| `foliage-tree.png` | 576×64 | 12 | 48×64 | job `b9e62123-a8d9-4546-8513-86600a514fef`, candidate 8 | procedural shear (below) |
| `foliage-grass.png` | 256×24 | 8 | 32×24 | job `4c3d92b3-66d8-4f6e-a641-844a74ac0c9f`, candidate 2 | job `c039e6d7-2503-4fc4-b685-9fc98d1c2030`, frames 1–8 |

## One tree, not three

The plan said "two or three trees". This ships **one**, at the clearing's edge, plus foreground grass.

The clearing was chosen precisely because it has no trees in the foreground or down the sides — the
first attempt at these backdrops produced four corridors and was thrown away for exactly that. Adding
swaying trees to the foreground would undo the composition it cost four generations to get. One
landmark tree gives wind without closing the space, and leaves E2's axe something obvious to cut.

Candidate 8 was chosen by the owner from a composite of eight candidates over the real
`forest-day.png` with the real Blob sprite at its real position — **not** from cutouts on
transparency, where a tree that will never read correctly in place still looks fine. It has the
longest clear trunk of the sixteen, and a canopy that clears the treeline so it reads as nearer.

## The tree's sway is a shear, not a generation

**This is a deliberate departure from the plan, and the measurement is why.**

`animate_image` was run on candidate 8 (job `d763d544-299e-4ca5-ac38-1e847a18ba16`, 8 frames, kept
for the record). Its output was rejected. Measured across its frames:

- the canopy's centroid moved **0.69px horizontally** — the mass barely moves
- **20–40% of the canopy's pixels changed colour every frame** — 239 to 513 of 1255

That is a boil, not wind. At any frame rate it reads as the leaves sparkling rather than as air
moving through them, and the plan's own acceptance question is "do the trees move like wind rather
than like a metronome?" It also altered the silhouette between frame 0 and frame 1, so the tree that
shipped would not have been the tree the owner chose.

The sheet is instead a **shear of the chosen still**: integer per-row shifts, quadratic in height
above the trunk's foot, so the base never moves and the canopy carries the motion. One full cycle,
right then left, returning to where it started:

```
shift by frame: 0, 1, 2, 3, 2, 1, 0, -1, -2, -3, -2, -1
```

It introduces no new colours, drops no pixels (all twelve frames carry the same 1259 opaque pixels)
and loops exactly. **The pixel count was measured when the sheet was generated and nothing in the
suite enforces it** — checking it again means decoding the image. What the suite does hold is the
sheet's length: `scenes.test.ts` reads each PNG's header and asserts its width is a whole number of
cells and equals `frames × cell` for the animation naming it, which is what a re-roll at a different
length would break. The art is still a committed PNG on a uniform cell grid
read by the one shared clock, which is what the architecture actually requires — the spec's
requirement is the sheet and the single clock, not that Pixel Lab authored every frame.

The grass kept its generated frames: on a 32px sprite the blades genuinely reshape, and blade
reshaping *is* the motion, where a canopy recolouring in place is not.

**Frame 0 is dropped from the grass sheet.** `animate_image` returns the input image unchanged as
frame 0 and the generated frames added blades, so keeping it would pop once per loop.

## Measured, and derived

The room's viewBox is `-72 -38 144 114`, so **PNG col = x + 72** and **PNG row = y + 38**. Blob's
cell is drawn at `translate(-CELL/2, FLOOR - foot)` with `CELL` 64, `FLOOR` 52 and the blob form's
`foot` 51 — cell top-left `(-32, 1)`, which is PNG col 40, row 39.

**Measured** (read off the art, not chosen):

| Thing | Value |
| --- | --- |
| Backdrop ground line | row 62, identical in all four |
| Tree art extent within its cell | x 3..44 of 48 — so a ±3 shear clips nothing |
| Tree canopy / bare trunk boundary | canopy rows 0–31, trunk rows 32–63 |
| Opaque pixels per tree frame | 1259, in every frame |

**Derived** (chosen, then converted):

| Layer | Placement | `at` (viewBox units) |
| --- | --- | --- |
| tree | left edge col 4, base row 70 | `[-68, -32]` |
| grass ×3 | cols 2, 46, 108, bottom-flush at row 114 | `[-70, 52]`, `[-26, 52]`, `[36, 52]` |

`at` is the cell's **top-left**, so the tree's is `base row 70 - 64 = row 6 → y -32`. The tree's top
lands at y −32 inside the room's −38 top edge, with 6 units to spare.

Frame rates are chosen by eye, not measured: `sway` 12 frames at 3fps (a 4.0s cycle), `rustle` 8
frames at 4fps (2.0s). The three grass tufts share one sheet and one animation, so without help they
would move in lockstep — which is the metronome the whole layer exists to avoid. They carry frame
offsets of 0, 3 and 5 instead.

## The light goes over everything, including the backdrop

Worth recording because it contradicts a line in the spec, and because a render is what settled it.

The spec says the foreground is "tinted at render by one filter over the whole foreground group",
which reads as *not* over the backdrop. The plan says the overlay covers "the backdrop, the foliage,
the room objects and Blob". They disagree, and the disagreement is visible: the backdrops are baked
already lit, so at night one is nearly black while the foliage arrives in neutral daylight.

Four alternatives were rendered at night and at dusk — over everything at the configured `dim`,
over the foreground only at the same `dim`, over the foreground only at a much higher `dim`, and a
normal-blend wash. **Over everything won, clearly.** Tinting only the foreground leaves a bright
baked backdrop behind a darkened tree, so the two disagree *more*, not less; pushing the foreground
`dim` high enough to match the treeline then leaves the ground brighter than the things standing on
it; and a normal-blend wash turns the tree orange at dusk and drains Blob to grey at night.

Washing both together is what keeps them in agreement, because relative contrast survives a multiply.
The plan is right and the spec's wording is superseded here.

One consequence, accepted rather than fixed: the lamp indoors is a light source being dimmed by the
darkness around it, which is backwards. At night `#F2C572` washes to about `#9E8756` against a wall
that washes to about `#1F2830` — the lamp is still by far the brightest and warmest thing in the
room, so it still reads as lit. Excluding it would mean a special case that contradicts one filter
over everything, for a difference of saturation.

---

# The shelter

The structure the clearing gets, in the three states Blob's economy funds: an open lean-to, then a
walled hut, then a windowed cabin. Unlike the backdrops and the foliage it is drawn once per stage,
not looped — a structure holds still where the tree and the grass exist to carry wind. `scenes.ts`'s
`shelterSprite()` reads these files by stage, and `companion-room.tsx`'s `ShelterLayer` draws whichever
one is standing.

| File | Object | State | Candidate |
| --- | --- | --- | --- |
| `shelter-lean-to.png` | `ebd97551-a19d-4ca3-9fea-6a0d34dd81d9` | `base` | 0, of 16 (review `47317f9b-670f-4172-9b63-eb5191d20edd`) |
| `shelter-hut.png` | `451c819a-949d-44f8-807f-4957b936cfcd` | `hut` | state edit — see below |
| `shelter-cabin.png` | `061dd3a7-374a-49fe-b62b-ad0710ae11b8` | `cabin` | state edit, then hand-composited over the hut — see below |

All three share group `5b74bd49-6407-4d95-b6c0-752f6a5ed340`; only the lean-to carries a tag
(`f35-shelter`), because it's the one `list_objects` needs to find again. As with the backdrops and
the foliage, Pixel Lab keeps no images library, so this directory is their only durable home.

## One footprint, filled in

The progression is **same footprint, filling in** — one silhouette across all three states, each
stage continuing the last rather than replacing it, so the lean-to's slope should still be readable
in the hut's roof. That rules out the obvious pipeline: three independent generations, even with a
chained style reference the way the four backdrops were made, can't promise it. A style reference
carries outline, shading and palette — not geometry.

So the lean-to is generated once, as an *object* (`create_1_direction_object`), and the hut and the
cabin are `create_object_state` edits on it, in sequence, lean-to → hut → cabin. A state edit
preserves the object's registration, which is what makes "same footprint" structural rather than
hoped for. `create_image_pro`, used for the four backdrops, returns a `job_id` instead of an
`object_id` and cannot be state-edited at all — a different registry, and the reason this pipeline
couldn't reuse that one.

## How the lean-to was made, and two traps in doing it

`create_1_direction_object`, `view: sidescroller`, with a single 48×48 style image passed as
`style_images` (base64). Two things about that call cost time to learn:

- **It forces a square output**, derived from the largest style image, and `style_images` cannot be
  combined with `size`. Asked for 48×40 it returned 48×48 anyway.
- **Base64 style arguments get truncated in transit**, and there's no URL variant. The reference
  below went from ~4,850 base64 characters to ~1,200 after quantising to 32 colours — the difference
  between a call that fails with `broken data stream ... TRUNCATED` and one that succeeds.

A third fact decided the candidate count, not the correctness: **candidates fall as size rises.** 32px
art sits in the ≤42 tier and returns 64 candidates; 48px sits in the ≤85 tier and returns 16. That is
why there are two style crops in this history. The first, at 32×32, produced a review object
(`0d52db24-c750-4f2e-9d6c-9f7de6205e36`, 64 candidates) that was abandoned unpicked — at that size
every candidate was hard to tell from its neighbours, two independent shortlists over the same set
agreed on nothing, and that disagreement was the evidence that decided the move to a larger cell. The
crop that shipped is one 48×48 patch of `forest-day.png` at **PNG col 92, row 14** — `PNG col = x +
72`, so the cell's left edge at room x = 20 is col 92 — the patch of ground the building actually
stands on, not an arbitrary sample of forest. It returned 16 candidates (review
`47317f9b-670f-4172-9b63-eb5191d20edd`), every one legible as a lean-to.

The style crop itself wasn't a raw forest patch: the chosen 32px candidate was composited into it at
its real offset before the 48px call, so one image carried the clearing's palette, its lighting and
the chosen silhouette together, in context. A raw crop plus a separate cutout would have quantised
the cutout's transparency to black and taught the model exactly that.

## The cabin is hand-composited, not generated

The lean-to → hut edit held the geometric promise exactly: same silhouette, walls closing the open
side. The hut → cabin edit did not. Measured directly against the files in this directory: the
generated cabin's opaque pixels span 40 of the hut's full 48 columns — about 17% narrower — which
reads as the building shrinking at its final upgrade, the opposite of what "same footprint, filling
in" promises.

Registration itself was never lost. Every opaque pixel of the generated cabin falls inside the hut's
silhouette — a 100% subset, checked pixel by pixel — so the fallback named in advance for exactly this
case (the tree's rejected `animate_image` output is the precedent: measured, found wanting, replaced
by something hand-built on the same grid) applied here too: layer the generated cabin over the hut
rather than re-roll. The composite adds the window and the door without leaving a hole, and
reproduces the hut's silhouette by construction rather than by luck. `shelter-cabin.png` and
`shelter-hut.png` have **byte-identical alpha channels** — 848 opaque pixels in each, verified
directly rather than assumed — and all three files occupy the same pixel rows, 8 through 43 of 48.

A later reader should not assume all three came straight out of Pixel Lab. Two did. The cabin is the
hut, re-skinned.

## Single frame, because a structure holds still

The tree and the grass are animated because this phase exists to give the clearing wind. The shelter
is a building: it doesn't sway, and it has no states of its own that need a shared clock to read. Each
stage is a single 48×48 frame, not a sheet — there's no `cell` narrower than the whole image to size,
and nothing here for the frame-count checks the foliage sheets get.

## Running this pipeline again

F3.6 generates twelve more sprites with this tooling. Everything above is the post-mortem — why each
choice was made. This is the runbook: what to actually type and call.

**1. The crop command.** The prose above gives a style crop's location as "PNG col, row" — `sips
--cropOffset` takes the same two numbers **in the opposite order, row then column**. Get this backwards
and the crop is silently taken from the wrong patch of forest; this branch already paid once for
confusing a room `x` with a PNG column. For the 48×48 patch at PNG col 92, row 14:

```bash
sips -c 48 48 --cropOffset 14 92 forest-day.png --out style-spot.png
```

**2. The quantise step.** `style_images` is passed as base64 and gets truncated in transit past roughly
1KB — there's no URL variant. The fix used throughout this phase is Pillow's palette quantiser,
`Image.quantize(colors=32)` (`colors=24` was enough for a smaller 32×32 crop), which took one reference
from ~4,850 base64 characters to ~1,200. **Quantise the composited reference, never a transparent
cutout on its own** — quantising a PNG that is still transparent turns the transparency black, and
teaches the model to draw a black backing behind the object.

**3. The candidate-selection rule**, which spec §4 makes binding: judge candidates **composited over
the real backdrop, at the real position, with the real Blob sprite** — never cutouts floating on
transparency, where a shape that will never read correctly in place still looks fine. This is why the
tree's candidate 8 and the lean-to's candidate 0 were both chosen from composites, not from a bare
grid of the raw generations.

**4. The review-object lifecycle.** A generation returns a review object holding every candidate in
`review` status. `get_object` inspects it (list or fetch one candidate); `select_object_frames` keeps
the one chosen, promoting it out of review; `dismiss_review` discards the rest. Nothing here is
automatic — an object left in review is not otherwise cleaned up.

**5. Budget roughly 8 minutes per generation job**, and do not poll it in a tight loop. This phase's
most expensive lesson cost 107k tokens to a poll-wait loop with no working sleep primitive in this
environment, and it lives only in this phase's SDD ledger, which gets deleted after merge — nowhere
durable records it otherwise. Start the job, do something else, come back.

**6. `SendUserFile` is not available to a subagent.** A task brief that names it as the way to show the
owner a candidate sheet will fail for that reason alone, repeatedly, on this branch's own history. Use
`Artifact` instead — publish an HTML page with the composites — and say so if the brief you were given
named the other tool.

---

# The nodes

The four things standing in the clearing, each drawn at the amounts its stock can be in. `scenes.ts`'s
`nodeSprite()` reads these by node and band, and `companion-room.tsx`'s `NodeLayer` draws whichever one
the server says is standing.

**Eleven sprites, not twelve.** Three world nodes get three bands each; the heap gets two. A node
without a skill is a heap rather than the world — `HarvestNode` deletes it once drained and
`CompanionBag::nodes()` skips a skill-less node with no row — so the heap never reaches the client at
nothing-at-all, and a `bare` heap would be art for a state the system cannot produce.

| File | Cell | Object | State | Source |
| --- | --- | --- | --- | --- |
| `node-reeds-bare.png` | 48x41 | — | — | top-cut of `plenty`, 0.62 requested (0.630 realised) |
| `node-reeds-some.png` | 48x41 | — | — | top-cut of `plenty`, 0.35 requested (0.381 realised) |
| `node-reeds-plenty.png` | 48x41 | `92e16390-da65-4a69-96b7-6e9555825e0e` | base | candidate 1 of 16, review `476665e9-e647-4b9f-b3c8-b45e962eab99` |
| `node-deadfall-bare.png` | 44x36 | `58e87787-b039-4240-856c-e9cefb668c89` | base | candidate 0 of 16, review `baa662f5-c396-4b41-a904-cb192889ca05` |
| `node-deadfall-some.png` | 44x36 | `4a5d37e2-a304-477a-b4a6-5c0e6592edca` | base | candidate 2, same review |
| `node-deadfall-plenty.png` | 44x36 | `6104f714-b738-45a3-b51f-2a7b5001bdfc` | base | candidate 1, same review |
| `node-trunk-bare.png` | 48x47 | `9d8265a3-dd4d-4212-98e0-d03a20c43507` | base | candidate 3 of 16, review `98dc6dd7-6a83-4f0d-8961-80a21d3d03dd` |
| `node-trunk-some.png` | 48x47 | `8182a150-c095-42b3-b5f7-02a589940e0b` | `some` | state edit on the base |
| `node-trunk-plenty.png` | 48x47 | `262b3164-491d-4bc8-98f9-5d692733c928` | `plenty` | state edit on `some` |
| `node-salvage-some.png` | 46x35 | `3af7e16c-349a-4d8a-a428-3128e667419c` | `some` | state edit on `plenty`, downward |
| `node-salvage-plenty.png` | 46x35 | `b6a72f8b-6a25-4b1f-9fde-cd25b042c1f1` | base | candidate 4 of 16, review `becf8225-b74c-4727-8230-5e30357a64bc` |

Tags `f36-reeds`, `f36-deadfall`, `f36-trunk`, `f36-salvage` sit on the promoted objects so
`list_objects` finds them again. As with the backdrops and the foliage, Pixel Lab keeps no images
library and this directory is their only durable home.

## Three pipelines, not one, and the measurements that chose between them

The plan assumed one route for all four — generate the lowest band, then `create_object_state` upward.
Three of the four needed something else, and each departure was measured rather than felt.

**The branches use three independent candidates as the three bands, with no state edit at all.** Their
sixteen candidates share one palette — brown sticks on green grass — and differ only in how much wood
is lying there, so three of them ordered by mass already *are* three amounts of one thing: 183, 353 and
437 opaque pixels for candidates 0, 2 and 1. Nothing had to be generated twice.

**The reeds cannot do that, and a render is why.** Their sixteen candidates are sixteen different reed
beds in different palettes, not one bed at sixteen amounts, and apparent quantity follows palette
rather than pixel count: candidate 0 carries 1273 opaque pixels in pale straw and reads as *fewer*
reeds than candidate 14's 741 in dark green. A triple picked by mass rendered as no progression at all.

So the reeds' lower bands are a **deterministic top-cut** of the chosen bed — each column of stalk
loses a share of its height, the base row untouched. That is the tree's shear precedent applied again:
measured, found wanting, replaced by something hand-built on the same grid. It introduces no new
colours because it removes rather than paints, it cannot move the ground line because it only ever
clears from the top, and it gives exact control of mass: 1259, 812, 492 for plenty, some and bare.

A `create_object_state` was tried first and survives in the history only as the reason not to use it:
asked for "about half as many stalks" it returned 1160 against 1259 — an eighth of the reduction
requested. Whatever that edit is good at, thinning fine repeated detail is not it.

**The heap needed a downward state edit and got a good one.** No candidate could serve as its lower
band: all sixteen cluster between 1009 and 1388 opaque pixels, and the smallest is 1009 against the
chosen one's 1084 — a fifteenth under it, not the twentieth once claimed here. The edit down returned
301 against 1084 — a stack of a few boards where a pile had been. The plan had named a hand-composite
fallback here, expecting the downward direction to fail. It did not.

**The trunk's additive edits held the geometric promise exactly**, which is worth recording because the
shelter's did not. Every opaque pixel of `bare` survives into `some` — a zero-loss superset, checked
pixel by pixel — and `some` into `plenty` loses two. Shared pixels move by a median of 6 of 255, with
74 of 1034 past the threshold that counts as a real change. The log is the same log in all three and
only the cut timber beside it grows, which is what the trunk's band model requires: the node is the
world, and the world does not change shape when you take something from it.

## Crop to one shared box — and align first only where alignment is right

All bands of a node are cropped to **one** box, the union of their bounds. Cropping each to its own
would lose registration and the object would slide between bands — the same property that makes the
shelter's three stages share a byte-identical alpha channel.

Whether to bottom-align *before* that crop is per-node, and getting it wrong once is what this
section exists to prevent.

**Align bands that share no registration.** The branches are three independent generations; nothing
relates their cells, so their lowest opaque rows are put on one floor first. The heap's two bands are
a state edit, but that edit genuinely moved the base — `some` came back with its lowest row three
below its source's — so it is aligned too.

**Do NOT align bands that are state edits of one another, or cuts of one image.** They already share
a registration by construction, and that is the entire reason the state pipeline exists.

**The trunk is the worked example of getting this wrong**, and it shipped wrong once before a review
caught it. Its `some` grew cut billets *beneath* the log, which dropped the cell's bbox floor from
row 37 to row 47. Bottom-aligning on that pushed `bare`'s log down ten rows to meet billets that only
exist in the other two bands — so the log jumped down when the trunk was emptied, which is precisely
the hop alignment is meant to prevent. Measured on the shipped files at the time: 174 of `bare`'s 1034
opaque pixels had no counterpart in `some`. Unaligned, that number is zero.

**The bbox cannot tell you which case you are in.** "The floor moved" and "material appeared below
the floor" look identical to it. So this is a judgement made once per node, recorded here, and
checked by rendering the bands in sequence and watching whether the object holds still. The check is
cheap and it is the only thing that catches the error — every count and cell dimension stayed
correct while the trunk was broken.

The shipped cell is whatever that shared box measures, and is deliberately not chosen in advance:
`create_1_direction_object` forces a 48x48 square, and four 48-wide cells cannot stand in a 144-wide
clearing at once.

## Two things the composites caught that arithmetic did not

Both were found by looking, and neither is visible in a cutout on transparency.

**Every generated cell carries 6 to 12 transparent rows below its art.** Composited raw, at the cell's
own bottom edge, all four nodes appeared to levitate in front of the treeline. That is what the crop
step removes, and it is why the crop is not an optimisation: without it the art does not stand on the
ground it is placed on.

**The reeds' style crop had to be clamped.** Their cell at x=50 wants PNG column 98, and 98 + 48 runs
two past the backdrop's own width of 144, so the reference was taken from column 96 instead — the same
ground, two columns left. The clamp is harmless for a palette reference. What it signals is not, and
belongs to placement rather than to art: a full-width cell does not fit the room at that x, which is
one of the things the placement pass has to answer.

---

# The chest

What F4.1 adds: a single object, built out of three planks, holding what the bag cannot carry.
`scenes.ts`'s `chestSprite()` reads this file and `companion-room.tsx`'s `ChestLayer` draws it
whenever the server says one is standing.

| File | Cell | Object | Source |
| --- | --- | --- | --- |
| `node-chest.png` | 34x33 | `1b5024b6-c02c-47fa-b326-4421ddb58438` | candidate 15 of 16, review `73a2dc64-7137-4abf-8f6b-94eaec3cefcc` |

Tag `f41-chest` sits on the promoted object. The review object was dismissed once the choice was made,
so the other fifteen are gone — as everywhere else here, this directory is the art's only durable home.

## One sprite, and no bands

A node is drawn at the amount standing at it because the amount is the thing being decided about. A
chest is a chest: what is inside it is named exactly in the bag, so drawing it at three fullnesses
would buy a picture the modal already gives precisely, at the cost of two more generations and a
second band model. This is the first object here that is deliberately *not* banded.

## The description carries the economy

The prompt asked for **rough weathered planks and no iron**, and that is a rule rather than a
preference: the chest is priced at three planks, and there is no metal anywhere in this world — the
whole material list is fibre, deadfall, timber, rope and planks. A chest with iron bands would be the
art promising a material the economy cannot supply.

## Style crop: the ground it stands on

`sips -c 48 48 --cropOffset 66 92 forest-day.png` — **row then column**, the opposite order from the
prose, which is the same trap the shelter's own runbook records. PNG column 92, row 66 is the
foreground grass the chest actually stands in, not an arbitrary sample of forest. Quantised to 32
colours it came to 732 base64 characters, comfortably under the roughly 1KB where `style_images`
truncates in transit.

## What the crop removed, and why the cell is 34x33

The generated cell was 48x48 with the art's bounds at (8, 9, 42, 42): **nine transparent rows above
and six below**. Cropping to those bounds kept all 843 opaque pixels and cost none — checked rather
than assumed. The shipped cell is therefore 34x33, and as with the nodes it is whatever the crop
measures rather than a size chosen in advance.

## The placement, and the measurement that did not decide it

Worth recording because the arithmetic and the render disagreed, and the render won.

Occupancy was computed first, from every base centre and cell already in `scenes.ts`:

| | x | y |
| --- | --- | --- |
| trunk | -68..-20 | -5..42 |
| heap | -17..29 | 35..70 |
| branches | -56..-12 | 38..74 |
| reeds | 20..68 | 35..76 |
| shelter | 20..68 | -14..34 |
| Blob, as drawn | -15..15 | 1..52 |

Against that, **a 34x33 chest has no collision-free position anywhere in the room** — with or without
a heap. The one candidate small enough to find clean ground (24x23) had eighteen such positions, all
centre-front, and none of them survived a heap either.

So the box test nominated the small chest, and **rendering four placements at two sizes overruled it**:
at `[30, 73]` the full-size chest stands in the open grass to Blob's right with the reed bed behind
it, clear of Blob's silhouette entirely. The bounding boxes call that an overlap with the reeds. The
picture calls it depth, which is the rule F3.6 set — nearer things sit lower and overlap further ones
— and the chest's base at y=73 is nearer than the heap's at y=70, so it paints in front of the salvage
pile as well. Both states were rendered: a clearing with a heap and a clearing without.

The lesson generalises past this object. A rectangle test can tell you that nothing fits; it cannot
tell you which overlap reads as a mistake. Only the render does that, and here it chose the size the
rectangles rejected.
