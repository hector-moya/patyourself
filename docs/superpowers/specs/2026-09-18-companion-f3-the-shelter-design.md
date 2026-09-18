# F3 — The shelter

**Status:** designed, not yet planned. Phase F3 of
`2026-09-17-companion-progression-arc-design.md`. F1 (`2026-09-17-companion-f1-the-bag-design.md`)
and F2 (`2026-09-17-companion-f2-the-tools-design.md`) are built and merged. Read the arc first; this
document argues from it and does not restate its reasoning.

When F3 ships, the clearing has a shelter standing in it that Blob built, it grows from a lean-to to
a hut to a cabin, and you can go inside.

## 1. The arc's own sentence was wrong, and the correction is better

Arc §6 describes F3 in one sentence that contradicts itself:

> `lean-to` and `hut` as **built** scenes rather than threshold-triggered ones. … **The threshold
> rule from E1 still binds: `cabin` sits at `insights: 5` and never moves; lean-to and hut are
> inserted below it.**

"Built" means a stored choice. "Inserted below it" means a threshold. They are different mechanisms,
and `CompanionState::scene()` today walks thresholds *independently* — its own docblock notes the
record can reach `cabin` "without ever passing through an intermediate scene." On that code an
established account with five insights is **already in the cabin** and would skip the entire build
arc.

The correction, settled in brainstorming, is not a compromise between the two readings. It discards
both:

**The forest is always the world.** It is never replaced. The shelter is an **object standing in the
clearing** that upgrades in place — lean-to becomes hut becomes cabin, one at a time, never side by
side. Clicking it offers *go inside*, which opens a separate interior view.

This is better than what the arc proposed on three counts, and the third is the one that matters:

- **It keeps the forest.** The four baked Pixel Lab backdrops are the prettiest thing in the feature
  and the arc's version teleported you out of them permanently.
- **It costs no new backdrops.** Two new scenes × four parts of day would have been eight. This needs
  three small structure objects and reuses the interior that already exists.
- **It separates two things the arc conflated.** Where Blob *is* and what Blob has *built* are
  independent facts. You can stand outside a cabin or sit inside a lean-to.

## 2. Two founding rules, overturned — recorded rather than assumed

The arc doc records its own overturns with reasoning so a later reader can check rather than
re-derive. These follow that form.

### OVERTURNED — the cabin is no longer a gift

`insights: 5` stops being *when the cabin arrives* and becomes *the floor at which it may be built*.
The threshold itself never moves, so E1's rule survives in letter: nothing is re-dated, and no
established record is walked backwards out of a threshold it passed.

Why this is right rather than merely convenient:

- **The cabin was never art.** `SCENES.cabin` carries `backdrops: {}` and a comment saying the
  interior "is still drawn by `CompanionRoom` itself — wall, floor, window, `ROOM_OBJECTS`." The
  thing being taken away from an established account is drawn geometry, not the pixel art the
  forest has.
- **Building it is the reward the arc was designed around.** Arc §3's effort-justification argument
  is explicit: *people value what they built*. A cabin handed over at a threshold is the one part of
  the shelter arc that was never earned in the sense the rest of the feature means it.
- **It is not a hook.** The loop is: keep an honest record, earn a little XP, learn a small skill,
  which may one day help you build a cabin. That is a pat on the back with a long tail, not a
  variable-ratio slot machine — arc §3 rules the latter out and this does not approach it.

### OVERTURNED — a player may destroy a material

Arc §4 holds that "materials transform; they are never taken." F3 adds a **drop** that destroys
outright: what is dropped does not return to the node, the world, or anywhere else.

**This is not the rule breaking, and the spec says so explicitly so a later reader does not think it
did.** Read §4's enumeration: *no decay, no expiry, no upkeep, nothing removed from Blob for
inactivity, ever.* Every prohibition names something **the system does to you**. A player choosing to
drop timber is none of them — it is the same category as choosing to spend fibre on a basket. Arc §2
celebrates exactly this: *the player spends, and worlds diverge permanently.*

The line to hold, and the one a later phase could cross without noticing:

> **Destruction is always player-initiated and never automatic.** Nothing in this feature may ever
> drop, decay, expire or discard on the player's behalf, for any reason, including a full bag.

## 3. Where Blob is, and what Blob built

`scene` today is a single derived string. It splits in two:

```
where Blob is    outside | inside        transient, not stored
what is built    none | lean-to | hut | cabin    stored, never regresses
```

**Inside/outside is not persisted.** It is where you are looking, not something you own. It resets to
outside on load, the way a modal does.

**The stage is stored** and only ever advances. It is a single value rather than a collection,
because the stages replace one another — building the hut *is* the lean-to becoming a hut, and the
lean-to's planks are in it.

### What the interior is

`CompanionRoom` keeps drawing what it draws today, parameterised by stage:

