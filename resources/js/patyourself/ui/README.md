# Pixel chrome — the Blob card

Two generated assets, used as CSS nine-slices in `resources/css/patyourself.css`.

| File | Size | Job | What it is |
| --- | --- | --- | --- |
| `panel.png` | 309×176 | `3c043450-f4c6-4550-af62-f70bf43db511` | the frame around the scene |
| `button.png` | 154×66 | `6e7a63bc-ce48-4176-9bfe-3f92cc91c07a` | the plaque under it |

Both from `create_ui_asset`, palette hinted as warm brown wood, mossy green and a dark
ink outline so they sit with the forest rather than beside it.

## Why the chrome stops at this card

Blob's card is the one place in the app that is a world rather than a notebook. The
record below it, the bottom nav and every other screen are deliberately untouched — a
strategy with a hypothesis and a verdict is meant to be read, and pixel chrome fights
that everywhere except here.

## Measured, not guessed

**Panel.** Its corners carry ornaments that are wider than the side rails, so the slice
is measured per side from where the art's own hollow middle begins, by flood-filling the
transparent interior and taking the distance to each edge:

```
interior hole  x 25..284  y 27..153   ->  slice 27 24 22 25  (top right bottom left)
```

`border-image-repeat: round` rather than `stretch`, because stretching smears wood grain
as the card changes width.

**Button.** A 2px outline over a ~8px bevel, so the slice is 10 with `fill` — `fill`
keeps the wood face, and that is the whole difference between a frame and a button.

## Two things that decided the art

**The first button generation was thrown away.** It came back with corner ornaments about
24px across on a 53px-tall plaque, which cannot be nine-sliced — any slice big enough to
capture an ornament leaves no middle to tile. It also had the word "Button" baked into its
face. The replacement was asked for as plain and blank, and slices cleanly at 10.

**The label is DOM text, never art.** A word baked into a bitmap cannot be read by a
screen reader, cannot be translated, and cannot grow with the reader's own text size. One
frame asset therefore serves every action, and adding a button later costs no art at all.

## Verified by looking

Rendered from the real page markup against the built stylesheet, in a 360px container —
the narrowest phone worth worrying about:

```
frame width 360   fits: true
button rows: 1    (Pet, Play, Wave, Jump all on one line)
button height 44  (the smallest comfortable touch target)
```

The 44px is set rather than inferred: the 10px slice eats into the box from both sides, so
leaving the height to whatever the label's line box produced gave about 37px.
