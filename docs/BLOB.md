# Blob

Blob is the creature on the companion screen. It arrives in a forest knowing nothing and learns to
live because someone kept a record.

This is the current-state reference: how it works today, why it works that way, and which decisions
must not be reopened. The per-phase specs in `docs/superpowers/specs/` are frozen design records —
accurate for the day they were written and superseded in places since. **Where this file and a spec
disagree, this file is right.**

---

## 1. The one sentence

**Logging is the teaching, not a trigger that dispenses rewards.**

A `failed` outcome advances Blob exactly as far as a `completed` one, because the behaviour being
rewarded is *recording*, not succeeding. The reward is reciprocal rather than transactional: a
creature learns to survive because you wrote down what happened.

## 2. The rules

These have held since the first phase and are load-bearing. Breaking one turns a companion back into
a progress bar.

| Rule | What it forbids |
| --- | --- |
| **Only ever show what has happened** | No locked slot, no greyed-out item, no "next up", no remaining count, no preview. An empty slot is a to-do, and a to-do is a thing to be behind on. |
| **Never state a plan** | The app never names a material Blob does not have or a building it has not made. Inference is welcome — a pile of logs beside a half-built frame says plenty. What is forbidden is the app doing the saying. |
| **Blob's life is never a mirror** | Copy says what Blob did, never how the person is doing. *"Blob can walk. Slowly, and so far in one direction only."* is a creature learning, with no lesson attached. |
| **Nothing regresses** | Once earned, always drawn. No decay, no sad state, no diminished state. |
| **Blob never asks to be pressed** | Pet and Play have no cooldown, no daily limit, no counter. They touch nothing the resolver reads. |

**Banned vocabulary**, enforced by `CompanionVocabularyTest` over a list of source files, comments
included: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`,
`level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` is a substring trap —
*appoints* and *disappoints* trip it.

**Any new companion source file must be added to `CompanionVocabularyTest::sourceFiles()`.** A file
absent from that list is scanned by nothing, and this project has been bitten by exactly that.

**This file is deliberately not on that list**, and must not be added: it quotes the banned words in
order to document them, so scanning it would fail on its own subject matter.

## 3. From record to drawing

There is **no companion table**. Nothing about Blob is stored, so there is nothing to backfill,
nothing to repair, and nothing that can drift out of step with the record it describes.

```
outcomes + summaries in the DB
        |
        v
CompanionResolver::forUser()      pure read; walks the ladder in order
        |
        v
CompanionState                    counts, features, items, abilities,
        |                         room objects, unlocks, scene
        v
CompanionController               + one remark, at most, per visit
        |
        v