| stage | interior |
| --- | --- |
| `lean-to` | open side, no window, objects on the ground |
| `hut` | walls, a shuttered gap |
| `cabin` | wall, floor, window — **exactly today's drawing, unchanged** |

Room objects from the gift ladder are drawn in whatever exists. A bookshelf under a lean-to is funny
rather than wrong, and withholding it would mean the ladder could hand over something invisible —
which BLOB.md already records as a ruling it will not reopen.

## 4. Content

| Stage | Cost | Available when |
| --- | --- | --- |
| `lean-to` | **4 planks** | nothing built |
| `hut` | **8 planks** | `lean-to` built |
| `cabin` | **14 planks** | `hut` built **and** `insights ≥ 5` |

All three are priced in planks. The shelter arc is the sawing arc: you saw wood to build a home, and
one material keeps the story tight.

**No tool and no skill.** F2's rule stands unchanged — a recipe gates on a tool, never a skill — and
planks already carry the handsaw's gate upstream. Adding a second gate here would be taxing the same
work twice.

**Only the next stage is ever listed.** The build list shows one shelter row at a time: the lean-to,
then the hut, then the cabin. A stage whose predecessor does not exist is **absent**, not greyed —
the same rule the skill list has followed since F1. A player at `hut` with three insights sees no
shelter row at all, and the feature answers that with silence rather than an explanation, exactly as
it does for an unmet skill. Naming what they are waiting for would be the app stating a plan.

## 5. Inventory — which F3 owns, and why

Two mechanics that are not "the shelter" but belong in this phase, because F3 inherits the dead end
they close.

**F2 left a real one.** Timber's only consumers are the handsaw (needs rope) and planks (need the
handsaw). A bag at capacity holding timber and no rope can build nothing, and because node stock
accrues while you are away, the trap gets *more* likely the longer someone is gone. Pricing the
shelters in planks does not touch it.

### Choose how much to take

`HarvestNode` takes `min($standing->available, $room)` — it **force-fills the bag**. One click on the
trunk after building the axe leaves you holding nothing but timber. That is the mechanism that
springs the trap, and a quantity choice defuses it at the source.

The existing one-click behaviour stays the default; taking less is the deliberate act.

### Drop

From the bag, a held stack can be dropped. It is destroyed — it does not return to the node's
standing stock, and it does not reappear anywhere.

Returning it to the world was considered and rejected: the fiction is tidy, but it makes the bag a
lossless scratchpad and quietly removes the weight from every decision to pick something up.

**Copy rules bind hard here.** The line for a drop describes what Blob did and never comments on the
choice. No confirmation that moralises, no "are you sure", no tally of what has been discarded.

## 6. The migration, and an arithmetic problem I hit writing this

An established account has a cabin the record granted. When `insights: 5` stops granting it,
that account must not simply lose it.

The brainstorm settled that **the cabin comes apart into its materials** — nothing taken, transformed,
and rebuilding it becomes the first thing you do.

**Writing the numbers out, that does not fit.** Reaching a cabin from nothing costs 4 + 8 + 14 =
**26 planks**. Returning fewer than 26 means the account cannot rebuild what it had, which is the
regression the conversion exists to avoid. Returning 26 into a bag whose capacity is 5 plus 5 per
container is impossible — F2's whole capacity arc tops out at 20.

**Resolution, and it needs your eye at spec review.** The materials do not go into the bag. They go
into **the clearing**, as a one-time `salvage` node standing where the cabin was, stocked with 26
planks, met and known from the start.

This reuses machinery that already exists rather than inventing any: a node, its standing stock, and
F3's own new partial-harvest. Nothing is destroyed, nothing overflows, and the player draws the cabin
down into their bag at whatever pace their capacity allows — which is the arc's "the world holds the
overflow" rule doing exactly the job it was written for.

The migration is therefore: for every companion whose record has `insights ≥ 5`, create a `salvage`
node stocked at 26. It must be idempotent, and it runs against MySQL in production while the suite
runs on SQLite.

**Three consequences this drags in, named rather than discovered during the build:**

1. **A node must be able to exist without a skill.** Every node today names one, and
   `CompanionContentTest::test_every_node_names_a_skill_and_a_material_that_exist` asserts it.
   Salvage is not a skill you learn — it is your own cabin in a heap. So `nodes.*.skill` becomes
   optional, exactly as `nodes.*.tool` already is, and the content test narrows to "if it names a
   skill, that skill exists."
2. **An emptied salvage pile is deleted, not left at zero.** A drained node otherwise renders forever
   as an empty label in the clearing. Removing a pile that is gone is not regression — nothing about
   Blob is diminished — but it is the one delete path in the feature and the spec should say so out
   loud rather than let it look like one.
3. **It is a fourth hotspot in an already-crowded clearing**, and §7 records that the labels are
   already too large below a ~300px stage. It is temporary and it only exists for established
   accounts, but it lands on the sorest spot in the layout.

