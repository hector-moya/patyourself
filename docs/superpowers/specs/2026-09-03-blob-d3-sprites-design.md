# Blob — D3, Sprites

The Phase D design deferred this one deliberately: "D3 is gated on pixel art, not on engineering. It
gets its own spec when there is art to render." There is art now, so this is that spec.

`config('companion.renderer')` has been a flag since Phase D shipped — `svg` draws, `sprite` returns
`null`. The clock/renderer seam did the hard part already: `use-sprite-clock` knows timing and
nothing about frames, `blob-renderer` knows frames and nothing about timing. What was never settled
is how a layered Blob survives being flattened into a sprite sheet, and that is what this document
decides.

---

## The problem this had to solve first

Blob is layered. Four item types, each recolourable from a palette, plus ability props, composited at
named anchors over a body. In SVG that is one `<g>` per layer and it costs nothing. A sprite sheet is
one flat image per pose, so the naive translation pre-renders every combination — four types times
five-plus colours times eight animations, before ability props — which is absurd.

Two observations dissolve most of it.

**Colour is not a generation axis.** An accessory generated once in a neutral and tinted at render —
an SVG `mask` filled with the palette colour, or a `feColorMatrix` over an `<image>` — costs one
piece of art per type regardless of how many colours the tail eventually names. Alpha-only masking
stays crisp under `image-rendering: pixelated`. This removes the multiplier entirely, and it means
`config('companion.tail.variants')` can grow forever without new art.

**Most anchors do not move.** This was very nearly stated as "a blob has no arms" — the SVG renderer
says Blob "waves with the whole of itself" because there is nothing to raise — but the art that was
actually chosen has both arms and legs, so the weaker claim is the true one and it is enough.

Of the four item types, `hat` and `glasses` sit on the head and `scarf` on the neck. On a creature
whose head is simply the top of one round mass, those ride the body rather than a limb — so their
anchor moves *with* the body, never independently of it, and one number per frame describes the
whole of that movement. Only `shoes` follow an actual limb, and only while walking.

So alignment is per-form, plus a per-frame delta table. **The table is not the exception it was
first drafted as.** The generated art breathes by changing the body's height, which moves the head
with it, so `head` and `neck` carry offsets on any animation that swells or settles — `idle` and
`play` both do. A first draft of this document claimed only `shoes` would ever need entries; the
frames disproved it. The mechanism was right and the estimate of its size was wrong.

The anchor model the SVG renderer already uses survives; it gains an optional per-frame override
rather than being replaced.

The hand anchor is the expensive one, because arms swing freely and a held prop has to swing with
them. It is the only anchor requiring a full per-frame table, it belongs to the two ability props
rather than to any item type, and it is out of scope here. When it arrives it is paid for in the
same delta table rather than in a new mechanism.

## Decisions

### Layered compositing, with alignment in data

Sprite mode keeps the layer model. The body is one `<image>` cropped from a sheet cell; each worn
item and each ability prop is its own layer above it, positioned by an anchor. Nothing is
pre-composited and no combination is ever generated.

The alternative — accessory sheets sharing the body sheet's grid, so alignment lives in the art
rather than in a coordinate table — is the more common paperdoll technique and it is genuinely
better for characters whose limbs carry their equipment. It is rejected here because it costs
roughly thirty cells per accessory per form, and the form axis below multiplies that by the number
of forms. Since none of the four item types hangs off a hand, a table of four coordinate pairs plus
a handful of walk-frame offsets buys the same result.

What this gives up: **the body cannot be rotated or scaled under an accessory.** Rotating pixel art
by a non-integer angle destroys it. The SVG renderer leans on exactly that — `wave` is
`rotate(±9deg)`, `jump` is `scaleY(0.94)` — so sprite mode does not share its transforms. Motion is
baked into the cells, and the compositor only ever translates by whole units.

Limbs may move freely inside a cell; that costs nothing, because no in-scope item hangs off a limb
except `shoes`, which the walk-offset table covers. What must be avoided is the **body mass** tilting
or squashing, since the head and neck anchors ride it and a hat cannot tilt with a head that the
compositor does not know has tilted. That is a constraint on the generation brief for each animation,
and it is the one that has to be checked by looking at the frames rather than by any test.

### A fresh character, not the SVG Blob in pixels

Sprite Blob is its own character rather than a pixel tracing of the vector one. That was weighed
against seeding the sheet from a render of `SvgBlobRenderer` — which would have preserved the
existing anchors exactly — and rejected because the sprite Blob is where the theme below finally
gets to show, and a faithful trace of four rounded rectangles has nowhere to go.