Inertia payload -> pages/companion.tsx
```

Two numbers drive everything:

- **`logCount`** — outcomes recorded, of any kind.
- **`insightCount`** — experiments concluded.

The resolver walks the ladder **in order** and stops at the first entry the record does not satisfy,
rather than filtering for every satisfied entry. The ladder alternates ability → item deliberately,
and filtering would hand a `walk` to a Blob that has not appeared yet.

### The known edge case, deliberately unsolved

Deleting an outcome lowers `logCount`, which could take an item back off Blob — the one way this
feature can regress. It needs a user to delete history to happen. If it ever becomes real, clamp with
a single `companion_high_water` integer. **Not before:** a stored high-water mark is exactly the thing
the resolver exists to avoid.

## 4. The ladder

Authored rungs, in `config/companion.php`. `logs` builds the body; `insights` earns everything else.

| Trigger | At | Unlocks | Brings |
| --- | --- | --- | --- |
| logs | 1 | `blob` | — |
| logs | 3 | `legs` | — |
| logs | 4 | `arms` | — |
| logs | 5 | `shoes` | — |
| insights | 1 | `walk` | — |
| insights | 2 | `scarf` | — |
| insights | 3 | `read` | bookshelf |
| insights | 4 | `hat` | — |
| insights | 5 | `wave` | rug |
| insights | 6 | `glasses` | — |
| insights | 7 | `jump` | lamp |
| insights | 8 | `scarf` in coral | — |
| insights | 9 | `carry` | plant |

Past the last authored rung the **tail** takes over: every 3 further insights it recolours one of the
four item types in turn (`item_types` = `shoes, scarf, hat, glasses`) using a variant from
`coral, moss, amber, plum, sand`, and every third of those also brings a room object. The tail is why
the item dictionary never needs a fifth type.

**`shoes` is index 0 in `item_types`, so the very first tail rung recolours the shoes.** Anything
drawing an item must therefore support every variant — see §7.

## 5. Where Blob stands

The scene is **derived, never stored**, exactly as a body form is.

| Scene | Trigger | Contents |
| --- | --- | --- |
| `forest` | `logs: 0` | four baked backdrops, one landmark tree, three grass tufts |
| `cabin` | `insights: 5` | wall, floor, window, and the five room objects |

The arc is `forest → lean-to → hut → cabin → whatever follows`. Today's room **is the cabin** — the
destination, not the thing being replaced. The lean-to and hut are not built; E2 and E3 insert them
*below* the cabin's threshold.

`COMPANION_SCENE` in `.env` overrides the derived scene. It is a **development affordance, not a
setting** — a scene the reader can choose is a setting rather than a consequence of the record.
Without it the forest is unreachable on any established account, which is why it exists.

### Light

Four parts of day (`sunrise` 5, `day` 8, `dusk` 18, `night` 21), each carrying a wall colour, a window
colour, a light colour and a `dim`. `partOfDay()` sorts by start hour and takes the last one that has
begun, which is how night wraps past midnight without a fifth state describing 3am.

The light is **one `<rect>` drawn last**, over the backdrop, the foliage, the room objects **and
Blob**, multiply-blended at the part's `dim`. It is skipped entirely when `dim` is zero — midday needs
no help.

## 6. How Blob is drawn

### Two renderers

`config('companion.renderer')`, from `COMPANION_RENDERER`, defaults to **`sprite`**. The `svg`
renderer draws Blob from vector shapes and still exists; the fixture defaults to it, which is why a
test asserting on `.blob-body` may never touch the renderer production ships. **`.blob-anim` is the
class both emit.**

### Forms

A form is chosen by what the record has unlocked, and each is a whole sheet of 64×64 cells — one row
per animation, frames left to right.

| Form | Sheet | Animations | Foot row |
| --- | --- | --- | --- |
| `blob` | `humus-blob.png` | idle, blink | 51 |
| `legs` | `humus-legs.png` | idle, blink | 53 |
| `arms` | `humus-arms.png` | all eight | 53 |

An animation a form has no row for holds the first idle frame rather than drawing an empty cell.

### One clock, for the whole app

`use-sprite-clock.ts` runs **exactly one `requestAnimationFrame` loop** at module scope; components
subscribe. Two Blobs on one screen therefore breathe in step rather than a beat apart, and the corner
instance on Today does not burn its own frame budget. The loop stops when the last subscriber leaves
and whenever the tab is hidden.

**Never start a second loop.** `use-sprite-clock.test.ts`'s "runs exactly one rAF loop" guards it.

The ambient frame is derived from the **absolute timestamp**, not from mount time. That is what keeps
subscribers in phase — and it is also why the three grass tufts need an explicit `phase` offset, since
otherwise every subscriber to one animation moves in lockstep.

`prefers-reduced-motion: reduce` holds frame 0 and never advances. A reaction still lands, in a single
step, because a button that does nothing is worse than one that does not animate.

### The animation registry

`companion-animations.ts` is data only. Three channels:

- **`ambient`** — what Blob does at rest, loops. `idle`, `walk`, and the self-starting one-shots.
- **`reaction`** — plays once, overrides the ambient, hands back. `pet`, `play`, `notice`.
- **`scene`** — belongs to the world, not the creature. `sway`, `rustle`.

`scene` exists so `sprite-layout.test.ts` can say *why* those two need no sprite row — because they are
not things Blob does. Deriving that exemption by scanning the scene registry instead let the registry
switch off a sprite-sheet invariant at a distance.

Only `ambient` animations carrying `autoEvery` self-fire, and only once the matching ability is earned
— otherwise the body would do things the ladder has not announced.

### Anchors

`sprite-layout.ts` holds five anchors — `head`, `face`, `neck`, `feet`, `hand` — for each of the three
forms: 15 resting positions, plus 148 per-frame offset pairs, written only where a limb actually
carries something. All of it measured off the art, not guessed; `sprites/README.md` records how.

**An anchor's two components are measured from different edges.** `y` is rows down from the cell's top;
`x` is columns from the cell's *centre*, signed — which is why every consumer adds `CELL / 2`. Reading
`x` as an offset from the left edge is what put an accessory 32px off the body.

**Every per-frame delta has `x = 0`.** Nothing ever moves sideways; anchors only translate vertically,
by −8 to +2. That single fact is why a worn item needs one sprite rather than one per frame.

## 7. How the art is made

Four asset classes, three different pipelines. All of it is committed, so a Pixel Lab outage can never
affect the running app.

### Bodies — character states

`create_character` once, then `create_character_state` for each form, because the forms are
*subtractions* from the fullest one. Two `create_character` attempts could not produce a body without
legs at all; the state edit could. Reach for it whenever a form is defined by what it lacks.

### Worn items — a state, then lifted back out

**This is the newest pipeline and the one to copy.** Shoes are the first item drawn this way; scarf,
hat and glasses are still flat rects.

1. `create_character_state` on a form — *the same creature wearing the thing*.
2. Lift the item's own pixels back out: keep only pixels inside the item's band that differ from the
   base by more than 16.
3. Remap the fill tones offline into one cell per variant, leaving the outline alone.

Because every state in a group shares one registration, **the lifted pixels are already in the right
place.** The layer needs no anchor of its own — only the per-frame movement the anchor table already
records. That deletes the entire "item drifts off the body" bug class.

Two things that decide it, both measured:

- **A whole-image difference does not work.** The edit re-quantises the whole body: 62.6% of pixels come
  back changed, though only by a median of 4/255. Restrict to the item's band *and* threshold.
- **An overlay can only add, never remove.** The silhouette survives the edit untouched (0 pixels
  removed), so shoes work. **The hat will not** — its crown *erases* most of the sprout stem, and that
  is a removal.

An object generated standalone is the wrong tool for anything worn: it arrives on its own canvas, in
its own framing, shaded against nothing, and still has to be anchored. Objects are right for things
that also exist in the world — the axe, the handsaw, a coil of rope.

### Scenes — baked backdrops, tinted foreground

Backdrops are `create_image_pro` at **144×114**, which is Blob's own pixel grid: the sprite sheet maps
one sprite pixel to one viewBox unit, and the room's viewBox is 144 units wide. Any other size puts the
character and the world on different grids.

Generate one part of day first, check the camera angle **by eye** — `create_image_pro` has no `view`
parameter — then pass that image as `style_image_url` for the other three. Describing the same forest
four times and hoping is what failed repeatedly; a reference is what worked.

Foliage is separate layers over the static backdrop, because `animate_image` caps at 256×256 with
`width × height × frames ≤ 524288`, and because swaying every pixel at once reads as an earthquake.

**The tree's sway is a procedural shear, not a generation.** The generated sway moved the canopy's
centroid 0.69px while recolouring 20–40% of its pixels every frame — leaves sparkling, not air moving.
The shear introduces no new colours, drops no pixels, and loops exactly. The grass kept its generated
frames, because on a 32px sprite the blades genuinely reshape.

### UI chrome — panels as nine-slices

`create_ui_asset` gives a panel; CSS `border-image` with `image-rendering: pixelated` and
`border-image-repeat: round` makes it fit any box. Slice insets are measured from where the art's own
hollow middle begins, not guessed.

### Where raw images live: nowhere

`create_image_pro` produces **job results, not saved assets**. Pixel Lab's library lists characters,
objects, tilesets, maps, fonts and UI panels — there is no images list, and `add_to_project` will not
take one. The backdrops therefore cannot be found in the Pixel Lab UI at all. **The repo is their only
durable home**, and `scenes/README.md` records every job id.

## 8. Where everything lives

```
config/companion.php                    ladder, tail, scenes, parts of day, renderer
app/Services/Companion/
  CompanionResolver.php                 record -> state, a pure read
  CompanionState.php                    what Blob is right now
  CompanionAnnouncement.php             what an unlock says
  CompanionRemarks.php                  the coach's own line, relayed verbatim
