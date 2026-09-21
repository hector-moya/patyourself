# Companion F4.1 — the stash

**Status:** approved in brainstorming, 22 Sep 2026. The first of three sub-projects F4 was decomposed
into; see §2. Supersedes nothing. Where this document and `docs/BLOB.md` disagree, BLOB.md is right —
this is a design record, accurate for the day it was written.

---

## 1. The one sentence

**A chest you build, standing in the clearing, that holds materials at home rather than on Blob.**

The bag still spends. The stash only stores.

## 2. F4 is three sub-projects, not one

The arc's §6 sketches F4 as four things — *"consumption proper, the chest, more skills, wearables
migrating into the taxonomy"* — and says outright that designing it early would be guessing. With
F1–F3.6 built, it decomposes. Recorded here because the decomposition is itself a decision:

| Sub-project | Needs art | Why it is separate |
| --- | --- | --- |
| **F4.1 — the stash** (this document) | yes | competes for clearing pixels |
| **F4.2 — consumption proper** | no | economy only; needs its own design answer |
| **F4.3 — wearables into the taxonomy** | no | representation only |

**"More skills" is not on that list, and its absence is a finding rather than an omission.** Two
measured facts remove it:

- `CompanionBag.php:298` reads `$skill['node']` with a bare lookup, and F2's rule is that a recipe
  gates on a tool and never on a skill. So a skill that unlocks nothing in the world is not
  representable today: **a new skill means a new node.**
- The clearing is full. BLOB.md §12 records the measurement: four node cells at 48x41, 44x36, 48x47
  and 46x35 need **186 units of width in a 144-unit room**, before Blob's silhouette and the
  shelter's 48-wide cell. Overlap is already forced rather than chosen, and the heap overlaps Blob
  by 30x30 because no position clears Blob without colliding with another node.

A fifth node would be a second new object in a room that could not hold four. F4 is the last phase,
so **the world ends at four nodes and the chest**, and that is written down rather than left to be
rediscovered.

The ordering is F4.1 first because its answer scopes the other two: a new node would have added
materials, which is input to consumption.

## 3. What the stash is for, with the arithmetic

### It is the existing rule getting a second home

Arc §2's Capacity row already says: *"Capacity caps what Blob holds. The world keeps the overflow.
Nothing is ever destroyed."* Today the overflow can only sit at the node that grew it. A stash is a
second place the world keeps it — one the player chose, and one that can hold **built** goods, which
a node never could. Nodes hold their own yield and nothing else; rope and planks have nowhere to
rest but the bag.

### The problem it actually solves

`Companion::wouldFit()` is `held − consumed + made ≤ capacity`. Sawing is
`{recipe: {timber: 1}, makes: 3, tool: handsaw}`, a net +2, so it needs `held ≤ 3` at the moment it
runs. Hold 3 planks and the timber that has to be in hand to saw again, and that is 4;
`4 − 1 + 3 = 6 > 5`, refused.

**So a base bag tops out at 3 planks and then the saw refuses.** BLOB.md §8 records this as the
reason the basket or the barrow is a prerequisite of that build path rather than an optimisation.

Park those 3 planks in the chest and the saw works again. **Sawing goes from self-blocking to
unbounded.** That is the whole mechanical claim.

### What it does NOT solve, stated so nobody assumes otherwise

- **It does not fix F2's timber dead end.** The chest needs planks → handsaw → **rope**, so it sits
  downstream of the very thing a clogged bag is missing. F3 already closed that, twice: a harvest
  can take less than a bagful, and a carried stack can be tipped out.
- **It does not reverse §10's ruling that 26 salvaged planks are not self-sufficient.** The cabin
  costs 14 planks and `BuildShelter` spends from the bag, which holds 5 plus 5 per container. Two
  crates still stand between the heap and the cabin. Unchanged by this phase.

## 4. Price: 3 planks

Chosen rather than obvious, so the reasoning is recorded to be argued with.

