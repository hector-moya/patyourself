# Humus — the sprite sheets

Three body forms of one creature, drawn as pixel art. The app calls it Blob; `Humus` is the name
used in this directory and in `sprite-layout.ts`, after the soil word that "human" and "humble" both
grow from. A lump of earth with something growing out of it.

Every sheet is a uniform grid of **64×64 cells**. One row per animation, in the order listed below.
Columns are frames, left to right, exactly `ANIMATIONS[name].frames` of them; the rest of a short row
is transparent.

| Sheet | Feature | Size | Rows |
| --- | --- | --- | --- |
| `humus-blob.png` | `blob` | 256×192 | `idle`, `blink`, `sleep` |
| `humus-legs.png` | `legs` | 256×192 | `idle`, `blink`, `sleep` |
| `humus-arms.png` | `arms` | 384×704 | `idle`, `blink`, `walk`, `wave`, `jump`, `pet`, `play`, `notice`, `sleep`, `stretch`, `look` |

`blob` and `legs` widened from 2 columns to 4 when `sleep` was added, because a sheet is as wide as
its widest row and `sleep` is 4 frames. The existing `idle`/`blink` pixels kept their exact position;
the new columns are transparent padding, not a re-crop of anything that was already there.

Cells keep the exact position Pixel Lab returned each frame at. **Do not re-crop or re-centre them.**
Every frame of every animation arrives registered to a shared 64×64 canvas, and that shared
registration is the whole reason accessory alignment is a small table instead of per-frame artwork.

## Where the art came from

Pixel Lab, group `e00eaa56-9fa7-4156-9ff6-f2aee915b617`, view `low top-down`, 48px, 8 directions of
which only `south` is used.

| Form | Character | State |
| --- | --- | --- |
| `blob` | `60d6031a-2699-4da0-9260-f0b5b1bfdd10` | No arms no legs |
| `legs` | `7cb62b7a-b49f-40b7-a40a-054bf8612687` | No arms |
| `arms` | `5c2e3e54-39e1-45f1-9b7c-bf4c89f0872a` | Green sprout |

The forms are **subtractions** from the fullest one, made with `create_character_state`, which edits
an existing image rather than posing a skeleton. Two `create_character` attempts could not produce a
body without legs at all; the state edit could. Reach for it whenever a form is defined by what it
lacks.

## Which frames were kept, and why

`idle` and `blink` are **selected frames, not generated rows.** Pixel Lab's minimum is four frames
and the contract wants two, so a choice was always required — but the choice went somewhere
unexpected.

Every generated `idle` row closes its eyes partway through, twice for the `arms` form even after an
explicit "both eyes wide open the entire time and never blinking". At 2fps that is a creature
blinking permanently, and it collides with `blink` being its own animation.

The `blink` rows turned out to contain a better idle than the `idle` rows did. So:

| Form | `idle` | `blink` |
| --- | --- | --- |
| `blob` | blink frames 0, 3 — eyes open, 1px of breath | blink frames 1, 0 |
| `legs` | idle frames 0, 3 — eyes open throughout, 2px of breath | blink frames 0, 2 — identical height |
| `arms` | blink frames 0, 3 — eyes open, 2px of breath | blink frames 1, 0 — identical height |

`blink` is stored **closed frame first**, because `eyesClosed()` in the SVG renderer returns true for
frame 0 and the two renderers must agree. Its pair is chosen for matching body height wherever
possible: the SVG renderer's own note says holding the body still is what makes a blink read as a
blink rather than as a flinch.

Everything else keeps all of its generated frames in order, except `sleep`, on all three forms — also
**selected frames, not a straight generation**, for a version of the same reason as `idle` above.

A direct 4-frame request for `sleep` — "both eyes closed the whole time" — opened the eyes on frame 0
every time, on all three forms: the same reference-frame bleed that made `idle` unusable, now hitting
the one animation that cannot tolerate it at all (`sleep` has to print `closed` on every frame; nothing
else in this file does). Rather than re-roll the same 4-frame request and hope, each form was
regenerated at 8 frames instead — `v3` mode accepts up to 16 — and 4 closed-eyed frames were picked
from the wider pool. This is the frame-selection fallback the plan names, reached for after the direct
4-frame `sleep` failed its own closed-eyes check, not after three failed re-rolls of it; re-rolling the
same request was never going to fix a bleed that came from the reference frame every request shares.
The two-frame-breath fallback one step further down was not needed on any form.