**The simpler alternative, and why it was not chosen.** The migration could set the stage to `cabin`
directly: the account keeps its cabin as a built thing, nothing converts, no new node, no fourth
hotspot, no delete path. It is strictly less code. It was rejected because it hands an established
account the finished cabin *again* — which is the exact thing §2's first overturn exists to stop.
The salvage pile is more machinery in exchange for the one experience this phase is built to deliver:
you put it up yourself.

**This is the decision most worth challenging at spec review.** It is three wrinkles and a delete
path bought with one experience, and a reasonable person could price that the other way.

## 7. Art, deferred again

F3 ships the shelter as an object in the clearing **without new backdrops**:

- three small structure objects, one per stage, drawn over the existing forest
- the interior reuses `CompanionRoom`'s drawing, parameterised
- the *go inside* affordance is a real control, not a shape in the SVG — the same accessibility rule
  the node hotspots already follow

This is the third phase to defer art and the reasoning has not changed: BLOB.md §7 records backdrops
as the slowest, least predictable work in the feature, and every visual judgement stalls on someone
being able to look at it.

**A known cost, stated rather than discovered:** until the objects are drawn, the shelter is a label
in a clearing and reads as unfinished. F1 and F2 both accepted the same trade.

**One inherited constraint the art phase must solve, not this one.** Node hotspots use a fixed 8px
font that does not scale with the SVG, and below roughly a 300px stage the labels are already larger
than the room. A fourth clickable object makes that worse. It is a label-sizing problem and it is
not F3's to answer, but F3 is what makes it urgent.

## 8. Testing

PHP:

- Each stage is buildable only when its predecessor exists; the cabin additionally requires
  `insights ≥ 5`.
- A stage whose predecessor is absent is **absent from the payload**, not flagged.
- Building a stage advances the stored value and consumes exactly its cost.
- The stage never regresses, and no code path lowers it.
- Partial harvest takes the amount asked for; asking for more than is standing takes what is there;
  asking for more than fits takes what fits.
- A drop destroys exactly what was dropped and nothing else, and never touches node stock.
- **Nothing drops, decays or discards without the player asking** — the guard on §2's second overturn.
- The migration is idempotent and creates `salvage` only for records past the floor.
- A record below the floor is untouched by the migration.
- A node with no `skill` key is harvestable without one; every node that *does* name a skill still
  names one that exists.
- A salvage pile drained to zero is removed, and removing it changes nothing else about Blob.

JS (Vitest):

- The shelter object renders in the clearing at each stage, and only one at a time.
- *Go inside* opens the interior; the interior reflects the stage.
- Inside/outside does not survive a reload.
- The existing `never shows what has not happened` guard passes — it runs twice, bag closed and bag
  open, and both must stay green.

Traps this repo has paid for, all live here:

- **`CompanionVocabularyTest` scans companion source files including comments.** Any new file must
  join `sourceFiles()`; a file absent from that list is scanned by nothing.
- **Anchor asserted rows by item name, never by index.** Six assertions changed meaning silently
  during F2 by addressing rows positionally while content grew around them.
- **Tests are SQLite, production is MySQL.** The migration is the first thing in this feature where
  that difference can bite for real.
- **jsdom has no layout engine.** It can prove what a component emits; it cannot prove two things do
  not overlap. F2 shipped a hotspot collision that way.

## 9. Batches

| Batch | What it delivers | Verifiable remotely |
| --- | --- | --- |
| **1** | Inventory: partial harvest and drop. Closes F2's dead end. | Yes — server plus modest UI |
| **2** | The shelter: stages, storage, build rules, the interior parameterised, the migration. | Yes |
| **3** | The clearing: the structure object, the *go inside* control, the interior view wired up. | Mostly — the object's placement needs eyes |

Batch 1 first on purpose: it is the smallest, it closes an open problem rather than adding one, and
it is entirely verifiable without anyone looking at a screen.

## 10. Out of scope

Named so they are not quietly absorbed: new backdrops for any stage; the chest and F4's depth
mechanics; wearables migrating into the taxonomy; anything Blob *says* about the shelter through
`CompanionRemarks`; and the node-label sizing problem, which F3 makes urgent and does not solve.

## 11. What would make this wrong

- If anything ever drops, decays or discards without the player asking, §2's second overturn has
  been misread and the founding rule really has broken.
- If the shelter stage ever decreases, "nothing regresses" has broken in the one place F3 gave it a
  number to decrease.
- If two stages are ever drawn at once, the upgrade-in-place model has been abandoned.
- If the build list ever shows a stage whose predecessor is missing, the feature is a checklist again.
- If an established account loses its cabin without the salvage arriving, the migration has failed
  in the exact way §6 exists to prevent.
- If the cabin's threshold ever moves from `insights: 5`, E1's rule has broken — the floor may stop
  granting, but it may never relocate.