**3 is the only plank price a base bag can reach unaided.** Saw once, hold 3, build.
`wouldFit()` allows it — `3 − 3 + 0 = 0 ≤ 5` — because a structure is not a carried category and so
contributes nothing to `made`.

At 4 it would need a container first, by the same arithmetic that makes the lean-to need one. That
would put the chest permanently *after* the containers it is meant to sit beside, and the first
plank thing a new account can build would go back to being nothing.

**It does not undercut the crate**, because they do different jobs: the chest **stores**, the crate
is what lets you **spend**. A player who wants to build the cabin still needs crates no matter how
much is at home.

## 5. Taxonomy and config

### One row in `config('companion.bag')`

```php
'chest' => [
    'category' => 'structure',
    'label' => 'chest',
    'recipe' => ['planks' => 3],
],
```

`structure` is arc §5's category for a world-standing permanent thing. It is **not** `container`:
containers raise carry capacity, and the stash explicitly does not.

### Why this may be a `bag` recipe when the shelter may not

§10's ruling keeping the shelter out of `bag` turns on one sentence: *"a recipe in `bag` becomes
knowable from its ingredients, so all three stages would list at once — and only the next stage is
ever listed."* **A chest has no stages**, so that rule is satisfied vacuously and the ruling does not
bind here.

Knowability then falls out for free. `CompanionBag::knowable()` is a fixed point over the catalogue:
planks are knowable once the trunk has been met, so the chest lists at the same moment the crate
does. No new gate, no reverse-gating, nothing added to the fixed point.

### One new refusal, and the listing that must agree with it

`BuildItem` currently does `firstOrCreate` then `increment('quantity', $makes)`. Without a guard the
chest stacks to quantity 2, which means nothing — there is no second stash. **`BuildItem` refuses a
build of a `structure` the companion already has**, as a `CompanionEconomyException`.

A refusal alone is not enough. `CompanionBag::recipes()` lists everything with a recipe that is
knowable (`CompanionBag.php:499–511`); it knows nothing about structures, so a built chest would
keep its row in the build list forever, offering a build that always refuses. **A `structure`
already standing drops out of the recipe list.**

This is the same shape as the shelter's only-the-next-stage rule, and it is the pair that matters:
the action that refuses and the read that offers must never disagree about whether a build is
possible — the property BLOB.md §8 calls *one arithmetic, two callers*.

### What may be stashed

**Exactly `config('companion.capacity.carried')`** — `material` and `consumable` — which is the same
list `DropItem` already enforces. Not a second list, because two lists describing one idea eventually
disagree.

- A **tool** is on the belt and never in the bag, so there is nothing to stash.
- A **container** in the stash would silently lower carry capacity, since `Companion::capacity()`
  sums the `capacity` field over held items. That is regression wearing a different hat.
- A **structure** is in the world already.

A non-carried category reaching the controller is a **404**, not a line in Blob's voice — the same
reasoning `CompanionItemController` follows, because there is no sentence for a gesture the screen
never offers.

## 6. Storage: a separate table

```
companion_stash_items
  id
  companion_id   foreignId, constrained, cascadeOnDelete
  item           string
  quantity       unsignedInteger
  timestamps
  unique(companion_id, item)
```

Same shape as `companion_items`, different table. `Companion::stashItems()` is a second `HasMany`.

### Why not a `location` column on `companion_items`

This is the load-bearing architectural call in F4.1, and it is decided by two facts read off the
code rather than by taste:

- `Companion::capacity()` (`Companion.php:145`) sums the `capacity` field over **`$this->items`**,
  unfiltered.
- `Companion::held()` (`Companion.php:167`) sums quantities over **`$this->items`**, unfiltered.

Add a `location` column and both silently change meaning: a stashed crate would raise carry
capacity, and stashed planks would count against the bag. Every existing caller becomes wrong, and
**no existing test would notice**, because every existing test creates rows that are all in one
location. The project's own memory records this bug class as *column-limited eager loads hide new
columns*, and it has bitten three times on one branch.