| Form | Pool frames kept | Why |
| --- | --- | --- |
| `blob` | 1, 6, 7, 1 (of 8) | frame 0 alone opened its eyes; frames 2–5 measure a 1px-deeper head than 1/6/7 do, which is fine on its own but pushes the scarf's tail 1px past the sole — `blob` has the tightest sole clearance of the three forms, none to spare. Kept to the three frames that measure identically to `idle`, repeating frame 1 for the fourth column; the mouth still varies frame to frame, so the loop is not fully static |
| `legs` | 0, 3, 5, 7 (of 8) | the only pool where all 8 frames came back closed-eyed already; frames chosen purely for a breathing arc that closes the loop (frame 0 and frame 7 sit at the same head height) |
| `arms` | 2, 3, 5, 7 (of 8, second pool) | the first 8-frame pool opened its eyes on frames 0 *and* 7, and its closed-eyed frames 1/3/5/6 carried a bright open-mouth pixel that reads as an eye highlight to the measurement script (the same false positive `pet` already has, see below) — so `arms` alone was regenerated a second time asking for a closed mouth too; the second pool has five unambiguously closed-eyed frames (1, 2, 3, 5, 7) and the four kept are the ones whose head height closes the loop cleanly (frame 2 and frame 7 match) |

Pixel Lab job ids, for the record: `sleep` on `blob` — animation group `86ff1923-fe65-4ade-91d7-39d7439135a2`
(discarded 4-frame attempt) and `d589a0e4-95ff-4198-a35c-0410ea768e25` (8-frame pool, used). `sleep` on
`legs` — `7517ee99-5e42-4168-9d4e-dcce5a78536d` (discarded) and `f76bdaa1-3af6-41fe-a5a0-9a1978ed5bfc`
(pool, used). `sleep` on `arms` — `796511e2-2851-415e-8a2a-d642a5523b92` (discarded 4-frame),
`d909ffa7-3ffe-45df-8c5c-f11158490630` (discarded 8-frame pool, mouth artifact) and
`db2a3055-ce79-44d5-876a-ab5b7bde4926` (8-frame pool, used). `stretch` and `look` on `arms` were kept
from their first, direct generation — `aaa542bb-92bf-4079-bfb2-ce536b5bc007` and
`fb14171b-57d9-4960-a9d3-8a432aec6a2e` — with no selection needed.

## Measured constants

All measured from each form's `idle` frame 0. `x` is measured from the cell's centre line, `y` from
its top edge.

**Corrected in review round 1 of the sprite-items task.** The original method for `head` — "the
first row holding a non-green pixel, so the sprout above it is not mistaken for the head" — sounds
right but isn't: the sprout's own outline is drawn in black, which is itself a non-green pixel, so
every `head` value below landed on the leaf tip, 8–12px above the skull it was meant to find. The
gap was not a constant, so nothing downstream could compensate for it with a fixed offset. Skin,
outline, sprout and eye highlight are now separated by **colour** rather than by position:

- `head` — the first row carrying six or more skin-toned pixels. The actual top of the skull.
- `face` — the row with the most white eye-highlight pixels (the specks in the eyes). Glasses hang
  off this, not `head`: the two do not move together on every frame (see Offsets below), and hanging
  glasses off `head` put them on the mouth during `notice` and on the forehead during `jump`.
- `neck` — `face + 9`. **Corrected in review round 2.** This was originally "the widest body row",
  blessed as unchanged because its numbers hadn't moved — but the method was wrong from the start on
  a creature with no neck that isn't the throat. The widest row is the shoulders on `blob` (right by
  luck), *above the eye line* on `legs`, and the raised arm itself on `arms` — so a scarf crossed the
  mouth on `legs` and sat at the hips, tail through the floor, on `arms`. The mouth's bottom lip
  measures at `face + 6` on every form — a single-row mouth on `blob` and `legs`, an open one spanning
  `face + 4` to `face + 6` on `arms` — so `face + 6` is the lowest row of it on every form even where
  it isn't the only row; a collar belongs a few rows below that, hence `face + 9`.
