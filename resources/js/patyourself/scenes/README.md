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

It introduces no new colours, drops no pixels (all twelve frames carry the same 1259 opaque pixels,
asserted at build time), and loops exactly. The art is still a committed PNG on a uniform cell grid
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