A separate relation cannot do that. `items` keeps meaning what it has always meant, by construction
rather than by a filter somebody has to remember to write.

The existing `unique(['companion_id','item'])` index is a second, smaller reason: a `location`
column would require index surgery on a table three phases of code already depend on.

## 7. Actions, controller, route

| Piece | Job |
| --- | --- |
| `StashItem` | bag → stash, an amount of one stack |
| `UnstashItem` | stash → bag, an amount of one stack |
| `CompanionStashController` | `POST companion/stash`, named `companion.stash.store` |

Both actions are transactional, following `BuildItem` and `DropItem`.

**`UnstashItem` respects capacity, and it mirrors `HarvestNode` exactly** rather than inventing a
second answer to the same question:

- A bag with **no room at all** refuses, and nothing moves.
- Otherwise it moves `min(stashed, room, wanted)` and **the remainder stays in the chest.**

A partial withdrawal is therefore the normal case, not an edge one — which is the behaviour a
player already knows from taking less than a bagful at a node. The chest is the same promise from
the other side: what will not fit is still there.

The refusal reuses `CompanionEconomyException::bagIsFull()` rather than a new factory, because it
is the same sentence about the same thing — there is no room, and what you were reaching for stays
where it was. `noRoomFor()` and its `CompanionCapacityException` subclass stay what they are: a
*build* that priced out fine with nowhere to put the result.

**`StashItem` needs no capacity check**, because the stash is uncapped.

### Why uncapped

The world is already uncapped everywhere else: node stock has no ceiling and nothing expires. A cap
here would be the first place in the feature where **the world refuses to hold something**, which is
a new kind of refusal for a phase that is otherwise reusing existing ones.

Containers keep their job regardless, because they govern **trip size** rather than total holdings:
a harvest takes `min(standing, room)`, so you still cannot move 26 planks in one act.

One accepted consequence: `DropItem` becomes rarely needed once a chest is standing. That is fine.
It was F3's answer to a dead end, and a dead end having a second exit does not make the first one
wrong.

## 8. Payload, and the second builder

`CompanionBag::forUser()` gains `'stash'`, carrying two things:

- **`standing`** — whether a chest has been built. The clearing needs this and cannot derive it: a
  chest is a `companion_items` row of category `structure`, which nothing on the drawing side reads.
  This is the flag `companion-room.tsx` draws on and `pages/companion.tsx` hangs the hotspot off,
  and it is what keeps an unbuilt chest from leaving an invisible hit region in the clearing — the
  defect 15e893a fixed for the shelter and `pages/companion.tsx:399` guards for nodes.
- **`items`** — the stacks at home, in the same shape as the carried list.

**It must be added in two places.** `CompanionBag::forUser()` has an early return for an account with
no companion row — `worldBeforeAnythingHappened()`, lines 72–87 — which builds its own payload
literal. A key added only to the main return leaves a brand-new account with no `stash` key at all,
and **nothing in the type system catches it: both paths satisfy `array`.** F3.6 shipped exactly this
defect and it was caught by a reviewer rather than by a test.

## 9. The surface

The bag modal gains an **at home** section beside the carried one. Each stack carries a move-in /
move-out control, reusing the take-an-amount control F3 built rather than inventing a second one.

Clicking the chest in the clearing opens the bag at that section — the same relationship a node
already has, where the encounter opens the bag.

**The rule this surface must pass** is the rewritten one from arc §4: *never show a total, a
completion figure, or an end state; show what is choosable now.* An at-home list with stacks and
amounts passes. Anything reading "3 of 5 stored" does not, and `companion.test.tsx`'s
`never shows what has not happened` guard (matching `/locked|next up|to unlock|remaining|streak|\d+ of \d+/`)
is what holds it.

An empty stash shows an empty section, not a placeholder naming what could go in it.