The cost is that `ANCHOR` in `blob-renderer.tsx` no longer describes the body sprite mode draws.
Sprite mode carries its own anchors, measured from the generated art, and the SVG renderer keeps
its own unchanged.

### Blob changes shape, and that is a body feature

The long arc is that Blob does not only accumulate hats — it **changes shape**, a few times, over a
long record. Three forms, all built here.

The creature is called **Humus** in this repository — the soil word, and the root that "human" and
"humble" both grow from. It names the sprite directory and the form identifiers and nothing else:
**in the app it is still Blob**, because that name is woven through the ladder copy, the config
messages, the aria labels and `CompanionVocabularyTest`, and renaming it is a different piece of work
entirely. Pixel Lab exposes no rename, so the character there keeps whatever it was born with and
carries a `humus` tag instead.

The theme is Camus. Blob keeps a record of an inquiry that may not conclude, logs the outcome
whichever way it went, and is fine. That is not a costume added on top: it is a description of the
mechanic that already shipped, where a `failed` outcome advances Blob exactly as far as a
`completed` one because the behaviour being rewarded is recording, not adherence. One must imagine
Blob happy.

**The copy constraint that follows is strict, and it is the reason this is not a Pokémon.** A form
change reads as *change*, never as improvement. Blob does not get better, stronger, or closer to
finished; Blob gets different, and keeps smiling. No form is numbered, no form is named before it
arrives, and the word "level" appears nowhere — `CompanionVocabularyTest` already bans "level up"
and that ban is load-bearing here rather than incidental.

Structurally a form is a `body` feature, which the ladder has carried since Phase A: `kind: 'body'`
already flows resolver → state → props → renderer as `features: string[]`. Two of the three forms
therefore need no ladder work at all — they *are* the existing `blob` and `legs` features. Only the
third introduces a rung, and no trigger moves for any of them.

One renderer rule is new: `features` is additive today (`hasLegs = features.includes('legs')`), but
a form replaces the body rather than adding to it, so sprite mode draws exactly one. Which one is
decided by an **ordered list of form names in the sprite layout**, walked to find the last entry
present in `features` — not by the order `features` happens to arrive in, which is the resolver's
business and not a promise the renderer should lean on.

### The three forms, and how they rescue the `legs` rung

The forms are subtractions from one creature rather than three separate characters:

| Form | Feature | Body |
| --- | --- | --- |
| 1 | `blob` | no arms, no legs — one rounded mass resting on the ground |
| 2 | `legs` | legs, still no arms |
| 3 | `arms` | arms and legs — the full creature |

This ordering does real work beyond looking nice. The ladder's second rung says *"Blob has legs now.
Standing up took most of the day and Blob considers it a fine use of one."* Had form one arrived
with legs already attached, that rung would announce something and change nothing on screen —
precisely the failure D1 was written to correct. Subtracting instead of adding means `legs` at
`logs: 3` visibly grows legs, and the rung is honest for the first time.

**Two `create_character` attempts failed to produce a legless body** — the humanoid skeleton supplies
feet and the description does not override it. `create_character_state` succeeds where they failed
because it edits an existing image rather than generating from a skeleton. That is the technique to
reach for whenever a form is defined by what it lacks.

Form three needs a rung that does not exist: the ladder has exactly two body entries. **An `arms`
unlock is therefore one new config entry and one new line of copy** — the only ladder change in this
branch, and the only place its voice has to be written. It says what Blob can now do and never how
well anyone is performing, like every line beside it.

### Sprites become the default

`COMPANION_RENDERER` defaults to **`sprite`**. This reverses an earlier draft of this document, which
held the flag at `svg` and deferred the flip to a separate deploy — that deferral existed because the
`legs` rung could not be made honest, and the three forms solved it. With the blocker gone there is
nothing left for a second decision to weigh.

The SVG renderer is untouched and becomes the fallback rather than the default. It ignores feature
names it does not recognise, so a record carrying `arms` still draws under it.

The animation coverage this ships with is deliberately uneven: all eight animations for the `arms`
form, `idle` and `blink` only for `blob` and `legs`. Those two forms are visible for a handful of
logs each and `arms` is visible forever after, so the art budget goes where the time is spent.
Anything ungenerated holds the first `idle` frame, which is what `prefers-reduced-motion` already
does — an established behaviour rather than a new compromise.

## Scale, and what it costs

The same sheet serves two very different sizes, and neither is an integer scale:

| Surface | viewBox | Rendered | Scale | Blob's body |
| --- | --- | --- | --- | --- |
| Dashboard corner | 64 units wide at `size={32}` | 32px | 0.5 px/unit | ~22 CSS px |
| `/companion` room | 144 units wide at `max-w-md` | up to 448px | 3.11 px/unit | ~137 CSS px |