- `feet` — the lowest opaque row. Unchanged.
- `hand` — the body's right edge, at the midpoint between skull and feet, on every form including
  `arms`: the ability props anchored here are simple shapes rather than something held in a drawn
  hand, so a consistent measurement matters more than tracking the arm itself. It still has to
  follow the body *vertically*, though — see the note under Offsets on how those rows are derived
  rather than measured.

| Form | `foot` | `head` | `face` | `neck` | `feet` | `hand` |
| --- | --- | --- | --- | --- | --- | --- |
| `blob` | 51 | `[-1, 21]` | `[-1, 34]` | `[-1, 43]` | `[-1, 51]` | `[16, 36]` |
| `legs` | 53 | `[-1, 19]` | `[-1, 32]` | `[-1, 41]` | `[-1, 53]` | `[14, 36]` |
| `arms` | 53 | `[-1, 21]` | `[-1, 34]` | `[-1, 43]` | `[-1, 53]` | `[18, 37]` |

### Foot centres, for the shoes

`blob` has no legs, so its foot nubs are wider and set further apart than the narrower feet `legs`
and `arms` grow. Measured two rows above the sole — where a nub is at its widest, rather than
tapering into the floor it stands on — `x` from the centre line:

| Form | left foot centre | right foot centre | each foot's width |
| --- | --- | --- | --- |
| `blob` | −10 | +8 | 12 and 11 px |
| `legs` | −7 | +7 | 9 px |
| `arms` | −7 | +7 | 9 px |

One shared shoe rect cannot fit both a 12px-wide nub at −10 and a 9px-wide one at −7, which is why
`SPRITE_ITEMS.shoes` in `sprite-items.tsx` reads the form rather than drawing the same rect for all
three.

### Offsets

How far an anchor has moved from that form's base anchor, per frame. **A zero here is a measurement,
not a gap left to fill.** Any animation absent from a form's table needs no offsets at all. **All
horizontal components are zero** — nothing in these animations translates sideways.

These are not a garnish. The body breathes by changing height, which carries the skull with it, so a
hat pinned to one fixed row drifts off during anything that swells.

**`neck`'s own delta is `face`'s, not a separate measurement — corrected in review round 2.** An
earlier pass here cited `wave` moving the (then widest-row) `neck` by −15px and `notice` by −9px,
against heads moving only −3px and −6px, as evidence that `neck` had to be measured independently.
That argued for the wrong thing: those larger numbers were the same "widest row" bug as the anchor
itself, reading the raised arm mid-`wave` rather than anything near the throat. Now that `neck` is
`face + 9`, it rides the skull exactly as `face` does, so every `neck` row below is a copy of that
animation's `face` row.

Where the eyes are **closed** — every frame of `pet` after the first, and the closed frame of
`blink` — the eye line cannot be measured, so `face` takes `head`'s own delta for that frame. The two
ride the same skull, so this is a derivation rather than a second guess, and it shows up below as
`head` and `face` simply carrying identical numbers on those rows.

**`hand`'s rows are derived, not measured. Every other row in this file was read off the art; these
were not, and the distinction matters when the next form is built.** The hand sits mid-body, so its
row is `round((head delta + feet delta) / 2)` — the average of how far the two ends of the body
travelled. Ties round **upward** — toward positive infinity, which is `Math.round`'s own behaviour,
and further down the cell, since `y` grows downward here. The art agrees: `blob`'s idle averages
−0.5 and measures 0, `legs`' averages +0.5 and measures +1, and both of those are the higher of the
two candidates.
`derivedHandDelta` in `sprite-layout.ts` is that arithmetic, and a guard holds every row of the
table to it, so a later re-measurement of `head` or `feet` cannot leave `hand` behind.

Derived rather than measured because a direct reading conflates the body with the arm: on `arms` the
rightmost pixel at the mid-body row *is* the raised arm during `wave`, `jump` and `play`, so
measuring it would make a prop swing with a limb the prop is explicitly not held in. Every derived
row was checked against the art anyway, by compositing the props over the real cells and looking:
the derivation agrees with a direct reading everywhere within one row, and the frames where it
differs by one (`walk` 2, `jump` 0–1, `pet` 0–2, `notice` 2) are all frames where the direct reading
is the arm rather than the body.