## 10. The clearing

### One sprite, not bands

The band machinery — `config('companion.node_bands')`, `bandFor()`, `NODE_SPRITES`, `nodeSprite()` —
is node-shaped. Generalising it to a structure so the chest visibly fills would buy a picture the
modal already gives precisely, at the cost of two more generations and a second band model.

A chest is a chest. Its contents are in the bag.

### Placement is a render pass, not a number in this spec

Constraints it inherits:

- The chest is the **fifth** object in a room that already forces overlap between four.
- **Array order in `scenes.ts` is paint order**, back to front, and the page's hotspot loop follows
  it — so the nearer control sits above the further one where hit regions overlap. Nothing enforces
  that order; the F3.6 hotspot-geometry assertions cover the reeds only.
- Nearer things sit lower and overlap further things. That rule came from renders, not arithmetic.
- The chest exists only once built, so it costs nothing to an account that has not built one.

**Starting hypothesis for the pass, explicitly not a decision: beside the shelter, x ≈ 44.** A chest
at home reads as at home, and that column is already the built-things column. It has to be rendered
before it is believed — the shelter's own `y=34` was chosen only after four heights were rendered
past the measured ground line, and its measured line turned out to be the clearing's back wall.

### Art pipeline

Follows `scenes/README.md`'s "Running this pipeline again" runbook verbatim:

1. `create_1_direction_object`, `view: sidescroller`, with a style crop taken from the real backdrop
   at the chest's real position. `sips --cropOffset` takes **row then column**, the opposite order
   from the prose's "PNG col, row".
2. Quantise the composited reference before passing it as base64 — it truncates in transit past
   roughly 1KB. Quantise the **composite**, never a transparent cutout, or the transparency
   quantises to black and teaches the model to draw a black backing.
3. Judge candidates **composited over the real backdrop, at the real position, with the real Blob
   sprite**. Never cutouts on transparency.
4. **Crop to the art's own bounding box.** The crop is what sets the cell; the 48px square the
   generator forces is not the cell. Every generated cell carries 6–12 transparent rows below its
   art, and composited raw the object appears to levitate.
5. Promote with `select_object_frames`, discard the rest with `dismiss_review`, tag it `f41-chest`.
6. Budget roughly **8 minutes per generation job** and do not poll in a tight loop.
7. `SendUserFile` is not available to a subagent. Use `Artifact` to show candidate sheets.

The chest needs no state edits and no bands, so this is the simplest art task the feature has had
since the foliage — one object, one candidate, one crop.

## 11. Copy

A build already announces itself: `CompanionBuildController` flashes
`"{$name} put together a {$label}."` from the controller rather than from config, so **the chest
needs no build line**.

Two new config keys, in the same register as `companion.drop`:

```php
'stash' => [
    'in'   => '{name} puts {count} {label} in the chest.',
    'out'  => '{name} lifts {count} {label} back out of the chest.',
    'full' => 'There is nowhere to put them. The {label} stays in the chest.',
],
```

Rules these follow, unchanged from every other line in the feature: sentence case, one or two
sentences, no exclamation marks, never congratulating, `{name}` rather than the literal, describes
what Blob did and never how the player is doing, never states a plan. `full` mirrors a node's own
`full` line because it is the same event seen from the other side — it names where the thing stays,
because nothing is ever destroyed.

## 12. Testing: the mutations, each to be run

BLOB.md trap 1, sharpened by F3.6: **naming the mutation is not enough — you have to run it.** Three
stated mutations on F3.6 were false or overstated, every one written by the plan's own author and
believed until somebody applied it. Each row below is to be applied and observed, and the observed
result recorded.