The room is fluid, so the scale changes with the viewport and is essentially never integer. Pixel
art dislikes this, and `image-rendering: pixelated` is the mitigation: it drops pixels on the way
down rather than blurring them.

The consequence worth stating plainly is that **detail does not survive the corner and silhouette
does.** At 22 CSS px an accessory is a handful of device pixels — a scarf tail is about two. So the
corner reads as shape, the room reads as detail, and the art budget belongs on body forms rather
than on accessory filigree.

The sprout is the thing to watch here, because it is the whole visual identity and it is also the
thinnest part of the silhouette. Whether it survives at 22px is a question the corner render has to
answer; if it does not, the fix is a stouter sprout on a later form rather than more detail anywhere.

## Architecture

| Thing | Where | Notes |
| --- | --- | --- |
| Sprite implementation | `resources/js/patyourself/blob-renderer.tsx` | fill in `SpriteBlobRenderer`; the signature already exists and does not change |
| Sheets and layout | `resources/js/patyourself/sprites/` | committed PNGs, imported as modules so Vite hashes and cache-busts them |
| Layout and anchors | `resources/js/patyourself/sprite-layout.ts` | animation → row, cell size, foot row, per-form anchors |
| Accessory geometry | `resources/js/patyourself/sprite-items.tsx` | sprite mode's own hard-edged, pixel-grid item shapes |

Sprite mode gets **its own item geometry rather than re-cutting `ITEMS`**. The existing shapes have
rounded corners and a stroke, which is right for the vector Blob and wrong on a pixel grid — but
`ITEMS` is what the shipping renderer draws, so editing it in place would change the SVG Blob that
is live today. Two dictionaries, one per renderer, keeps "the SVG renderer is untouched" true rather
than aspirational. They share the `PALETTE` and the `BlobItem` shape, so a recolour still lands in
both.

The PHP surface is two edits and no more: **one `arms` rung in `config/companion.php`**, and each new
sprite source file added to `CompanionVocabularyTest`'s `sourceFiles()` list. `CompanionState`,
`CompanionResolver` and every migration are untouched — a form is an ordinary `body` unlock and every
consumer already handles those.

### How a cell becomes a drawing

`SpriteBlobRenderer` returns a `<g>`, as the existing signature promises. Inside it, one nested
`<svg>` per layer crops its cell out of the sheet by viewBox, with `image-rendering: pixelated` and
**no `transition` of any kind**. The sprite renderer never calls `bodyTransform`: the easing that
makes the SVG renderer's 2fps idle read as a breath is exactly what makes a frame-swapped sprite
look wrong, and the existing docblock already records that as the one rule that matters.

Placement is derived from one measured constant per sheet — the row the character's feet sit on —
so the body lands with its feet on `FLOOR` and the room needs no changes whatsoever.

### The generation recipe

Recorded so the remaining forms are reproducible rather than rediscovered.

Form one is Pixel Lab character `5c2e3e54-39e1-45f1-9b7c-bf4c89f0872a`, the "Green sprout" state of
`f1115139-0d10-4675-a9e1-4a0571289c5f`. It is `view: "low top-down"` at `size: 48`, 8 directions, 40×44
of content, generated outside `standard` mode. Only the `south` rotation is ever used.

It has arms, legs, blush and an earthy brown body under a green sprout — a lump of matter with
something growing out of it, which is the right creature for the theme above.

**Variants come from `create_character_state`, not from a fresh generation.** The green sprout was
obtained that way: it preserves the source's identity by design, so the result is the same creature
with one thing changed rather than a near-miss that has to be argued about. Describing a look to a
new generation and hoping was tried twice and failed twice. The same route is how forms two and
three should be attempted first, before anything more expensive.

Three findings paid for by generations already spent, recorded so nobody repeats them:

- **`standard` mode cannot reach this look.** Two attempts produced flat, elongated, roughly 1:2
  pears. Standard is the only mode that honours `shading`, `outline` and `detail`, which makes it
  the obvious tier to reach for and the wrong one — those parameters are soft guidance and they do
  not buy the density that `pro` and `v3` give by ignoring them entirely.
- **`view` matters more than any style parameter.** `side` is eye-level and flattens the silhouette;
  `low top-down` looks down about 20° and is what produces the round, chunky, chibi read.
- **A canvas the content nearly fills is the chibi look.** 40×44 of content in a 48×48 canvas is
  almost square. A 64px request returned a 92px canvas holding a 29×59 character, and elongation is
  what made it read as a pear rather than a blob.

The room is drawn flat and side-on while the body is seen slightly from above. That mismatch is
accepted **temporarily**, not permanently: the room is to be rebuilt in pixel art at the character's
own angle, and at that point the two agree and the compromise disappears. Until then it reads as a
character standing in a room rather than as an error, and the alternative — an eye-level character —
costs the silhouette that made this art worth choosing in the first place.

