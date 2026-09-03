# Humus — the sprite sheets

Three body forms of one creature, drawn as pixel art. The app calls it Blob; `Humus` is the name
used in this directory and in `sprite-layout.ts`, after the soil word that "human" and "humble" both
grow from. A lump of earth with something growing out of it.

Every sheet is a uniform grid of **64×64 cells**. One row per animation, in the order listed below.
Columns are frames, left to right, exactly `ANIMATIONS[name].frames` of them; the rest of a short row
is transparent.

| Sheet | Feature | Size | Rows |
| --- | --- | --- | --- |
| `humus-blob.png` | `blob` | 128×128 | `idle`, `blink` |
| `humus-legs.png` | `legs` | 128×128 | `idle`, `blink` |
| `humus-arms.png` | `arms` | 384×512 | `idle`, `blink`, `walk`, `wave`, `jump`, `pet`, `play`, `notice` |

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

Everything else keeps all of its generated frames in order.

## Measured constants

All measured from each form's `idle` frame 0. `head` is the top of the skull, found as the first row
holding a non-green pixel so the sprout above it is not mistaken for the head. `neck` is the widest
body row. `x` is measured from the cell's centre line, `y` from its top edge.

| Form | `foot` | `head` | `neck` | `feet` | `hand` |
| --- | --- | --- | --- | --- | --- |
| `blob` | 51 | `[-1, 11]` | `[-1, 37]` | `[-1, 51]` | `[16, 31]` |
| `legs` | 53 | `[-1, 9]` | `[-1, 31]` | `[-1, 53]` | `[14, 31]` |
| `arms` | 53 | `[-1, 11]` | `[-1, 41]` | `[-1, 53]` | `[18, 32]` |

`hand` on `blob` and `legs` is the body's own side edge — neither form has arms, and an ability prop
still has to land somewhere sensible rather than at the origin.

### Offsets

How far an anchor has moved from that form's base anchor, per frame. **A zero here is a measurement,
not a gap left to fill.** Any animation absent from a form's table needs no offsets at all.

These are not a garnish. The body breathes by changing height, which carries the head with it, so a
hat pinned to one fixed row drifts off during anything that swells. `play` moves the head 5px and the
feet 5px; `notice` lifts the feet 4px clear of the ground. `head` and `neck` share the same numbers,
because both ride the same body mass.

```
blob   idle   head/neck  [0,0] [0,-1]              feet  [0,0] [0,0]
       blink  head/neck  [0,1] [0,0]               feet  [0,0] [0,0]

legs   idle   head/neck  [0,0] [0,2]               feet  [0,0] [0,0]
       blink  head/neck  [0,1] [0,1]               feet  [0,0] [0,0]

arms   idle   head/neck  [0,0] [0,-2]              feet  [0,0] [0,0]
       walk   head/neck  [0,-2] [0,-3] [0,-3] [0,-2]
              feet       [0,0] [0,0] [0,-1] [0,0]
       wave   head/neck  [0,-1] [0,-2] [0,-2] [0,-1]
              feet       [0,0] [0,0] [0,0] [0,0]
       jump   head/neck  [0,1] [0,0] [0,-3] [0,-1]
              feet       [0,0] [0,-1] [0,-4] [0,-1]
       pet    head/neck  [0,0] [0,1] [0,1] [0,0]
              feet       [0,0] [0,0] [0,0] [0,0]
       play   head/neck  [0,0] [0,-1] [0,-3] [0,-5] [0,-4] [0,-2]
              feet       [0,0] [0,0] [0,-2] [0,-5] [0,-2] [0,0]
       notice head/neck  [0,0] [0,-2] [0,-4] [0,-2]
              feet       [0,0] [0,-3] [0,-4] [0,-1]
```

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