| Guard | Mutation to apply | Must turn red |
| --- | --- | --- |
| The second builder carries `stash` | delete the `stash` key from `worldBeforeAnythingHappened()` | a brand-new account rendering `/companion` |
| Storage is a separate table | point the stash query at `companion_items` | a `held()` or `capacity()` assertion with something stashed |
| Withdrawal respects capacity | remove the `room` clamp in `UnstashItem` | an over-withdraw test asserting the bag never exceeds capacity |
| A full bag refuses, moving nothing | return 0 instead of throwing when `room === 0` | a test asserting the refusal and an untouched stash |
| The remainder stays in the chest | delete the stash row after a partial withdrawal | a test asserting what was left over is still at home |
| The stash is uncapped | add a ceiling | a large-deposit test |
| A structure stands once | remove the `BuildItem` refusal | a second-build test |
| The listing agrees with the refusal | stop excluding a standing structure from `recipes()` | a test asserting the chest leaves the build list once built |
| An unbuilt chest draws nothing | hardcode `stash.standing` to `true` | a test asserting no chest art and no hit region before it is built |
| Only carried categories stash | allow a `container` through the controller | a 404 test |

Two guards that are not mutations but are required:

- **Vocabulary.** Every new companion source file goes into `CompanionVocabularyTest::sourceFiles()`
  in the same task that creates it. A file absent from that list is scanned by nothing, and
  `companion-glyph.tsx` is already sitting in that gap. `points` is a substring trap.
- **Trap 9, which has bitten three times on one branch.** `companion.test.tsx`'s
  `never shows what has not happened` guard runs against a shared fixture, bag closed and bag open.
  The fixture must be widened for the stash section **in the same task**, and the test must **assert
  the section actually rendered**. Widening the obvious field is not proof a section renders — an
  unnamed section does not fail the guard, it silently drops out of what the guard covers.

## 13. Rules this was checked against

| Rule | Verdict |
| --- | --- |
| Only ever show what has happened | Holds. An empty stash shows an empty section, never a slot naming what belongs in it. |
| Never state a plan | Holds. The chest's recipe is a price, which §2 explicitly allows. |
| Blob's life is never a mirror | Holds. The three new lines describe Blob and the chest. |
| Nothing regresses | Holds. Nothing decays, expires or is removed. A refused withdrawal leaves the stash untouched. |
| Blob never asks to be pressed | Holds. Untouched. |
| Destruction is always player-initiated | Holds. The stash destroys nothing; `DropItem` remains the only path that does. |
| Store only what cannot be derived | Holds. Stash contents are a choice, not a count — the same standing the bag has. |
| A tool never occupies bag capacity | Holds. Tools are not stashable because they are not carried. |
| Never show a total or an end state | Holds, and is guarded by the existing regex. |

## 14. Not in F4.1

Consumption proper (F4.2), wearables migrating into the taxonomy (F4.3), any new node or skill (cut
from F4 entirely, see §2).

Also untouched, and still open from BLOB.md §12: the flat-rect scarf, hat and glasses; the
unguarded prop-versus-item collision; `ROOM_OBJECTS` and `SPRITE_ITEMS`' prototype-chain
fallthrough; `companion-glyph.tsx`'s absence from the vocabulary list.

## 15. What would make this wrong

Recorded so a later reader can check rather than re-derive.

- If `Companion::held()` or `capacity()` ever counts a stashed row, §6's whole reason for a separate
  table has been defeated and the bag's arithmetic is lying.
- If a recipe ever spends from the stash, containers have become decoration and `wouldFit()` — the
  one arithmetic the refusing action and the predicting read are required to share — has grown a
  second notion of "held".
- If the stash ever refuses a deposit, §7's "the world is uncapped" has been broken and the world
  has started saying no.
- If the at-home section ever shows a figure of the form "n of m", arc §4's rewritten rule has been
  broken and the bag is a checklist again.
- If a built chest is still listed as buildable, the read and the action have split and §5's
  *one arithmetic, two callers* property has been lost.
- If the chest ships without being rendered in place, §10's placement hypothesis has been believed
  instead of checked — which is the exact failure the shelter's `y=34` and the trunk's bands each
  cost a round on.
