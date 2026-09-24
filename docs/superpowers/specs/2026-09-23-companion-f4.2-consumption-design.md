# Companion F4.2 — consumption proper

**Status:** approved in brainstorming, 23 Sep 2026. The second of three sub-projects F4 was decomposed
into; see the F4.1 spec's §2 for the decomposition. Supersedes nothing. Where this document and
`docs/BLOB.md` disagree, BLOB.md is right — this is a design record, accurate for the day it was
written.

---

## 1. The one sentence

**A pile you keep feeding, standing inside the shelter, that takes wood and never gives it back.**

The bag spends. The stash stores. **The pile consumes.**

## 2. What "consumption proper" turned out to mean

The arc names F4 as *"consumption proper, the chest, more skills, wearables migrating into the
taxonomy"* and says designing it early would be guessing. It was. Three readings are ruled out before
this one, and they are recorded so nobody re-proposes them:

- **Tools wearing out** — forbidden. BLOB.md §10: *"a tool never wears out or needs repair.
  Durability is removal with extra steps."*
- **Materials spent by recipes** — already shipped since F1. Materials transform; spending fibre on a
  basket is the basket having fibre in it.
- **Anything that decays, expires, or is removed for inactivity** — forbidden by §2's *nothing
  regresses* and §10's *destruction is always player-initiated*.

What is left is a narrow gap: **player-initiated, not decay, and nothing Blob owns gets worse.**

### The problem that fits in it

**The economy terminates.** Measured on this branch:

- `LearnSkill.php:66` is the only writer of `xp_spent`, and three skills at 20 each
  (`config/companion.php:467–469`) make **60 XP the entire amount any account will ever spend.** Past
  roughly four concluded experiments the wallet only grows. "More skills" was cut from F4 on
  measurement — a skill needs a node, and the world ends at four — so nothing refills that sink.
- After the cabin and the chest, **the only new thing any further material can become is another
  container.** `Companion::capacity()` multiplies a container's `capacity` by its quantity, so crates
  stack +5 forever. The terminal loop is *build crates to carry materials you have nothing to spend
  on.*

That is an **end state**, and arc §2's Discovery row forbids one outright: *"No totals, no
completion, no end state."* F4.2 exists to remove it.

### The other finding, kept for F4.3 rather than solved here