A prop needs this. Without it the book stayed level while Blob hopped — roughly 17 CSS px of daylight
at room scale on `play` frame 3.

```
blob   idle    head [0,-1]   face [0,-2]   neck [0,-2]
               hand: derives to [0,0] on both frames, so no row
       sleep   absent — the four frames kept (see "Which frames were kept, and
               why" above) all measure identically to idle frame 0

legs   idle    head [0,1]    face [0,2]    neck [0,2]    hand [0,1]
       blink   head [1,0]    face [1,0]    neck [1,0]    hand [1,0]
       sleep   head [1,2,2,1]   face: closed throughout, so it takes head's own delta
               neck [1,2,2,1]   hand [1,1,1,1]

arms   idle    head [0,-2]   face [0,-3]   neck [0,-3]   hand [0,-1]
       walk    head [-2,-3,-3,-2]      face [-2,-3,-3,-2]      neck [-2,-3,-3,-2]
               feet [0,0,-1,0]         hand [-1,-1,-2,-1]
       wave    head [-1,-2,-3,-2]      face [-1,-3,-3,-2]      neck [-1,-3,-3,-2]
               hand [0,-1,-1,-1]
       jump    head [0,-3,-6,-4]       face [2,-1,-6,-4]       neck [2,-1,-6,-4]
               feet [0,-1,-4,-1]       hand [0,-2,-5,-2]
       pet     head [0,0,0,-1]         face [0,0,0,-1]         neck [0,0,0,-1]
               hand: derives to [0,0,0,0], so no row
       play    head [-1,-3,-5,-6,-4,-2]   face [-1,-3,-5,-7,-5,-3]   neck [-1,-3,-5,-7,-5,-3]
               feet [0,0,-2,-5,-2,0]      hand [0,-1,-3,-5,-3,-1]
       notice  head [-2,-6,-4,-2]      face [-3,-8,-6,-4]      neck [-3,-8,-6,-4]
               feet [0,-3,-4,-1]       hand [-1,-4,-4,-1]
       sleep   head [-1,-1,2,-1]   face: closed throughout, so it takes head's own delta
               neck [-1,-1,2,-1]   hand [0,0,1,0]
       stretch head [-1,-3,-4,-3,-2,-1]   face [-1,-3,-5,-5,-3,-2]   neck [-1,-3,-5,-5,-3,-2]
               hand [0,-1,-2,-1,-1,0]
       look    head [-1,-1,-1,-1]   face [-1,-2,-2,-2]   neck [-1,-2,-2,-2]
               hand: derives to [0,0,0,0] on all four frames, so no row
```

Each bracketed list is that anchor's y-delta per frame, in order — `[0,-1]` on a 2-frame animation
means frame 0 is unmoved and frame 1 is 1px higher, not a single `(x, y)` pair. `blob` and `arms`
carry no `blink` entry at all: holding the skull still is what makes a blink read as a blink rather
than a flinch, the same reading the SVG renderer gives its own blink — and now that `neck` follows
`face`, it holds still along with the rest of the skull rather than carrying a delta of its own.

An anchor whose derivation or reading is zero on every frame gets no row, which is why `hand` is
absent from `arms`'s `pet` and `look`, and why `blob` has no `hand` row on any animation at all —
`idle` derives to zero on both its frames, and the four `sleep` frames kept were chosen so the body
does not move at all (see "Which frames were kept, and why" above), so `sleep` needs no row of any
kind, the same as `blink` on this form and on `arms`.

`stretch`'s one closed-eyed frame — frame 1, the mid-stretch squint — takes `head`'s own delta for
`face`, the same convention every other closed frame in this file uses. `look`'s closed-eyed frame is
frame 0. Neither row gets a `feet` entry: both briefs are explicit that the feet stay on the ground
through the whole animation, and the art agrees — every frame measures zero.

**`sleep`'s pose is deliberately upright, on all three forms, and that is a constraint on the art, not
a stylistic choice.** A sleeping creature that lies down or curls up both rotates its body and
translates it sideways — exactly the two things every other animation in this file has always avoided,
and the reason is not aesthetic. Every per-frame anchor delta above has an `x` component of `0`, which
is what lets a hat, a scarf or a pair of glasses be one sprite that rides the body rather than one
hand-placed drawing per frame of every animation. A sleeping Blob that curled up would need its own
per-frame accessory art, or would need to go without accessories while asleep — either way, the small
table this file is would stop being able to describe it. Sitting upright with the head merely tipping
forward keeps `sleep` inside the same contract as `wave`, `jump` and everything else: reachable by a
delta table, not by bespoke art.