That rebuild is out of scope here and is the reason `ROOM_OBJECTS` stays vector for now: redrawing
furniture twice, once against a side-on wall and again against an angled one, is work this branch
would only have to throw away.

Animations come from `animate_character` with `directions: ['south']`, `mode: "v3"`,
`keep_first_frame: false` so the stored frame count matches `frame_count` exactly. `frame_count`
must be even and at least four, which does not match every entry in the contract:

| Animation | Contract | How |
| --- | --- | --- |
| `walk` | 4f @ 6fps | `walking-4-frames` template, or v3 at `frame_count: 4` |
| `wave`, `jump`, `pet`, `notice` | 4f @ 8fps | v3, `frame_count: 4` |
| `play` | 6f @ 8fps | v3, `frame_count: 6` |
| `idle` | 2f @ 2fps | v3 at `frame_count: 4`, two frames selected |
| `blink` | 2f @ 8fps | two frames, eyes open and shut, authored by hand |

Frame counts and rates in `companion-animations.ts` are the contract and **do not change** — the
clock reads them, and every form must supply every animation at exactly those counts.

## Error handling

- An animation with no row in the sheet falls back to the first `idle` frame rather than drawing an
  empty cell. This mirrors the existing rule that an item type the ladder names but no renderer
  draws is skipped rather than rendered as a gap: naming a thing must never break the screen.
- An item type with no sprite-mode geometry is skipped, exactly as it is in SVG mode today.
- A variant the palette does not know falls back to the item's own colour, unchanged from today.
- A `features` array containing no known form draws the earliest form. Blob is never nothing.
- The SVG renderer ignores feature names it does not recognise, so it keeps drawing today's Blob
  whatever the ladder later names. No regression is possible on the shipping renderer.

## Testing

- The sprite renderer emits one layer per drawn thing, and the body layer's crop matches the
  expected cell for a given `(animation, frame)`. A wrong row or a wrong frame must turn it red.
- **No easing anywhere in sprite mode.** No `transition`, no `transitionDuration`, on any element the
  sprite renderer produces. This is the rule the existing docblock singles out, so it gets a test
  that fails if anyone reintroduces a transition.
- An accessory layer's transform equals the form's anchor, and changing the anchor table moves it.
- `shoes` pick up their per-frame walk offset, and an anchor with no delta entry falls back to the
  form's base anchor rather than to zero.
- An unknown animation renders the idle fallback rather than an empty cell.
- `CompanionVocabularyTest::sourceFiles()` covers every new sprite source file, comments included.
  The list is proven live by watching it fail on a banned word before the file is cleaned.
- Every guard is sabotaged before it is trusted. A test whose failing mutation cannot be named is
  decoration, and D2 found four of those.
- The visual check is a render, not an assertion. Class-name assertions and geometry arithmetic
  cannot judge a drawing. Herd serves the main checkout, so worktree output is dumped to HTML and
  served with `php -S 127.0.0.1:8899 -t .`. Under browser automation `data-frame` reads 0 forever —
  the clock stops on `document.hidden` — so `data-animation` is the trustworthy signal.

## Out of scope

- **A fourth form.** Three is the arc; a fourth is a later spec.
- **Rebuilding the room in pixel art** at the character's angle. Planned, and the reason the room
  and its objects stay vector here.
- **Any change to what earns a rung.** `logs` and `insights` stay exactly as they are. Experience is
  the act of recording — more experiments recorded, more outcomes recorded — and succeeding or
  failing at a loop does not change what Blob gains. That is already the shipped mechanic.
- **The remaining animations for the `blob` and `legs` forms.** They ship with `idle` and `blink`;
  everything else holds the idle frame until there is art.
- **A fifth item type.** The cap is data and `CompanionLadderTest` asserts it.
- **Room objects in pixel art.** The room stays vector this branch; Blob standing in it is the thing
  being proven.
- **Naming or previewing a form that has not arrived**, in any surface.

## Assumptions

- Single user in practice, so no migration or compatibility burden.
- Pixel Lab remains reachable as a plain HTTPS fetch for sheet retrieval. Sheets are committed to
  the repository, so a later outage cannot affect the running app.
- Generation quota is not the constraint. Form one cost four calls — two discarded `standard`
  attempts, one `create_character_state` at the 20-40 tier, and the animations still to come at
  roughly one each — against a refilling budget of two thousand per cycle. Call it 30-50 for a form
  including its mistakes. Review effort is the real budget: every sheet needs a human look, and the
  two discarded attempts were rejected by eye, not by any measurement.
