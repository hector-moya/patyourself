# Blob

Blob is the creature on the companion screen. It arrives in a forest knowing nothing and learns to
live because someone kept a record.

This is the current-state reference: how it works today, why it works that way, and which decisions
must not be reopened. The per-phase specs in `docs/superpowers/specs/` are frozen design records —
accurate for the day they were written and superseded in places since. **Where this file and a spec
disagree, this file is right.**

Its siblings hold the rest of the app on the same terms: `docs/NOTEBOOK.md` (the record Blob reads
from), `docs/WORKFLOWS.md`, `docs/GYM.md` and `docs/MCP.md`.

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
| **Never state a plan** | Naming the thing the player should go and get, or previewing a building that has not been made. A price is not a plan: a recipe stating what something costs — in materials, or in a tool — is what a recipe has always been, and stays allowed. Inference is welcome — a pile of logs beside a half-built frame says plenty. What is forbidden is the app doing the saying. |
| **Blob's life is never a mirror** | Copy says what Blob did, never how the person is doing. *"Blob can walk. Slowly, and so far in one direction only."* is a creature learning, with no lesson attached. |
| **Nothing regresses** | Once earned, always drawn. No decay, no sad state, no diminished state. |
| **Blob never asks to be pressed** | Pet and Play have no cooldown, no daily limit, no counter. They touch nothing the resolver reads. |

**The one exception, and its exact shape.** A player may destroy a material they are carrying. That
is not this rule breaking: every prohibition in it names something *the system does to you* — decay,
expiry, upkeep, removal for inactivity — and a player tipping out timber is the same category as
spending fibre on a basket. The line that holds instead is that **destruction is always
player-initiated and never automatic.** Nothing may drop, decay, expire or discard on the player's
behalf, for any reason, including a full bag. `CompanionRulingsTest::test_nothing_is_dropped_on_the_players_behalf`
is the guard.

**Banned vocabulary**, enforced by `CompanionVocabularyTest` over a list of source files, comments
included: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`,
`level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` is a substring trap —
*appoints* and *disappoints* trip it.

**A sleeping Blob is a function of the clock alone, never of the record.** Nothing about whether
Blob sleeps reads `logCount`, `insightCount` or any unlock — only the browser's hour and
`config('companion.room')` say so. That is the rule most at risk here: the day this starts reading
what has been recorded, a state of being alive has quietly become a reward.

**Any new companion source file must be added to `CompanionVocabularyTest::sourceFiles()`.** A file
absent from that list is scanned by nothing, and this project has been bitten by exactly that.

**This file is deliberately not on that list**, and must not be added: it quotes the banned words in
order to document them, so scanning it would fail on its own subject matter.

## 3. From record to drawing

**Store only what cannot be derived.** Six tables hold the chosen half of Blob — `companions`
(the name, `xp_spent`, `shelter` — what has been built — and `salvaged_at` — whether a granted
cabin was already handed back), `companion_skills`, `companion_items`, `companion_stash_items`,
`companion_nodes` and `companion_remarks` (the coach's own lines, written via `WriteBlobRemark` and
the MCP tool, read on every `/companion` request). Nothing else. Counts, earned XP, the scene and
the whole gift ladder are still recomputed from the record on every read, so the parts that could
drift still cannot.

`companion_stash_items` is its own table rather than a `location` column on `companion_items`,
because `Companion::held()` and `capacity()` both sum `$this->items` unfiltered — a `location`
column would make both silently wrong while every test written before F4.1 stayed green, since
every one of them puts every row in one place. Measured rather than assumed: pointing
`stashItems()` at `CompanionItem` gave `held() = 11` and `capacity() = 10` against an expected 2
and 5. §10 has the ruling.

Where Blob is *looking* is deliberately not among them. `inside` is `useState(false)` in
`resources/js/pages/companion.tsx`, with a comment saying why, and it resets to outside on load the
way the bag itself does — a view, not a thing anyone owns.

The rule survived its own replacement: the pre-F1 premise was "nothing is stored, so nothing can
drift", and what made that valuable was never the storage count. It was that no stored number
claims to describe the record. A choice is not a claim about the record, which is why a choice may
be stored and a count may not.

```
outcomes + summaries in the DB     companions + its four tables     companion_remarks
        |                                    |                       (+ one remark, at
        v                                    v                        most, per visit)
CompanionResolver::forUser()        CompanionBag::forUser()                 |
CompanionWallet::balanceFor()       what is held, buildable, learnable      |
        |                                    |                              |
        +------------------+-----------------+------------------------------+
                            v
                   CompanionController
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

**This is the gift track.** It hands out the body and the wearables from the record — unchosen,
unpurchasable, and untouched by either F1 or F2. XP is the other track, the one a player spends;
see §8.

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

### XP, the other track

Every outcome and insight pays XP as well as feeding the counts above. Earned XP is derived the
same way `logCount` is — recomputed from the record on every read — and only the *spending* is
stored; see §8 for the full mechanics.

| Source | XP |
| --- | --- |
| An outcome recorded — 1st of the day | 3 |
| — 2nd of the day | 2 |
| — 3rd and every one after | 1 (floor; never zero) |
| A reflection written | 5 |
| A loop's chain corrected | 8 |
| A new strategy version started | 10 |
| An experiment concluded | 15 |

The four insight rates sit outside the taper — they are rarer by nature, and rate-limiting them
would double-count. The outcome taper groups by `logged_at`, not by the occasion a log describes,
so a catch-up session logging seven days at once tapers as **one** day and pays 10, not 21 — the
taper rewards showing up, and you showed up once. A `failed` outcome pays exactly what a
`completed` one pays, same as the ladder above.

## 5. Where Blob stands

The scene is **derived, never stored**, exactly as a body form is.

| Scene | Trigger | Contents |
| --- | --- | --- |
| `forest` | `logs: 0` | four baked backdrops, one landmark tree, three grass tufts |

The forest is always the world and is never replaced. What Blob built is an **object standing in
the clearing** that upgrades in place — lean-to, then hut, then cabin, one at a time and never two
— and going inside it is a view, held by the client and reset on load, rather than a scene the
record puts Blob in. Where Blob **is** and what Blob has **built** are independent facts: you can
stand outside a cabin or sit inside a lean-to.

`insights: 5` has not moved. It has stopped *granting* a cabin and started being the *floor at
which one may be built*. E1's rule is that a threshold never relocates, and it has not — what it
does changed, not where it sits.

`COMPANION_SCENE` in `.env` overrides the derived scene. It is a **development affordance, not a
setting** — a scene the reader can choose is a setting rather than a consequence of the record.
Without it the forest is unreachable on any established account, which is why it exists.
`config('companion.scenes')` since F3 names only `forest`; `cabin` survives solely in `scenes.ts`'s
`SCENES` registry, so `COMPANION_SCENE=cabin` still resolves to a name without being a threshold of
anything.

### Light

Four parts of day (`sunrise` 5, `day` 8, `dusk` 18, `night` 21), each carrying a wall colour, a window
colour, a light colour and a `dim`. `partOfDay()` sorts by start hour and takes the last one that has
begun, which is how night wraps past midnight without a fifth state describing 3am.