app/Http/Controllers/CompanionController.php

resources/js/hooks/use-sprite-clock.ts  the one rAF loop
resources/js/patyourself/
  companion.tsx                         payload types, ambientFor, selfStartedFor, actionsFor
  companion-animations.ts               the animation registry
  companion-room.tsx                    the scene compositor and the light
  blob-renderer.tsx                     both renderers, worn items, ability props
  sprite-layout.ts                      forms, cells, the 221 anchors
  sprite-items.tsx                      what Blob wears, rects or sheets
  scenes.ts                             the scene registry
  sprites/   + README.md                bodies, worn-item sheets, every measurement
  scenes/    + README.md                backdrops, foliage, the light's reasoning
  ui/        + README.md                the pixel frame and buttons
resources/css/patyourself.css           .pixel-frame, .pixel-button, transition rules
resources/js/pages/companion.tsx        the screen

tests/Feature/Companion/                resolver, tail, scene, screen, vocabulary
tests/Unit/Companion/                   ladder, room config
```

## 9. Rulings that must not be reopened

Each of these was settled with evidence. The cost column is what getting it wrong actually costs.

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **The cabin's threshold is `insights: 5` and never moves** | Later phases insert scenes *below* it. Raising it walks an established record backwards out of a building it already earned. | Frozen on merge. The only later fix is moving a ladder rung instead. |
| **The light covers Blob too** | This reverses `blob-renderer.tsx`'s "a Blob that changes colour with its surroundings is a different Blob" — that rule was about light mode vs dark mode, not standing outdoors at dusk. A creature lit differently from its world reads as pasted on. | A docblock and one `<rect>`. |
| **The light covers the backdrop as well as the foreground** | The spec says "one filter over the whole foreground group"; rendering all four alternatives showed that is wrong. Backdrops are baked already-lit, so tinting only the foreground leaves a bright backdrop behind a dark tree — they disagree *more*. A normal-blend wash turns the tree orange at dusk. | Four `dim` values in config. |
| **Room objects wait for an interior** | `bookshelf` is earned at insights 3 but the cabin arrives at 5, so it banks invisibly for two rungs. No copy ever names a room object — every message is about the ability — so nothing is announced and then missing. | Only fixable later by moving a ladder rung. |
| **Foliage `cell` is a `[w, h]` pair** | The art is 48×64 and 32×24, neither square. Padding to square would put the tree's cell 4 units outside the room's left edge. | One type, two literals. |
| **`FoliageSpec.phase` exists** | The clock derives frames from the absolute timestamp, so three tufts sharing one animation move in lockstep — the exact metronome the layer exists to avoid. | Three tufts move as one. |
| **Scene animations get their own channel** | Deriving the sprite-row exemption from `SCENES` let the scene registry switch off a Blob invariant at a distance: adding a foliage layer named `wave` turned the guard green. | One union member. |
| **The lamp is dimmed by the night wash** | A light source dimmed by darkness is backwards, but `#F2C572` washes to ~`#9E8756` against a wall at ~`#1F2830` — still by far the brightest thing in the room. Excluding it means a special case contradicting one filter over everything. | One conditional. |