**`consumable` is a category with no behaviour.** Grepped across `app/`, `resources/js/` and
`config/`, the word occurs exactly twice, both as data — `config/companion.php:658` (rope's category)
and `:833` (the `carried` list). Nothing branches on `consumable` versus `material`. Rope is a
material with a second name, and the arc's own taxonomy table (§5) describes both identically:
*"in the bag; consumed by recipes."*

This is real and it is **not** what F4.2 fixes. It is representation, which is F4.3's subject. Noted
here so the next phase inherits the measurement rather than re-deriving it.

## 3. What was decided, and what it cost to decide

The payback question — *what does spending into the sink give back?* — was settled as **Blob's
behaviour**, over pure divergence, over an economic return, and over Blob's voice. Two measurements
then constrained it hard:

- **There is no spare sprite row.** `humus-arms.png` is 384×704 at a 64px cell — exactly 11 rows —
  and exactly 11 animations exist (`idle, blink, walk, wave, jump, sleep, stretch, look, pet, play,
  notice`). A genuinely new motion means regenerating a sheet three phases depend on. F4.1's own
  decomposition row says F4.2 **needs art: no**, and that row stands.
- **Blob's position is fixed.** `BlobRenderer` at `companion-room.tsx:750` takes no coordinate. Blob
  draws at the room's origin, after the room objects and before the light — always centred, always in
  front.

So a finite set of behaviours cannot back an unbounded sink. The resolution, chosen knowingly:

> **The behaviour unlocks once. Only the picture scales after that.**

Spending is unbounded, behaviours stay at eleven, and no art is generated. The marginal return past
the first deposit is visual, and that is accepted rather than glossed over.

## 4. What the pile takes

```php
'woodpile' => [
    'takes' => ['deadfall', 'timber', 'planks'],
    'stacked' => '{name} stacks {count} {label} against the wall.',
],
```

**This is not a second `carried` list.** F4.1 refused one on the grounds that *two lists describing
one idea eventually disagree*, and that reasoning holds — but it is not the same idea. `carried` says
what the bag may hold; `takes` says what this one object accepts, which is the kind of statement a
recipe has always made about its ingredients. Fibre and rope are not wood.

**A plank and a deadfall count the same.** Deliberate. The pile's job is to absorb whatever there is
too much of, and it is a picture rather than an inventory.

## 5. Storage: one column

```
companions.woodpile   unsignedInteger, default 0
```

No table, no per-material breakdown. **Nothing comes back out, so there is nothing to remember.**

This is a *choice*, not a count of the record — the same standing `xp_spent` and `shelter` already
have, and the reason "store only what cannot be derived" permits it.

### Why it is one-way, and why that is not destruction

Material entering the pile is **spent**, exactly as fibre spent on a basket is spent. It is
player-initiated, it names its cost before it is paid, and nothing is taken on the player's behalf.
`DropItem` remains the only path that *destroys*, and `CompanionRulingsTest::test_nothing_is_dropped_on_the_players_behalf`
is untouched.

A one-way sink that could be emptied would be a third stash, and F4.1 already built the stash.

## 6. The pile is not built

There is no recipe, no price and no duplicate-`structure` refusal. **The pile exists the moment
something is in it**, and an amount of zero draws nothing — which satisfies *only ever show what has
happened* without a placeholder, and without the `BuildItem`/`recipes()` agreement F4.1's chest
needed.

**One gate: a shelter must be standing**, because there is no indoors to stack against otherwise.
The bag's control is **absent** without one rather than disabled — an empty slot is a to-do — and a
request arriving anyway is a **404**, not a line in Blob's voice. Same reasoning
`CompanionItemController` and `CompanionStashController` already follow, because there is no sentence
for a gesture the screen never offers. The 404 is the backstop, not the surface.

## 7. The behaviour, and the ruling it needs

**Blob's resting hours move indoors once a pile stands.** Same `sleep` and `idle` rows, the interior
drawn around them instead of the clearing.

Today you can build a cabin and Blob still stands outside it. This is the first time in the whole
feature that **what you built changes where Blob is.**

### The ruling, written rather than assumed

> **Whether Blob sleeps stays a function of the clock alone. Where it sleeps becomes a function of
> what you built.**

`asleepAt()` and `wakingAt()` must not learn about the pile. Only the view does. BLOB.md §2 is
explicit that the day this starts reading what has been recorded, *a state of being alive has quietly
become a reward* — and the guard for that names `logCount`, `insightCount` and the unlocks. The pile
is none of those: it is a purchase, like the cabin, and the cabin is already the thing Blob is
standing next to.

### How it is implemented, and the rule that falls out for free

`inside` is `useState(false)` in `resources/js/pages/companion.tsx`. Its initialiser becomes:

```
asleepAt(hour, room) && bag.woodpile.amount > 0
```

**A `useState` initialiser runs once, at mount.** So the page opens indoors at night when a pile
stands, an explicit toggle afterwards is never overridden, and the view never flips under the
player's hand as the hour ticks. That is three separate behaviours that need no extra rule to hold —
they fall out of where the value is read.

### Two accepted consequences

- **Harvesting at night gains one click.** The clearing's hotspots are not reachable from indoors
  (`pages/companion.tsx` says so outright: *"nothing grows in a room"*). Stepping out is one click
  and always available, so nothing is lost — but a default did change, and it is recorded here rather
  than discovered later.
- **A page loaded in daylight stays outside when the hour crosses into night.** Same class as the
  `prefers-reduced-motion` hour-staleness already recorded in BLOB.md §6. Nothing is worse than the
  pre-pile behaviour, which was Blob asleep in the clearing.

## 8. The picture

Hand-drawn SVG in `ROOM_OBJECTS`' own idiom — inline rects, no Pixel Lab, no sprite. It is
deliberately **not** a `ROOM_OBJECT`: those arrive from the ladder as gifts, and this one is bought.

**Drawn in `companion-room.tsx`'s `indoors` branch only.** It is never in the clearing, at any
amount, which is what keeps this phase clear of the placement fight F4.1 measured — four node cells
needing 186 units of width in a 144-unit room.

**Placement: the measured gap at x[18,38].** The interior's floor is occupied by `bookshelf`
(ends x −34), `rug` (−18…18), `plant` (from 39) and `lamp` (62…66). Two gaps exist, x[−34,−18] and
x[18,38]; the right-hand one is the wider.

### The picture is asymptotic, by necessity

The room is 114 units tall and the pile is uncapped. So its drawn size must be **monotonic
non-decreasing, fast at first and slower forever, with no plateau that reads as full.** Unbounded
input, bounded picture, and no figure shown anywhere.

**The exact curve is a render pass, not a number in this spec.** The shelter's `y=34` was chosen only
after four heights were rendered past a measured line that turned out to be the clearing's back wall,
and the trunk's bands shipped broken with every arithmetic check green. This is the same class of
judgement and gets the same treatment: render it in sequence at increasing amounts and watch whether
it ever looks finished.

## 9. The surface

A **stack it** control on each wood stack in the bag's carried section, mirroring the stash's
`put away` control beside it (`companion-bag.tsx:129`) exactly: a `Form` with `preserveScroll`, two
hidden inputs, one submit button, **and no amount input — it posts the whole stack.**

Measured rather than assumed: the Held row's `put away` sends no `amount`, so "reuse F3's
take-an-amount control" would have been inventing a control the bag does not have. The action still
accepts an optional amount, the same as `StashItem` does, so the route is not narrowed by what the
screen currently offers.

**No clearing hotspot.** The pile is indoors, and the clearing's controls are unreachable from there
by design. The chest's *click it, the bag opens at that section* relationship does not transfer,
because the pile has no section — it has a control on each stack that may feed it.

The rule this surface must pass is arc §4's rewritten one: *never show a total, a completion figure,
or an end state; show what is choosable now.* A stack with an amount control passes. Anything reading
"12 in the pile" is a total and does not — **the amount is never rendered as text, only as the
picture's size.**

## 10. Payload, and the second builder

`CompanionBag::forUser()` gains `'woodpile'`, carrying:

- **`amount`** — what is in it. The room draws from this and nothing else can derive it.
- **`takes`** — which held stacks may be fed, so the bag's control appears on exactly those rows
  without the client re-authoring config's list.

**It must be added in two places.** `CompanionBag::forUser()` has an early return for an account with
no companion row — `worldBeforeAnythingHappened()` — which builds its own payload literal. A key
added only to the main return leaves a brand-new account with no `woodpile` key at all, and **nothing
in the type system catches it: both paths satisfy `array`.** F3.6 shipped exactly this defect; F4.1
had to guard against it again.

`pages/companion.tsx` passes `bag.woodpile.amount` to `CompanionRoom`, the same way it already passes
`bag.shelter.built`, `bag.nodes` and `bag.stash.standing`.

## 11. Actions, controller, route

| Piece | Job |
| --- | --- |
| `StackWood` | bag → pile, an amount of one stack; one-way |
| `CompanionWoodpileController` | `POST companion/woodpile`, named `companion.woodpile.store` |

Transactional, following `BuildItem`, `DropItem` and `StashItem`.

**No capacity check in either direction.** Nothing comes back, so there is no room to make; and the
pile is uncapped for the same reason the stash is — *the world is already uncapped everywhere else in
this feature, and a limit here would be the first place the world refuses to hold something.*

Copy follows every other line in the feature: sentence case, one or two sentences, no exclamation
marks, never congratulating, `{name}` rather than the literal, describes what Blob did and never how
the player is doing, never states a plan.

## 12. Testing: the mutations, each to be run

BLOB.md trap 1, sharpened by F3.6 and paid for again on F4.1: **naming the mutation is not enough —
you have to run it.** Four stated mutations on F4.1 were false or overstated, and one of them was
written by a reviewer rather than by the plan's author. Each row below is to be applied, run,
observed, reverted, and the **observed** result recorded. A mutation that stays green is a finding
about the test, not a failure.

| Guard | Mutation to apply | Must turn red |
| --- | --- | --- |
| The pile is uncapped | add a ceiling to `StackWood` | a deposit larger than that ceiling, asserting the full amount landed |
| The pile needs a shelter | remove the shelter guard in the controller | a 404 test for an account with no shelter |
| Only what `takes` names | remove the membership check | a 404 test posting `fibre` |
| Blob's night is indoors once a pile stands | seed `inside` to `false` unconditionally | a client test asserting `data-interior` at a night hour with a pile |
| Whether Blob sleeps is still clock-only | make `asleepAt` read the pile | a test asserting a pile-less Blob still sleeps at night |
| Zero draws nothing | draw the pile whenever indoors | a test asserting no pile element before anything is stacked |
| The second builder carries `woodpile` | delete the key from `worldBeforeAnythingHappened()` | a brand-new account rendering `/companion` |
| The control appears only on wood | offer it on every carried row | a test asserting `fibre` has no stack control |
| The amount is never text | render the amount beside the pile | a test asserting no digit characters inside the control's own `<form>` — **not** `companion.test.tsx`'s `never shows what has not happened` guard, whose regex (`/locked\|next up\|to unlock\|remaining\|streak\|\d+ of \d+/`) does not match a bare integer |

**A ceiling has two shapes**, and F4.1 paid for learning it: a *clamp* (limit this call) and a
*threshold* (refuse once N is held). A test starting from an empty pile can only ever see the first.
The uncapped test must start from a pile that already holds a large amount.

**The row above is corrected, not aspirational — running the mutation disproved the original claim.**
`never shows what has not happened` stayed green with the amount rendered as text beside the pile,
because its regex was never written to catch a bare integer. What actually turns red is the dedicated
no-digits test, and only that test.

Two guards that are not mutations but are required:

- **Vocabulary.** Every new companion source file goes into `CompanionVocabularyTest::sourceFiles()`
  **in the same task that creates it**. A file absent from that list is scanned by nothing.
  `points` is a substring trap.
- **Trap 9, which has now bitten four times.** `companion.test.tsx`'s
  `never shows what has not happened` guard runs against a shared fixture, bag closed and bag open.
  The fixture must be widened for the woodpile **in the same task**, and the test must **assert the
  control actually rendered**. Widening the obvious field is not proof a section renders — an unnamed
  section does not fail the guard, it silently drops out of what the guard covers.

## 13. Folded in from BLOB.md §12

Three open items, all cheap, all touching surfaces this phase opens anyway. A separable final batch —
if any of them grows, it is cut rather than carried.

| Item | Fix | Mutation |
| --- | --- | --- |
| `Companion::capacity()` sums `capacity` across **every** held item regardless of category | filter to `container` | revert the filter, with a test that sets `'capacity' => 5` on the chest's config row and asserts capacity stays 5 — measured on F4.1 to turn 5 into 10 |
| The bag's `Held` section lists a standing chest | filter `structure` out of `CompanionBag::items()` | stop filtering; a test asserting a standing chest is not listed as carried |
| `$companion->load('items')` states a premise that does not hold at `HarvestNode:86` and `BuildItem:109` | correct the comments, as F4.1 did for `UnstashItem:69` | none — a comment, and it is recorded as such rather than given a fake guard |

`UnstashItem`'s missing non-carried-category guard is **not** folded in. It is unreachable today, and
BLOB.md §12 records why it would only ever cost something if a second way to populate the stash
appeared. A guard over a state the system cannot reach is what cost the `blob` form its sleep
amplitude.

## 14. Rules this was checked against

| Rule | Verdict |
| --- | --- |
| Only ever show what has happened | Holds. An empty pile draws nothing — no outline, no placeholder naming what belongs in it. |
| Never state a plan | Holds. `takes` is a price, which §2 explicitly allows; nothing names what to go and get. |
| Blob's life is never a mirror | Holds. One line, describing what Blob did with the wood. |
| Nothing regresses | Holds. Nothing decays, expires or is removed. The pile only grows. |
| Blob never asks to be pressed | Holds. Untouched. |
| Destruction is always player-initiated | Holds. Feeding the pile is spending, and `DropItem` remains the only path that destroys. |
| Store only what cannot be derived | Holds. One column, holding a choice rather than a count. |
| A sleeping Blob is the clock alone | Holds, **restated**: whether is the clock; where is what you built. §7 carries the ruling. |
| Never show a total or an end state | Holds. The amount is never rendered as text. |
| A tool never wears out | Untouched. Nothing here consumes a tool. |
| The world ends at four nodes and the chest | Holds. No new node, no new clearing object; the pile is indoors. |
| F4.2 needs art: no | Holds. Hand-drawn SVG in the interior's existing idiom, zero Pixel Lab jobs. |

## 15. Not in F4.2

F4.3 — wearables migrating into the taxonomy, and with it the `consumable`-has-no-behaviour finding
in §2. Any new node or skill, cut from F4 entirely.

Still open and untouched, from BLOB.md §12: the flat-rect scarf, hat and glasses; the unguarded
prop-versus-item collision; `ROOM_OBJECTS` and `SPRITE_ITEMS`' prototype-chain fallthrough;
`companion-glyph.tsx`'s absence from the vocabulary list; the Settings starter-kit shell; the
`blob` form's flat sleep row and the over-broad scarf-clearance guard behind it.

## 16. What would make this wrong

Recorded so a later reader can check rather than re-derive.

- If anything ever comes **out** of the pile, it has become a third stash and §5's whole reason for a
  single integer is gone.
- If `asleepAt()` or `wakingAt()` ever reads the pile, §7's ruling has been broken and a state of
  being alive has become a reward.
- If the pile's amount is ever rendered as a figure, arc §4's rewritten rule has been broken and the
  interior is a checklist. **Not** caught by `companion.test.tsx`'s `never shows what has not
  happened` guard — its regex does not match a bare integer, disproved by running the mutation — so
  the check that actually holds this is the dedicated no-digits test scoped to the control's own
  `<form>`.
- If the drawn HEIGHT ever reaches a size it stops growing from, §8's asymptote has been implemented
  as a cap and the end state F4.2 exists to remove has been rebuilt inside the fix. **This is not the
  same claim as the drawn STACK settling on one fixed shape past some amount** — a discrete row count
  computed from a continuous height necessarily saturates once the height rounds to the last row, and
  that saturating is not a cap on the height itself. Measured: the drawn markup is byte-identical from
  `amount = 172` onward (10 rows / 32 logs, unchanging through 500 and 1,000,000), while
  `woodpileHeight()` keeps climbing toward, and never reaches, `WOODPILE_MAX_H` for every one of those
  amounts.
- If the pile ever refuses a deposit for being full, the world has started saying no — the same
  failure §7 of the F4.1 spec names for the stash.
- If a recipe ever spends from the pile, `wouldFit()` has grown a second notion of "held" and
  containers are decoration.
- If the pile ships without being rendered at increasing amounts, §8's asymptote has been believed
  instead of checked — the exact failure the shelter's `y=34` and the trunk's bands each cost a round
  on.