**`night` also carries `asleep: true`.** It is the one field on `RoomPalette` the light itself never
reads — `asleepAt()` and `wakingAt()` in `part-of-day.ts` do, and that flag alone is what Blob's own
day keys off, entirely apart from the wash the room gets. A room authored before the field existed
behaves exactly as it did: absent is awake.

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

| Form | Sheet | Size | Animations | Foot row |
| --- | --- | --- | --- | --- |
| `blob` | `humus-blob.png` | 256×192 | idle, blink, sleep | 51 |
| `legs` | `humus-legs.png` | 256×192 | idle, blink, sleep | 53 |
| `arms` | `humus-arms.png` | 384×704 | all eleven | 53 |

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

**The hour is effectively ticked by this same loop, not left to sit stale.** `setFrame` fires from
inside the tick, which re-renders `Companion` and `CompanionPage` — and with them, whatever calls
`ambientFor`, `selfStartedFor` and `partOfDay`, each of which takes a fresh `new Date().getHours()`.
React bails out on an unchanged state, so the effective rate is the running ambient's own fps — about
1Hz asleep, 2Hz idle — but that is still often enough that Blob falls asleep and wakes live, the room's
light follows in the same render pass, and `selfStartedFor`'s pending timers clear the moment bedtime's
empty list comes back. No separate hour ticker is needed, and adding one would only duplicate work this
loop already does.

**The one exception is `prefers-reduced-motion: reduce`.** The hook never subscribes to the loop at all
(see `useSpriteClock` above), so those viewers get the hour read once, at render, and nothing ticks it
again after. A page left open past 21:00 keeps Blob awake until something else causes a re-render.
Harmless, arguably even fitting for that preference, but worth writing down rather than rediscovering.

### The animation registry

`companion-animations.ts` is data only. Three channels:

- **`ambient`** — what Blob does at rest, loops. `idle` and `walk` while awake, `sleep` as the resting
  state at night, and the self-starting one-shots: `blink`, `wave`, `jump`, and — new here —
  `stretch` and `look`.
- **`reaction`** — plays once, overrides the ambient, hands back. `pet`, `play`, `notice`.
- **`scene`** — belongs to the world, not the creature. `sway`, `rustle`.

`scene` exists so `sprite-layout.test.ts` can say *why* those two need no sprite row — because they are
not things Blob does. Deriving that exemption by scanning the scene registry instead let the registry
switch off a sprite-sheet invariant at a distance.

Only `ambient` animations carrying `autoEvery` self-fire, and only once the matching ability is earned
— otherwise the body would do things the ladder has not announced. `blink` and `look` are exempt from
that: nothing earns them, because they are Blob being alive rather than Blob doing something, the same
reasoning `wave` and `jump` do not get. **Nothing self-starts while Blob is asleep** — `selfStartedFor`
returns an empty list for the whole of `night`, `blink` included, rather than opening a sleeping Blob's
eyes every few seconds.

### Anchors

`sprite-layout.ts` holds five anchors — `head`, `face`, `neck`, `feet`, `hand` — for each of the three
forms: 15 resting positions, plus 216 per-frame offset pairs, written only where a limb actually
carries something. All of it measured off the art, not guessed; `sprites/README.md` records how.

**An anchor's two components are measured from different edges.** `y` is rows down from the cell's top;
`x` is columns from the cell's *centre*, signed — which is why every consumer adds `CELL / 2`. Reading
`x` as an offset from the left edge is what put an accessory 32px off the body.

**Every per-frame delta has `x = 0`.** Nothing ever moves sideways; anchors only translate vertically,
by −8 to +2. That single fact is why a worn item needs one sprite rather than one per frame.
`sprite-layout.test.ts`'s "never moves an anchor sideways, on any form or frame" is the guard: it walks
every offset table and fails the moment one of them stops being zero.

## 7. How the art is made

Five asset classes. All of it is committed, so a Pixel Lab outage can never affect the running app.

**Not five pipelines, though — the count stopped being useful at F3.6.** The nodes were planned as a
fifth single pipeline and needed three different ones between four objects, decided by measurement
rather than by kind. Treat the sections below as worked cases, not as a taxonomy to file a new asset
under.

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

### The shelter — one object, edited through its states

**F3.5's pipeline, and the fourth one.** `create_1_direction_object` once, as the lean-to, then
`create_object_state` twice — hut, then cabin — because a state edit keeps one registration across all
three, which is what makes "same footprint, filling in" structural rather than hoped for.
`create_image_pro`, used for the backdrops, returns a `job_id` and cannot be state-edited at all — a
different registry, and the reason that pipeline could not be reused here.

Three tool facts cost time to learn: `style_images` forces a **square** output; candidate counts fall
sharply as the style image grows (64 candidates at 32px, 16 at 48px); and a base64 style argument
truncates in transit past roughly 1KB, fixed by quantising the reference first. One state edit (hut →
cabin) did not hold the geometric promise and was hand-composited over its predecessor rather than
re-rolled — the same measured-and-replaced move the tree's rejected `animate_image` output set as
precedent in the scenes pipeline above.

Full detail — the exact crop and quantise commands, the candidate-selection rule, the review-object
lifecycle — lives in `scenes/README.md`'s "Running this pipeline again", not here. That section is
the runbook F3.6 read first.

### The nodes — one pipeline expected, three needed

**F3.6, and the finding is that "generate the lowest band, then state-edit upward" is not one
pipeline but three.** Eleven sprites, not twelve: the three world nodes get `bare`, `some` and
`plenty`; the heap gets two, because a drained heap is deleted and the client never receives one at
nothing-at-all, so a `bare` heap would be art for a state the system cannot produce.

- **The branches use three independent candidates AS the three bands, with no state edit.** Their
  sixteen candidates share one palette and differ only in quantity, so three ordered by mass already
  are three amounts of one thing — 183, 353 and 437 opaque pixels.
- **The reeds cannot do that.** Their candidates are sixteen different beds in different palettes,
  and apparent quantity follows palette rather than pixel count: one at 1273 opaque pixels in pale
  straw reads as *fewer* reeds than one at 741 in dark green. Their lower bands are a **deterministic
  top-cut** of the chosen bed instead — each stalk column loses a share of its height, the base row
  untouched. No new colours, ground line immovable, exact mass. A state edit was tried first and
  returned an eighth of the reduction asked for.
- **The trunk's additive edits held exactly**, which the shelter's did not: zero opaque pixels lost
  from `bare` into `some`, two from `some` into `plenty`. The log is the same log in all three and
  only the cut timber beside it grows — which is what its band model requires, since the trunk is the
  world and the world does not change shape when you take something from it.
- **The heap's downward edit worked** (1084 → 301), so the hand-composite fallback named in advance
  was not needed.

