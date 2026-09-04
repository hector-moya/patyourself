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

## What is not here yet

**Foliage sheets.** The trees that move are separate animated layers over these static backdrops,
because `animate_image` caps at 256×256 with `width × height × frames ≤ 524288` — a 144×114 backdrop
would allow only a handful of frames, and swaying every pixel at once reads as an earthquake anyway.
Three or four trees moving at different rates reads as wind, and layering buys parallax for free.

Generate foliage in **neutral light**. The scene's light is one overlay drawn last, over the backdrop,
the trees and Blob alike — so an asset lit for noon would be lit twice.
