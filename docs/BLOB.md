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

**Store only what cannot be derived.** Five tables hold the chosen half of Blob — `companions`
(the name, `xp_spent`, `shelter` — what has been built — and `salvaged_at` — whether a granted
cabin was already handed back), `companion_skills`, `companion_items`, `companion_nodes` and
`companion_remarks` (the coach's own lines, written via `WriteBlobRemark` and the MCP tool, read
on every `/companion` request). Nothing else. Counts, earned XP, the scene and the whole gift
ladder are still recomputed from the record on every read, so the parts that could drift still
cannot.

Where Blob is *looking* is deliberately not among them. `inside` is `useState(false)` in
`resources/js/pages/companion.tsx`, with a comment saying why, and it resets to outside on load the
way the bag itself does — a view, not a thing anyone owns.

The rule survived its own replacement: the pre-F1 premise was "nothing is stored, so nothing can
drift", and what made that valuable was never the storage count. It was that no stored number
claims to describe the record. A choice is not a claim about the record, which is why a choice may
be stored and a count may not.

```
outcomes + summaries in the DB     companions + its three tables     companion_remarks
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
the whole arc needs a container before it can be paid for, which makes the basket or the barrow a
prerequisite of the build arc rather than an optimisation. One route reaches 5 anyway: tip a plank
out (held drops to 2), harvest one timber (held 3), saw (2 kept + 3 made = 5). It costs a plank, and
it is the only way to a lean-to without building a container first.

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

## 9. Where everything lives

```
config/companion.php                    ladder, tail, scenes, parts of day, renderer, xp, skills,
                                         nodes, the bag, capacity, the shelter, salvage
database/migrations/
  ..._000001_add_the_shelter_to_companions_table.php   shelter + salvaged_at
  ..._000002_salvage_granted_cabins.php                the one-time backfill
app/Models/
  Companion.php                         the name, xp_spent, capacity(), held(), wouldFit(),
                                         shortfallFor(), spend()
  CompanionItem.php                     one stack held in the bag
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
  CompanionNameController.php           renaming Blob

resources/js/hooks/use-sprite-clock.ts  the one rAF loop
resources/js/patyourself/
  companion.tsx                         payload types, ambientFor, selfStartedFor, actionsFor
  companion-bag.tsx                     the bag modal: contents, the build list, the skill list
  companion-animations.ts               the animation registry
  companion-room.tsx                    the scene compositor and the light; takes inside and shelter
  companion-glyph.tsx                   the 8x8 mark beside a record line: sprout, crate, spark
  blob-renderer.tsx                     both renderers, worn items, ability props
  sprite-layout.ts                      forms, cells, the 231 anchors
  sprite-items.tsx                      what Blob wears, rects or sheets
  scenes.ts                             the scene registry; each scene may carry a shelter coordinate
  part-of-day.ts                        partOfDay, asleepAt(), wakingAt()
  sprites/   + README.md                bodies, worn-item sheets, every measurement
  scenes/    + README.md                backdrops, foliage, the light's reasoning
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

## 11. Traps that have already bitten

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
9. **When a task adds a conditionally-rendered section, the shared fixture must be widened in the
   same task, or every guard downstream goes quiet rather than red.** It has bitten three times on
   one branch, always in `companion.test.tsx`'s "never shows what has not happened" guard — the
   acceptance criterion for the whole feature, run twice, bag closed and bag open. The three nodes'
   shared `available: 0` default hid `Clearing` from both runs (Batch 1); `shelter.offer`'s default
   of `null` hid `Shelter`, and rendering `1 of 4` as visible text there left 38/38 green (Batch 2);
   and `ShelterSpot` needed two fixture fields widened together — `bag.shelter.built` *and* the
   fixture's default scene, `cabin`, whose `SCENES` entry names no `shelter` coordinate — because
   widening `built` alone still left the object absent (Batch 3). This differs from trap 1: that one
   is about a single test proving nothing; this is about a **shared** fixture, where an unnamed
   section does not fail the guard, it silently drops out of what the guard covers, and every later
   addition to the same surface inherits the blind spot. Widening the obvious field is not proof a
   section renders — assert that it did.

## 12. What is not done

- **F4 (depth)** is what remains. F1 (the bag), F2 (the tools) and F3 (the shelter) are built; the
  reasoning behind the whole arc, and what F4 is sketched to cover, lives in
  `docs/superpowers/specs/2026-09-17-companion-progression-arc-design.md`.
- ~~A full bag of timber has no exit.~~ **Closed by F3**: a harvest can take less than a bagful, and
  a carried stack can be tipped out. Both are the player's act; nothing discards on their behalf.
- **Node hotspot labels do not scale with the stage.** A fixed 8px font, so below roughly a 300px
  stage the labels are larger than the room. F3 added a fifth object to the clearing, which makes it
  urgent; it is a label-sizing problem and was deliberately left out of scope.
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