**Bands are cropped to one shared box per node, and bottom-aligned first only where alignment is
right.** Align bands that share no registration (the branches) and a state edit that genuinely moved
the base (the heap's `some`, three rows low). Never align a state edit that added material *below* an
existing silhouette: the trunk's `some` grew billets beneath the log, dropping the bbox floor ten
rows, and aligning on that pushed `bare`'s log down to meet billets it does not have. That shipped
once and was caught by a review — **the bbox cannot tell "the floor moved" from "material appeared
below the floor"**, so the judgement is per node and is checked by rendering the bands in sequence and
watching whether the object holds still. Every cell size, opaque count and monotonicity check stayed
correct while the trunk was visibly broken.

### UI chrome — panels as nine-slices

`create_ui_asset` gives a panel; CSS `border-image` with `image-rendering: pixelated` and
`border-image-repeat: round` makes it fit any box. Slice insets are measured from where the art's own
hollow middle begins, not guessed.

### Where raw images live: nowhere

`create_image_pro` produces **job results, not saved assets**. Pixel Lab's library lists characters,
objects, tilesets, maps, fonts and UI panels — there is no images list, and `add_to_project` will not
take one. The backdrops therefore cannot be found in the Pixel Lab UI at all. **The repo is their only
durable home**, and `scenes/README.md` records every job id.

## 8. The economy

Two layers, added by F1 and widened by F2, sitting entirely beside the gift ladder in §4. **XP buys
skills; a skill unlocks an interaction with one node; a node yields a material; a material builds a
thing.** The record controls what Blob *can* do — buying a skill is a decision, and a permanent
one, so two people with identical records can build visibly different clearings. The world controls
*how fast* — a skill only earns access, and every unit still has to be harvested one outcome at a
time. One wallet would collapse the two into an animation; harvesting is what keeps a material a
resource rather than decoration.

### Discovery: clicking the thing you cannot use

All three nodes — F1's reeds and deadfall, F2's trunk — are **visible in the clearing from the
start** and unusable without their skill. Clicking one before you have it does not fail and shows no
lock: Blob walks over, turns it over, and puts it down again, and that encounter is what puts the
skill in the bag's skill list. The list therefore only ever contains skills whose subject has been
met, grows without ever showing its own length, and a skill you cannot yet afford stays listed with
its price — a menu, never a checklist with a bottom to be behind on.

### Spending: earned is derived, spent is stored

```
balance = max(0, earned − spent)
          ^^^^^^        ^^^^^
          derived       companions.xp_spent
          from the
          record
```

`CompanionWallet::earnedFor()` recomputes earned XP from the record on every read, at the rates in
§4. Only the *spending* is stored, in `companions.xp_spent`, written nowhere but `LearnSkill`.
Deleting history can lower the balance, but a skill already learned is never revoked, and the clamp
stops the balance going negative — the worst case is being unable to buy until more is recorded,
which takes nothing away.

### The rule F2 established

```
a node   gates on  skill + tool
a recipe gates on  tool, never skill
```

A skill is the record's permission to **touch the world**. Hands have never needed permission — they
need the right thing in them. `BuildItem` gates on a tool alone: assembling by hand is what hands are
for, which is why building has needed no skill since F1 and never will. A node needing both a bought
skill and a built tool is the only place in the feature where the two layers meet on a single
gesture — the thing that makes a tool a mechanic rather than a badge.

### The rule F3 adds beside it

```
a node with a skill  is the world:  always standing, and the record stocks it
a node without one   is a heap:     there only if something put it there, and nothing restocks it
```

`salvage` — the cabin an established account already had, handed back as a heap standing where it
stood, see §10 — is the one node of the second kind this feature has: absent from the clearing
until something puts it there, never grown by an outcome logged, and deleted once drained rather
than standing there forever as an empty label.

### The chain

```
fibre ──▶ rope ──▶ axe ──▶ timber ──▶ handsaw ──▶ planks ──┬─▶ crate
  │                 │        │                       │     │
gathered          built    gathered                built  └─▶ shelter
(F1 node)      (recipe)   (F2 node, needs        (recipe,      (lean-to → hut → cabin;
                           chop-wood + axe)       needs handsaw)  priced, but not a recipe)
```

Five links, crossing between the two layers at every step. `timber`, not `logs` — that word already
means something two hundred lines up in `config/companion.php` (`'trigger' => 'logs'`, `logCount`,
`logMoments()`), and a material by the same name would sit near it meaning something else entirely.
`planks` also feeds the shelter, but that branch is not a sixth link: `companion.shelter` prices
lean-to, hut and cabin in planks alone and is deliberately not a `bag` recipe — §10 has the ruling.

### Visibility is a fixed point

`CompanionBag::knowable()` decides what Blob can account for — the same list that gates which
recipes appear at all. An ingredient is knowable if it is a met node's yield **or** if it is itself a
knowable recipe: rope is knowable because fibre is, and the axe is knowable because deadfall and rope
both are. A tool that gates a *node*, like the axe, is additionally withheld from the list until that
node has been met — the axe stays unlisted until the trunk is, even once its own ingredients are
already known. A tool that gates a *recipe* instead, like the handsaw, is not reverse-gated by
anything — no node names it — so it becomes knowable the moment its own ingredients are, which is
the same click that reveals the axe, since both bottom out in meeting the trunk.

**The gates sit inside the loop, not beside it.** An item this map still withholds never enters the
known set at all, so nothing built from it becomes accountable either — at whatever depth the chain
runs. A guard checked only when a row is rendered could stop the withheld tool itself; it could not
stop something three links downstream of it. The resolution is a fixed point over the catalogue, not
recursion, because `config` is authored data and an authored `a → b → a` pair would otherwise
recurse forever on a page load — each pass adds whatever became reachable, and the loop stops the
first time a pass adds nothing.

### One arithmetic, two callers

`Companion::wouldFit()` is the single place the build-capacity check lives: `held − consumed + made
≤ capacity`, counting only carried categories on both sides. `BuildItem` calls it before it writes
anything, and `CompanionBag::recipes()` calls the same method to decide whether `buildable` should
say yes — so the action that refuses and the read that predicts can never quietly disagree about
whether a build will fit. Held and affordable is not the same as fitting: `HarvestNode` fills the bag
by design, so a full bag is the expected outcome of harvesting, not an edge case.

### Three things F2 refused

Each of these is a test that fails if the refusal is ever quietly reversed — see §10.

- **A tool takes no bag room.** `capacity.carried` stays `['material', 'consumable']`; a tool is on
  the belt. A tool is permanent, so a tool that occupied a slot would be a permanent tax on what Blob
  can carry — gaining the axe would shrink the bag forever, which is regression in everything but
  name.
- **A tool never wears out.** Durability is removal with extra steps, and repair-with-materials would
  reintroduce upkeep — the same loss-aversion reasoning that rules out streaks. Progression past a
  tool, if a later phase wants it, is a second, better tool. Addition, never repair.
- **Insight events do not stock the world.** There is no insight event in this application — the four
  kinds are derived from database state by `CompanionResolver::insightMoments()`, with no seam to
  listen to. Stocking from them would mean new domain events dispatched from the reflection, intention
  and experiment actions: new architecture for a pacing adjustment, and a second opinion about what
  happened alongside a resolver that already derives it. The world does not need it anyway — it now
  accrues across three nodes, so one outcome logged yields three units, and insights are already paid
  generously, outside the taper, in the layer §4 describes.

### The shelter's price

| Stage | Cost | Floor |
| --- | --- | --- |
| `lean-to` | 4 planks | — |
| `hut` | 8 planks | — |
| `cabin` | 14 planks | `insights: 5` |

No tool and no skill gates a stage. `planks` already carries the handsaw's gate upstream, and a
second gate here would tax the same work twice — the same reasoning `BuildItem` has followed since
F1.

**A base bag cannot pay for even the cheapest stage.** `planks` is `{recipe: {timber: 1}, makes: 3,
tool: handsaw}`, and `Companion::wouldFit()`'s `held − consumed + made ≤ capacity` makes one sawing
a net +2, so it needs `held ≤ 3` the moment it runs. Held 1 timber saws to 3 planks, fine; held 3
planks plus the timber that has to be in hand to saw again is 4, and 4 + 2 = 6 > 5, refused.
**Sawing alone tops out at 3 planks in a base bag, and the lean-to costs 4** — the first stage of
the whole arc needs a container before it can be paid for by an account that saws its own planks,
which makes the basket or the barrow a prerequisite of that build path rather than an optimisation.
It is not a prerequisite for a converted account: a harvest takes `min(standing, room)`, so a base
bag draws `min(26, 5) = 5` planks straight out of the salvage heap, and the lean-to is paid for with
no container at all.

### Two ways to keep a bag from clogging

F2 left a real dead end: timber's only consumers are the handsaw (needs rope as well) and planks
(needs the handsaw), so a bag at capacity holding timber and no rope can build nothing — and because
node stock accrues while you are away, the trap gets *more* likely the longer someone is gone.
Pricing the shelter in planks does not touch it; F3 closes it instead, with two choices that both
belong to the player:

- **Take less than a bagful.** The clearing's own click still fills the bag by default —
  `HarvestNode` sends no amount — but the bag's own control can name one, so filling the bag with
  one material and nothing else buildable is now avoidable rather than inevitable.
- **Tip a stack out.** `DropItem` destroys a whole carried stack — the one exception §2 records and
  §10 rules on — so a bag already stuck can still be emptied. Only carried categories are droppable;
  a tool or container reaching `CompanionItemController` is a 404, not a line in Blob's voice,
  because there is no sentence for a gesture the screen never offers.

### The stash: a second place the world keeps the overflow

F4.1 adds a chest, built once out of three planks, standing in the clearing. **The bag still
spends; the stash only stores.** `Companion::wouldFit()` is untouched — a recipe reaches into the
bag alone and never into the chest, so a container is still what decides whether a build fits, and
the stash cannot make buildable something the bag's own arithmetic refuses.

**The stash is uncapped.** The world is already uncapped everywhere else in this feature — node
stock has no ceiling and nothing expires — and a limit here would be the first place the world
refuses to hold something. `StashItem` runs no room check at all.

**What it actually fixes, with the arithmetic.** Sawing is `{timber: 1} makes 3`, a net +2, so
`wouldFit()`'s `held − consumed + made ≤ capacity` needs `held ≤ 3` the moment it runs: a base bag
holding 3 planks plus the timber that has to be in hand to saw again is 4, and `4 − 1 + 3 = 6 > 5`,
refused. A base bag tops out at 3 planks and the saw stops. Park those 3 planks in the chest and
`held` drops back to what the timber alone costs — **sawing goes from self-blocking to unbounded.**

**What it does NOT fix.** It sits downstream of rope — the chest itself needs planks, which need
the handsaw, which needs rope — so it does nothing for F2's timber dead end; F3 already closed that,
twice, with a harvest that can take less than a bagful and a stack that can be tipped out. It also
does not make the salvage heap's 26 planks self-sufficient: the cabin costs 14 and `BuildShelter`
spends from the bag, which still holds only 5 plus 5 per container, so two crates still stand
between the heap and the cabin.

**Accepted consequence: `DropItem` becomes rarely needed once a chest is standing.** That is fine.
It was F3's answer to a dead end, and a dead end having a second exit does not make the first one
wrong.

### The pile: the economy's first sink

F4.2 adds a pile of wood standing inside the shelter. `StackWood` moves a whole carried stack of
`deadfall`, `timber` or `planks` into it, and nothing ever moves back out.

**One-way and uncapped.** The pile is `companions.woodpile`, a single unsigned integer, default 0 —
no table, no per-material breakdown. Nothing comes back out, so there is nothing to remember beyond
the running total, and `StackWood`'s `increment('woodpile', …)` is the column's **only writer in
application code**, confirmed by grepping `app/`, `config/` and `database/`. Not `tests/`: `woodpile`
is `#[Fillable]` on `Companion` — the same list `xp_spent` sits in, and that shape predates this
phase — which is what lets `StackWoodTest.php:81` and `CompanionBagTest.php:1400` seed a pile
directly with `create(['woodpile' => …])` for their own setup. Neither test is application code
deciding to move wood; the claim above is scoped to the code the record actually runs.

`config('companion.woodpile.takes')` names `deadfall`, `timber` and `planks`. **This is not a second
`carried` list.** F4.1 refused one on the grounds that two lists describing one idea eventually
disagree, and that reasoning holds — but `takes` and `carried` are not the same idea. `carried` says
what the bag may hold; `takes` says what this one object accepts, which is the kind of statement a
recipe has always made about its own ingredients. Fibre and rope are not wood, and the pile does not
take them. A plank and a deadfall count the same, deliberately — the pile's job is to absorb whatever
there is too much of, and it is a picture rather than an inventory.

**The measured reason it exists: the economy terminated.** `LearnSkill.php:66` is the only writer of
`xp_spent`, and three skills at 20 XP each (`config/companion.php:467–469`) make 60 XP the entire
amount any account will ever spend — past roughly four concluded experiments the wallet only grows,
and nothing refills that sink. Past the cabin and the chest, **the only new thing any further
material can become is another container**: `Companion::capacity()` multiplies a container's
`capacity` by its quantity, so crates stack +5 forever, and the terminal loop was *build crates to
carry materials you have nothing left to spend on*. That is an end state, which the progression
arc's own Discovery row forbids outright — the pile is what removes it.

## 9. Where everything lives

```
config/companion.php                    ladder, tail, scenes, parts of day, renderer, xp, skills,
                                         nodes, the bag, capacity, the shelter, salvage, woodpile
database/migrations/
  ..._000001_add_the_shelter_to_companions_table.php   shelter + salvaged_at
  ..._000002_salvage_granted_cabins.php                the one-time backfill
  ..._000001_create_companion_stash_items_table.php    the stash's own table; uncapped, no location column
  ..._000001_add_the_woodpile_to_companions_table.php  the pile's own column; uncapped, one integer
database/factories/
  CompanionStashItemFactory.php         one stash stack, for tests
app/Models/
  Companion.php                         the name, xp_spent, woodpile, capacity(), held(), wouldFit(),
                                         shortfallFor(), spend(), stashItems()
  CompanionItem.php                     one stack held in the bag
  CompanionStashItem.php                one stack at home, in the chest — its own table, see §10
  CompanionNode.php                     one node's standing stock
  CompanionSkill.php                    one skill learned, and when
  CompanionRemark.php                   one thing Blob has to say, written by the coach
app/Services/Companion/
  CompanionResolver.php                 record -> state, a pure read
  CompanionState.php                    what Blob is right now
  CompanionAnnouncement.php             what an unlock says
  CompanionRemarks.php                  the coach's own line, relayed verbatim
  CompanionWallet.php                   earned − spent, the balance
  CompanionBag.php                      what the bag screen needs, in one shape
  CompanionEconomyException.php         every refusal the economy makes
  CompanionCapacityException.php        the one refusal that is not a shortage: no room, not unpaid
app/Actions/
  LearnSkill.php                        buys a skill; the only writer of xp_spent
  MeetNode.php                          the encounter that reveals a skill
  HarvestNode.php                       moves stock from a node into the bag
  BuildItem.php                         spends a recipe, makes something
  BuildShelter.php                      puts up one shelter stage; the only writer of shelter
  DropItem.php                          tips a carried stack out; the only path that destroys
  StashItem.php                         puts a stack down in the chest; nothing destroyed, no cap
  UnstashItem.php                       fetches a stack back out, bounded by room; mirrors HarvestNode
  StackWood.php                         moves a whole stack onto the pile; the only writer of woodpile
  SalvageTheCabin.php                   the migration's conversion, factored out and tested
  WriteBlobRemark.php                   records one of Blob's remarks; the only writer
app/Listeners/StockCompanionNodes.php   ActionLogged -> one unit into each unlocked node
app/Mcp/Tools/WriteBlobRemarkTool.php   the MCP surface a remark is written through
app/Http/Controllers/
  CompanionController.php               the screen: the resolver and the bag, assembled
  CompanionNodeController.php           clicking a node — meet it or harvest it
  CompanionSkillController.php          buying a skill
  CompanionBuildController.php          building an item
  CompanionShelterController.php        putting up one shelter stage
  CompanionItemController.php           tipping a carried stack out
  CompanionStashController.php          moving a stack between the bag and the chest; one route, both directions
  CompanionWoodpileController.php       stacking wood against the wall; one route, one direction
  CompanionNameController.php           renaming Blob

resources/js/hooks/use-sprite-clock.ts  the one rAF loop
resources/js/patyourself/
  companion.tsx                         payload types, ambientFor, selfStartedFor, actionsFor
  companion-bag.tsx                     the bag modal: contents, the build list, the skill list
  companion-animations.ts               the animation registry
  companion-room.tsx                    the scene compositor and the light; takes inside and shelter,
                                        draws the standing structure and, indoors only, the woodpile
  companion-glyph.tsx                   the 8x8 mark beside a record line: sprout, crate, spark
  blob-renderer.tsx                     both renderers, worn items, ability props
  sprite-layout.ts                      forms, cells, the 231 anchors
  sprite-items.tsx                      what Blob wears, rects or sheets
  scenes.ts                             the scene registry; each scene may carry a shelter coordinate
  part-of-day.ts                        partOfDay, asleepAt(), wakingAt()
  sprites/   + README.md                bodies, worn-item sheets, every measurement
  scenes/    + README.md                backdrops, foliage, the shelter sprites, node-chest.png,
                                        the light's reasoning
  ui/        + README.md                the pixel frame and buttons
resources/css/patyourself.css           .pixel-frame, .pixel-button, transition rules
resources/js/pages/companion.tsx        the screen

tests/Feature/Companion/                resolver, tail, scene, screen, vocabulary, the economy
tests/Unit/Companion/                   ladder, room config, content
```

## 10. Rulings that must not be reopened

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
| **Sleep is a behaviour, not a rung** | It needs nothing earned and announces nothing — Blob sleeps the first night it exists, the same as `blink`. A rung would make it something the record grants, which is exactly the mirror this feature refuses to be. | A rung, permanently, and a shallow record standing awake while a deep one sleeps. |
| **The browser's clock drives Blob's day, same as it drives the room's light** | Two clocks disagreeing is worse than one clock being wrong: a sleeping Blob in a lit room, or a lit Blob in a dark one, reads as broken rather than as a creature keeping its own hours. | The two splitting, and a sleeping Blob in a midday room. |
| **The sleeping pose stays upright** | Lying down or curling up both rotates the body and moves it sideways — the two things every other animation here has always avoided, and the reason every per-frame anchor's `x` is `0`. A curled-up Blob would need bespoke per-frame accessory art, or would have to go without accessories while asleep. | Per-frame horizontal anchors, and one sprite per item becoming one per frame. |
| **A tool never occupies bag capacity** | It sits on the belt, not in a slot — `capacity.carried` excludes `tool`. A tool is permanent, and counting a permanent thing against capacity would tax Blob forever for having progressed. | Every future tool becomes a punishment: gaining it would shrink the bag forever. |
| **A tool never wears out or needs repair** | Durability is removal with extra steps, and repair-with-materials would reintroduce upkeep — the same loss-aversion reasoning that rules out streaks. Progression past a tool is a second, better tool, never a mend. | The one regression rule this whole feature exists to hold, broken in the one place a tool could hide it. |
| **Insight events never stock a node** | There is no insight event in this app — the four kinds are derived by `CompanionResolver::insightMoments()`, with no seam to listen to. Stocking from them would mean new domain events and a second opinion about what happened, beside a resolver that already derives it. | New architecture built to duplicate a pacing job XP already does, and two sources of truth about what one log did. |
| **Destruction is always player-initiated** | Every prohibition in "nothing regresses" names something the system does to you. A player spending, or tipping out, is the thing arc §2 celebrates. | The one rule the whole feature rests on, broken in the one place that looks like a convenience. |
| **A node without a skill is a heap, not the world** | One property drives three behaviours: it is absent until placed, the record never stocks it, and it needs nothing learned. Stocking one would rebuild a cabin out of nothing, one outcome at a time. | A salvage pile in front of an account that never had a cabin, or a heap that regrows forever. |
| **The shelter is not a `bag` category** | This departs from arc §5's taxonomy table, deliberately. A recipe in `bag` becomes knowable from its ingredients, so all three stages would list at once — and only the next stage is ever listed. A structure also does not stack in `companion_items`. | The build list becomes a checklist of three, which is the one thing the arc forbids. |
| **The salvage is 26 planks, and 26 is not self-sufficient** | 4 + 8 + 14 is what the arc costs, but the cabin is consumed in one act of fourteen and the bag holds five plus five per container — so two crates, eight more planks, stand between the heap and the cabin. An established account reaches the hut and then has to play the economy for the rest. That is consistent with the cabin being something you build rather than something you are given; it is *not* what spec §6's own sentence claims. | An established account is told it can rebuild what it had and finds it cannot without gathering. |
| **The shelter's `y=34` is chosen, not measured** | The measured ground line (PNG row 62 → `y=24`) is the clearing's *back wall*, not its open floor — a building standing on it reads as standing among the trunks rather than in the clearing. Four heights were rendered past that measured line before `y=34` was picked as the one that actually stands in it. | A later "correction" back onto the measured line puts the shelter back in the trees, with the render evidence that ruled it out gone unless this row is read first. |
| **The stash is its own table, never a column on `companion_items`** | `Companion::held()` and `capacity()` both sum `$this->items` unfiltered. A `location` column makes both silently wrong while every existing test stays green, because every test written before this one puts every row in one place. MEASURED: pointing `stashItems()` at `CompanionItem` gave `held() = 11` and `capacity() = 10` against the expected 2 and 5. | A stashed crate raising carry capacity and stashed planks counting against the bag, discovered nowhere in the suite because every test's rows share one location. |
| **A recipe spends from the bag alone** | `wouldFit()` is the one arithmetic the refusing action and the predicting read must share. Letting a recipe reach the stash grows a second notion of "held" and makes containers decoration. | `CompanionBag::recipes()` promising a build that `BuildItem` then refuses, or the reverse — the exact disagreement `wouldFit()` exists to make impossible. |
| **Whether Blob sleeps is the clock alone; WHERE it sleeps is what you built** | `asleepAt()` and `wakingAt()` take only `(hour, room)` — there is no bag parameter to add. §2's rule names `logCount`, `insightCount` and the unlocks as what a sleeping Blob must never read; the pile is none of those, it is a purchase, like the cabin Blob already stands next to. The decision of *where* lives entirely in `pages/companion.tsx`'s `useState` initialiser — `asleepAt(hour, room) && bag.woodpile.amount > 0`, read once, at mount. | A state of being alive becomes a reward, in the one place it could hide. |
| **Nothing comes out of the pile** | One-way is the entire difference between a sink and a stash, and it is why one integer suffices — nothing to remember, only a total to grow. `StackWood`'s `increment` is confirmed, by grep, as the column's only writer in application code — `woodpile` is `#[Fillable]` (`xp_spent` has the identical shape, and predates this phase), which is what lets two tests seed it directly by mass assignment; neither is application code moving wood. | The sink becomes storage, the end state returns, and the column has to become a table to answer a question it never had to answer. |

## 11. Traps that have already bitten

Every one of these has cost a round on this project.

1. **A test written against a fixture's default asserts nothing.** Five have shipped here. One asserted
   no bookshelf appears outdoors — against a fixture defaulting `room_objects` to `[]`. If you cannot
   name the mutation that turns a test red, it is decoration.

   **F3.6 sharpened this: naming the mutation is not enough — you have to RUN it.** Three stated
   mutations on that one branch were false or overstated, every one of them written by the author of
   the plan and believed until somebody applied it. One test clicked a control that never reached the
   condition it claimed to pin, and passed with or without that condition. Another asserted a guard
   against a prototype-chain lookup using a key the prototype chain does not produce, so the guard
   could be deleted with the file still green. A third claimed five tests would redden where only two
   could, because the other three asserted that *nothing* was drawn and "draw even less" cannot
   falsify them. A stated mutation nobody has executed is a second thing to believe, not evidence —
   and it is more dangerous than no comment at all, because it stops the next reader checking.

   **A new case, F4.2: `$attributes` hides a column's SQL `DEFAULT`.** When a model declares an
   `$attributes` default for a column, `HasAttributes::getAttributesForInsert()` returns the
   in-memory attributes verbatim and `Model::performInsert()` never re-SELECTs, so the INSERT is
   always explicit — no test going through Eloquent's `create()` path can verify that column's
   `DEFAULT` clause at all. Mutating `companions.woodpile`'s `->default(0)` to `->default(5)` left a
   database-reading assertion green; the only mutation that reddened it was removing the column
   outright. `xp_spent` has the same shape and predates this phase — not a new defect, only a newly
   measured one.
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
8. **Herd serves the main checkout, never a worktree.** "Dump to HTML and `php -S`" is not specific
   enough, and it cost F4.1 about eight tool calls to get past. Serving a built SPA with
   `php -S ... -t public public/index.php` returns EVERY asset — the JS module included — as
   `Content-Type: text/html`, so the browser silently refuses to execute the module script and
   **the page renders blank with no console error at all**. A registered PWA service worker is a
   plausible-looking red herring; it is not the cause. What works is a router script that
   `return false`s for a request matching a real file, served as
   `php -S 127.0.0.1:8899 -t public <router>.php` — BOTH the `-t public` and the router are
   required, because `return false` resolves the path against the docroot `-t` sets, not against
   wherever the router script itself lives.
9. **When a task adds a conditionally-rendered section, the shared fixture must be widened in the
   same task, or every guard downstream goes quiet rather than red.** It has bitten four times on
   one branch, three of them in `companion.test.tsx`'s "never shows what has not happened" guard —
   the acceptance criterion for the whole feature, run twice, bag closed and bag open. The three
   nodes' shared `available: 0` default hid `Clearing` from both runs (Batch 1); `shelter.offer`'s
   default of `null` hid `Shelter`, and rendering `1 of 4` as visible text there left 38/38 green
   (Batch 2); and `ShelterSpot` needed two fixture fields widened together — `bag.shelter.built`
   *and* the fixture's default scene, `cabin`, whose `SCENES` entry names no `shelter` coordinate —
   because widening `built` alone still left the object absent (Batch 3). This differs from trap 1:
   that one is about a single test proving nothing; this is about a **shared** fixture, where an
   unnamed section does not fail the guard, it silently drops out of what the guard covers, and
   every later addition to the same surface inherits the blind spot. Widening the obvious field is
   not proof a section renders — assert that it did.

   **The fourth bite, F4.2's, is the first outside `companion.test.tsx`** — it landed in
   `companion-bag.test.tsx`'s own "never shows" guard, which means the trap is a property of the
   shared-fixture *pattern*, not of one file. It also exposed a **second, independent failure mode
   this trap's text above does not describe**: even with the fixture widened AND the woodpile
   control rendering, the mutation that rendered the pile's amount as text stayed green under all
   three regex-based "never shows" guards in the codebase — because **the regex is blind to shapes
   nobody listed**. `/locked|next up|to unlock|remaining|streak|\d+ of \d+/` does not match a bare
   integer, and no amount of widening a fixture teaches a regex a shape it was never given. Both
   modes were confirmed by running the mutation: with the fixture widened and the amount rendered,
   the shared guards stayed green, and only a dedicated no-digits test, scoped to the control's own
   `<form>`, caught it.
10. **A type signature is not a guard in this project.** `asleepAt()`'s signature was widened
    experimentally, every call site was left passing two arguments, and `tsc --noEmit` reported
    14 errors — to see whether TypeScript actually stops a mismatched call from shipping. It does
    not, two ways over: `npm test` stayed green (vitest's esbuild transform strips types without
    checking them), and `npm run build` also built clean with zero errors, and `npm run build` is
    the exact command `.github/workflows/tests.yml` runs. Only `npm run types:check`
    (`tsc --noEmit`) caught it, and that script runs in **neither** workflow — `lint.yml` runs
    `composer lint` + `npm run format` + `npm run lint`, `tests.yml` runs `npm run build` +
    `phpunit`. So "the types prevent this" means "an editor prevents this, for whoever is looking."
    TypeScript errors never fail this project's CI.
11. **The harness's own `scene: 'cabin'` trap is a moving count, and F4.2 moved it to six.**
    `companion.harness.tsx`'s own docblock records that `companion.fixture.ts` defaulting to
    `scene: 'cabin'` — combined with `companion-room.tsx`'s `indoors = inside || scene.name ===
    'cabin'` — has silently rendered the interior instead of the clearing five times across F3 and
    F3.5, "twice in tests written hours after someone read the warning." A sixth landed in this
    phase's own plan: the woodpile's "never draws the pile in the clearing" test, as drafted, would
    have exercised nothing, for exactly that reason, and was caught only because the implementer
    added `scene: 'forest'` before it shipped. **The sixth instance was written by someone who had
    quoted that very warning earlier in the same session.** The count moving is the point: reading
    the warning does not prevent making the mistake it warns about — only a runtime check does,
    which is why `renderClearing`/`renderAtHome` throw rather than merely comment.

## 12. What is not done

- **F4 is three sub-projects, not one.** F1 (the bag), F2 (the tools), F3 (the shelter's economy),
  F3.5 (the shelter drawn) and F3.6 (the nodes drawn) are built. F4 decomposes into
  **F4.1 — the stash (DONE)**, **F4.2 — consumption proper (DONE)**, and **F4.3 — wearables
  migrating into the taxonomy** — the last of the three. F4.3 is not designed; it needs its own
  brainstorm → spec → plan cycle, the same one F4.1 and F4.2 went through. The reasoning behind the
  whole arc lives in `docs/superpowers/specs/2026-09-17-companion-progression-arc-design.md`; F4.1's
  own decisions live in `docs/superpowers/specs/2026-09-22-companion-f4.1-the-stash-design.md`;
  F4.2's own decisions live in `docs/superpowers/specs/2026-09-23-companion-f4.2-consumption-design.md`.
- **"More skills" was cut from F4 entirely**, and the cut is a finding rather than an omission. Two
  measured facts removed it: `CompanionBag.php`'s `skills()` reads `$skill['node']` with a bare
  lookup, and F2's rule is that a recipe gates on a tool and never on a skill — so a skill that
  unlocks nothing in the world is not representable today, and a new skill means a new node. The
  clearing is also full: four node cells need **186 units of width in a 144-unit room**, before
  Blob's silhouette and the shelter's 48-wide cell are counted, so a fifth node would be a second
  new object in a room that could not hold four. **The world ends at four nodes and the chest.**
- ~~A full bag of timber has no exit.~~ **Closed by F3**: a harvest can take less than a bagful, and
  a carried stack can be tipped out. Both are the player's act; nothing discards on their behalf.
- ~~`$companion->load('items')` stated a false premise at two of the three sites that carry it.~~
  **Closed by F4.2**: `HarvestNode.php`, `BuildItem.php` and `UnstashItem.php` now all read alike —
  each says the call is belt-and-braces, not load-bearing, because each action resolves `$companion`
  fresh inside its own transaction, so `items` is never pre-loaded and the relation would lazy-load
  on first read regardless. The measurement that established this, carried over from F4.1 rather than
  re-run: a mutation moving the call to after `room()` stayed GREEN.
- ~~Node hotspot labels do not scale with the stage.~~ **Closed by F3.6, by deletion rather than by a
  font rule.** The labels were a fixed 8px font that did not scale with the svg, so below roughly a
  300px stage they wrapped to a second line — reflow, not overflow, was the failure mode, and no box
  arithmetic on that branch modelled reflow. F3.6 draws the nodes instead, so there is no text in the
  clearing to size. The replacement hit region is the art's own cell, measured in room units and
  scaling with the picture, with a 24px floor (WCAG 2.5.8 AA) that in practice never binds. **The
  trunk's width-limited residual closed with it**, since it was the same fixed box seen from the other
  side.
- **The clearing's four node coordinates were all re-decided in F3.6, and the arithmetic that chose
  the old ones is gone rather than amended.** Every one of them reasoned about label boxes that no
  longer exist. The measurement that replaced them: the four cells are 48x41, 44x36, 48x47 and 46x35,
  so they need **186 units of width in a 144-unit room** — before Blob's drawn silhouette (about
  x −15..15) and the shelter's 48-wide cell are counted. No arrangement stands four objects that size
  apart, so **overlap is forced rather than chosen**, and the only question is which overlaps read as
  depth and which read as collision. The rule taken from renders: nearer things sit lower and overlap
  further things. **Array order in `scenes.ts` is now paint order** and runs back to front — trunk
  (y=42), heap (70), branches (74), reeds (76) — which the page's hotspot loop follows too, so the
  nearer control sits above the further one where hit regions overlap. What the old coordinates
  actually did, measured: the branches and the trunk shared **40x36 units**, which left the branches
  invisible inside the log; the reeds covered the hut by **42x31** and overhung the right wall by 2.
  **The heap is the one node that cannot be placed cleanly** — its 46-wide cell needs `|x| >= 38` to
  clear Blob, and both such positions collide with another node instead, so it overlaps Blob by 30x30
  at `[6, 70]` deliberately, sitting at Blob's feet and in front. It is also the only node that
  expires: a heap exists solely for an account handed a cabin, is never restocked, and is deleted when
  drained.
- **Three of the clearing's coordinates were rendered in F3.5, found wrong, and corrected.**
  `scenes.ts`'s own comments had argued at length that all three were sound, with no hint that
  anyone had since disagreed. Checked by viewing the built page and photographing the labels moved
  in the live DOM; all three are now shipped in `scenes.ts`, with comments that record the render
  and not just the arithmetic.
  - **`THE FALLEN TRUNK` moved `[-24, 48]` → `[-40, 48]`.** It overlapped Blob: F2 computed it against
    the other *labels* and never against the *creature*. `y=48` did not move — it is still the only
    value clearing both the grass line at 52 and the deadfall's label box — so the whole correction is
    in x.
  - **The shelter's final position is `[44, 34]`, not `[44, 22]`.** The bullet that used to occupy this
    line recorded `[44, -10]` → `[44, 22]` as the fix, and reasoned that `[44, 22]` put the shelter
    "where the treeline meets the grass" — which is the exact placement the owner rejected: standing
    where the treeline meets the grass reads as standing *in* the trees, not beside them. Every number
    in that bullet was superseded before this phase shipped, and its reasoning was the trap `scenes.ts`
    itself warns about elsewhere — a coordinate argued sound at length, with no hint anyone had since
    disagreed — sitting inside the warning.

    What actually decided it: PNG row 62 (`PNG row = y + 38`, giving `y=24`) is the backdrop's
    *measured* ground line, identical across all four parts of day — but it is the clearing's **back
    wall**, not its open floor, so a structure standing on it reads as standing among the trunks. Four
    heights were rendered past that line; `y=34` is the one that actually stands in the clearing, and
    `scenes.ts`'s own comment on `SceneSpec.shelter` now records the render, not just the arithmetic.

    One rule was set aside knowingly, not missed: spec §3 nominates the vector renderer's declared body
    (`BODY.w = 44`, so ±22) as the conservative clearance bound. The shipped `x=44` does not clear it —
    the cell's left edge at `x=20` overlaps that declared box by 2 units. It clears the renderer that
    actually draws instead (the sprite renderer, `config('companion.renderer')`'s default, measured
    narrower at roughly ±15) by 5 units. `scenes.ts`'s own comment is candid about the trade; this file
    should be too, rather than let a reader assume the stated conservative bound was met.
  - ~~The trunk's fix is width-limited.~~ **Closed by F3.6 with the labels themselves** — the
    feasible window shrank below a ~303px stage only because the fixed-8px box grew in room units as
    the stage shrank. An art cell does not.
  - **`THE HEAP` at `[21, 62]` was checked and is fine** — it renders cleanly in its gap between the
    middle and right grass tufts, with visible margin both sides. **Scoped to labels**: no *label*
    overlaps another label. The clearing also carries the shelter's 48×48 sprite cell and its own
    control's hit region now, which this bullet never claimed anything about — `scenes.test.ts` checks
    those separately, derived from `SHELTER_CELL`, not by a spot check on the heap.
  - **`THE REEDS` moved `[44, 36]` → `[50, 44]`.** The shelter's move to `y=34` put its 48-wide cell
    over the reeds' old position, and the clearing's right-hand column is too narrow to separate the
    two sideways — so `y` carries the clearance and always did. `x` moved for a different reason and
    was measured, not computed: pushed toward the right wall the label does not overflow it, it
    **wraps to a second line**, and no box arithmetic on this branch models reflow. Sweeping `x` on
    the rendered page, the label wrapped at 58, 56, 54 and 52 and sat on one line from 50 leftward,
    so 50 is the rightmost value that keeps it whole. F3.6 deletes the label, which retires the
    constraint that decided `x` — `y`'s clearance from the shelter outlives it.
- **Scarf, hat and glasses are still flat rects.** The worn-item pipeline in §7 covers them — except the
  hat, which occludes and therefore needs a different answer.
- **Phases B, C and D1/D2 have never been verified in production** — mail arriving, one-click links on a
  phone, the PWA installing. D3 has: Blob renders live.
- **Prop-versus-item collision is unguarded.** Nothing stops a held book and a hanging scarf occupying
  the same pixels.
- **`ROOM_OBJECTS` and `SPRITE_ITEMS` share the prototype-chain fallthrough** that was fixed only in
  `scenes.ts` — `Object.hasOwn` there, a bare lookup in the other two.
- **`companion-glyph.tsx` is absent from `CompanionVocabularyTest::sourceFiles()`.** It is a
  companion source file scanned by nothing, exactly the gap §2 warns about — true since the file
  landed on 16 Sep, not something F3 introduced. It is today almost entirely glyph grids and colour
  constants, so the exposure is small; the cost is that the next edit to it inherits no guard.
- **The Settings area still wears the stock Laravel starter-kit shell.**
- **A catch-up acknowledgement** is an open design question, not code.
- **`stretch` and `look` fall back to `idle` on the `blob` and `legs` forms.** Neither form has a row
  for either animation, so the fallback contract in §6 holds every frame to `idle` frame 0 rather than
  drawing anything. Only `arms` draws them.
- **The `blob` form's sleep row has no vertical breath amplitude.** A livelier frame selection tripped
  the scarf-clearance guard, which asserts a form/item pairing the ladder cannot produce — a scarf on
  the `blob` form — so the amplitude was dropped rather than the guard. The lesson generalises past
  this bullet: **a guard over a state the system cannot reach costs you the state it can.** Assert what
  is reachable. Fixing this properly means narrowing that guard to the forms a scarf can actually
  appear on, and only then restoring the amplitude.
- **`UnstashItem` does not refuse a non-carried category, unlike `StashItem` and `DropItem`, which
  both do.** There is no reachable path today: a container can only ever enter the stash through
  `StashItem`, which already refuses it, and `CompanionStashController` gates both directions on
  `movable()` before either action runs. It would cost something only if a second way to populate the
  stash ever bypassed `StashItem` — a seeder, an import, a future admin action — at which point
  `UnstashItem` would hand a container back out of a chest that should never have held one, silently.
- **`consumable` is a category with no behaviour.** It occurs exactly twice in non-test code, both as
  data — `config/companion.php:658` (rope's category) and `:833` (the `carried` list) — and nothing
  branches on `consumable` versus `material`. Rope is a material with a second name, and the arc's
  own taxonomy table describes both identically. This is **F4.3's subject**, measured here so the
  next phase inherits it rather than re-deriving it.
- **The pile's drawn height is `WOODPILE_MAX_H * n / (n + WOODPILE_K)`, and the curve — and the shape
  drawn at that height — were chosen by rendering, not by arithmetic.** Rendered at 1, 12, 40 and
  500: h = 2.0, 13.0, 20.0, 25.4, still climbing toward but never reaching the 26-unit ceiling —
  geometry the browser confirmed (placement, clearance from Blob and the plant, growth across the
  range), not geometry it assumed. What the render changed was the shape, not the curve: the first
  drawing was a rectangle with evenly-spaced horizontal lines, and it read as a small chest of
  drawers at a dozen logs and a panelled cupboard door at 500 — trap 4 exactly — while every jsdom
  assertion stayed green the whole time. It was redrawn as rows of stacked log ends; `woodpileHeight()`
  itself did not change.

  **That is true of the height, and false of the drawing.** `h` is asymptotic and never reaches
  `WOODPILE_MAX_H` for any finite `n` — but the drawn stack discretises it into rows of `ROW_H`
  (2.7 units) via `Math.ceil(h / ROW_H)`, and a rounded row count is not asymptotic: it saturates.
  MEASURED, not derived: the drawn markup is byte-identical from `amount = 172` onward — 9 rows
  (29 logs) at 171, 10 rows (32 logs) at 172, and still 10 rows (32 logs) at 500 and at 1,000,000 —
  because `h` can approach but never clear `WOODPILE_MAX_H`, so `h / ROW_H` can never clear 10.
  `companion-room.test.tsx`'s "saturates the drawn stack at 172 logs" pins this: identical innerHTML
  at 172, 500 and 1,000,000, and different at 171. The height keeps climbing forever; the picture
  stops changing at 172.
- **`CompanionBag::items()` is the single author of what the bag lists.** `companion-bag.tsx` maps
  `bag.items` with no category filter of its own, so nothing on the client would stop a wrongly-
  included row from rendering. Worth writing down before someone adds a second filter client-side.