## Adding a form

1. `create_character_state` on the fullest form, describing the change. Prefer this over a fresh
   `create_character`: it keeps the same creature rather than producing a near-miss to argue about.
2. `animate_character` with `mode: "v3"`, `directions: ["south"]`, `keep_first_frame: false`, and
   `frame_count` matching the contract. Brief every animation with the body staying upright and
   level — the head anchor rides the body mass, and a hat cannot tilt with a head this table does not
   know has tilted.
3. Reject any frame where the body rotates. That is a re-roll, never something to fix in the table.
4. Compose the sheet, measure everything above, and add an entry to `FORMS` in `sprite-layout.ts`.

Sheets live in this repository, so a Pixel Lab outage can never affect the running app.

---

# Worn items, drawn from art rather than rects

| File | Size | Cells | Source state |
| --- | --- | --- | --- |
| `humus-legs-shoes.png` | 384×64 | 6 | `6542139b-28ce-4a87-9fd8-69a1e5b8e1d8` (Boots legs form) |
| `humus-arms-shoes.png` | 384×64 | 6 | `b05731d8-c634-4d99-bc0e-a7cad7cb5a97` (Boots spike) |

Shoes are the first item drawn this way. The other three are still rects.

## Why a state, and not an object

An item generated as a standalone *object* arrives on its own canvas with its own framing, and would
then have to be anchored to a foot that moves every frame — more alignment work, not less, and shaded
against nothing. Generated as a character **state** — the same creature wearing the thing — it is
drawn against the real body, in the real palette, by a model that already knows what the creature
looks like.

Then the item's own pixels are lifted back out. Every state in a group shares one registration, so
the lifted pixels are already where they belong: **the layer needs no anchor of its own.** All it
follows is the anchor's per-frame movement, which the table above already records.

## The measurements this rests on

| Fact | Value | Why it matters |
| --- | --- | --- |
| Rotation → cell offset | `dx 8, dy 8` | searched, not assumed; agrees with idle frame 0 on 97.9% of the cell |
| Body drift from the edit | median **4/255**, max 38 | small enough to threshold away; the silhouette is untouched |
| Pixels removed by the edit | **0** | an overlay can only add, so an occluding item could not work this way |
| Anchor movement, all five anchors | x is **always 0**, y −8..+2 | one sprite per item, not one per frame |
| `legs` vs `arms` under the boots | 147 of 300 px differ | one extraction cannot serve both forms |

The last row is why there are two sheets. The x-always-0 row is why there is only one cell per
colour rather than one per frame.

## Extraction, and the two traps in it

Lift only pixels inside the item's own band that differ from the base by more than 16. Both halves
matter:

- **The threshold alone is not enough.** The edit re-quantises the whole body, and a few dozen of
  those pixels clear 16 — up on the skull and in the sprout. Recoloured, they showed as confetti in
  the leaves. Restrict to the band the item occupies.
- **A whole-image difference does not work at all.** 62.6% of body pixels come back changed.

## Recolouring

The tail recolours every item type in turn, and `item_types` lists `shoes` first — so the very first
tail rung recolours them, and a single fixed-colour PNG would have made the ladder's own message
("Blob swapped to the coral shoes") a lie.

So each sheet carries one cell per variant, remapped offline: the boot's four fill tones are mapped
onto the target colour keeping their relative luminance, and **the outline is left alone**. An
outline that recolours with its fill flattens the shape. Cell 0 is `slate`, the colour the art was
generated in, and is what an unknown variant falls back to.

## What a render caught that the tests did not

The cell draws at `x=0, y=0`, because the enclosing group is *already* translated to the cell's
top-left. Shifting it by another half-cell — which looked right, since that is what every
anchor-relative layer does — put the boots in the bottom-left corner with the feet left bare, while
every assertion stayed green. The transform tests were checking the layer; nothing was checking the
cell. There is a guard for it now, added after the picture showed the bug.