## 10. Traps that have already bitten

Every one of these has cost a round on this project.

1. **A test written against a fixture's default asserts nothing.** Five have shipped here. One asserted
   no bookshelf appears outdoors — against a fixture defaulting `room_objects` to `[]`. If you cannot
   name the mutation that turns a test red, it is decoration.
2. **`assertDatabaseMissing` on a column that does not exist is a constant-false predicate.** SQLite
   degrades the unresolvable identifier to a string literal, so it passes forever. On MySQL it errors
   outright — and tests here are SQLite while production is MySQL.
3. **A fallback correct for one case makes a guard unfalsifiable for another.** `backdrops[part] ?? base`
   exists so the cabin can have none — and that is exactly what let the forest lose a backdrop silently
   with the suite green.
4. **Class-name assertions and geometry maths cannot judge a visual.** The shoes shipped into the
   bottom-left corner with the feet bare and every one of 340 tests green, because the tests asserted
   the *layer's* transform and nothing asserted the *cell's* position. Render it and look.
5. **Composite from what the component emits**, never from a reimplementation of its arithmetic. D3
   shipped every worn item 32px off the body with a green suite that way.
6. **Blob's frames freeze under browser automation.** The clock stops on `document.hidden`, so
   `data-frame` reads 0 forever. Trust `data-animation` and `data-part-of-day`.
7. **Check the commit, not the working tree**, before believing anything found while a review is
   running. A reviewer's in-flight mutation once looked exactly like a shipped defect.
8. **Herd serves the main checkout, never a worktree.** Dump to HTML and `php -S 127.0.0.1:8899 -t .`.

## 11. What is not done

- **E2 (tools and materials)** and **E3 (shelter)** are sketched in the Phase E spec, not designed.
- **Scarf, hat and glasses are still flat rects.** The worn-item pipeline in §7 covers them — except the
  hat, which occludes and therefore needs a different answer.
- **Phases B, C and D1/D2 have never been verified in production** — mail arriving, one-click links on a
  phone, the PWA installing. D3 has: Blob renders live.
- **Prop-versus-item collision is unguarded.** Nothing stops a held book and a hanging scarf occupying
  the same pixels.
- **`ROOM_OBJECTS` and `SPRITE_ITEMS` share the prototype-chain fallthrough** that was fixed only in
  `scenes.ts` — `Object.hasOwn` there, a bare lookup in the other two.
- **The Settings area still wears the stock Laravel starter-kit shell.**
- **A catch-up acknowledgement** is an open design question, not code.
