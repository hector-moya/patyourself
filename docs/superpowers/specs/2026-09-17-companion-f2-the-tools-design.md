# F2 — The tools

**Status:** designed, not yet planned. Phase F2 of
`2026-09-17-companion-progression-arc-design.md`, which carries the reasoning behind every decision
referenced here. F1's rails are described in `2026-09-17-companion-f1-the-bag-design.md` and what
actually shipped is in `docs/superpowers/plans/2026-09-17-companion-f1-the-bag.md`. Read the arc
first; this document argues from it and does not restate its justifications.

The `tool` category in earnest. When F2 ships, Blob has an axe it built, a trunk it can only reach
with that axe, a handsaw that turns timber into planks, and a chain five links long that crosses
between the two economic layers at every step.

## 1. The claim this phase was asked to test

Arc §6 calls F2 "pure content on F1's rails; no new architecture." **That is false**, and the finding
is worth more than the content.

F1's rails carry the *node* half of F2 unchanged. They do not carry the *recipe* half. Seven gaps,
all on the `BuildItem` / `CompanionBag::recipes()` path:

1. **A recipe using a built ingredient is invisible forever.** `CompanionBag::recipes()` computes
   knowability from met node *yields* only. Rope is built rather than gathered, so a recipe naming
   rope fails the `array_diff` and is never listed. This is an F1 defect that F2 surfaces on day one,
   not a limitation F2 introduces.
2. **`BuildItem` has no gate, and that is asserted.** `BuildItemTest::test_building_needs_no_skill`
   is green today. Sawing with a handsaw contradicts it unless the gate is per-recipe.
3. **A recipe makes exactly one.** `increment('quantity')` with no argument, hardcoded.
4. **`BuildItem` has no capacity check.** F1 never hit it because containers are not carried;
   three planks are.
5. **Node gating is skill-only.** `HarvestNode` checks `skills()` and nothing else.
6. **`CompanionContentTest::test_each_material_builds_exactly_one_container` breaks** the moment
   deadfall builds both the barrow and the axe.
7. **Insight stocking is not content at all.** There is no insight event in this application;
   stocking from insights means new domain events. See §7.

So the honest framing, and the one the plan should be built on: **F2 is content on F1's node rails
and a real, small build-out of F1's recipe rails.** Three optional config keys, three guards, one
visibility rule, one restated test.

## 2. The rule

One line, and it is the whole of F2's architecture:

```
a node   gates on  skill + tool
a recipe gates on  tool, never skill
```

A skill is the record's permission to **touch the world**. Hands have never needed permission —
they need the right thing in them. That is why `BuildItemTest::test_building_needs_no_skill` stays
green and unedited: the basket still needs no skill, and neither does rope.

The rule is also what keeps the two layers from collapsing. Arc §2 pays for the two-layer economy on
the grounds that **the record controls what Blob can do and the world controls how fast**. A node
needing both a bought skill and a built tool is the only place in the feature where those two layers
meet on a single gesture. That is what makes the tool a mechanic rather than a badge.

## 3. Content

Everything here is config. Nothing in this table requires a code change beyond §4's keys.

| Thing | Category | Detail |
| --- | --- | --- |
| `trunk` | node | The fallen trunk. Visible from the start. Yields `timber`. Needs `chop-wood` **and** the axe. |
| `chop-wood` | skill | **20 xp.** F2's only new skill. |
| `rope` | consumable | Built: **3 fibre**. No tool. |
| `axe` | tool | Built: **2 deadfall + 1 rope**. Gates the trunk. |
| `timber` | material | From the trunk. |
| `handsaw` | tool | Built: **2 timber + 1 rope**. Gates the plank recipe. |
| `planks` | material | Built: **1 timber, makes 3**. Needs the handsaw. |
| `crate` | container | Built: **4 planks**. Capacity +5. |

The chain is five links long and crosses between the layers at every step:

```
fibre ──▶ rope ──▶ axe ──▶ timber ──▶ handsaw ──▶ planks ──▶ crate
  │                 │        │                       │
gathered          built    gathered                built
(F1 node)      (recipe)   (F2 node, needs        (recipe,
                           chop-wood + axe)       needs handsaw)
```

### Why exactly one new skill

Skills map one-to-one onto nodes, and `CompanionContentTest::test_skills_and_nodes_point_at_each_other`
enforces it. Sawing is a recipe, so it earns no skill. F2 therefore adds 20 xp of new sink where F1
added 40.

That is correct rather than thin. F2's substance is the tool gate and the chain; pacing has never
come from XP breadth, it comes from the world, and the world now accrues across three nodes instead
of two.

### Why `timber` and not `logs`

`logs` already means something in this codebase — `'trigger' => 'logs'` in the ladder, `logCount`,
`logMoments()`, `ActionLogged`. A material called `logs` would sit in `config('companion.bag.logs')`
two hundred lines below `'trigger' => 'logs'` and mean something else entirely, and every future grep
for either would find both.

### Why the crate

Planks have no consumer inside F2 — F3's shelter is what eats them. Ending the phase on an inert
material would make the tool gate feel ceremonial: you would build a handsaw in order to make a pile.

The crate keeps every material productive and keeps F1's capacity arc going as the materials get
bulkier. The alternative considered and rejected was ending on the pile, on the grounds that
BLOB.md's "inference is welcome — a pile of logs beside a half-built frame says plenty" makes an
inert stack legitimate. It is legitimate; it is just a weaker end to a phase.

**Assumption on the record:** the crate is in. If F3 would rather inherit a pile than a container,
drop `crate` from `config('companion.bag')` and the only other change is §6's dead-end test.

### The fiction's one unpaid debt

A handsaw has a metal blade and this clearing has no forge. Recorded rather than solved, because the
alternatives were worse: adding a stone material and a fourth node is a phase of its own, and
hewing planks with the axe drops the second tool and with it the only thing that exercises the
recipe gate.

## 4. The rail changes

Three optional config keys, three guards, one visibility rule. Each is small; together they are the
architecture the arc said F2 would not need.

### 4.1 `nodes.*.tool` — the node gate

An optional key. A node without it behaves exactly as F1's two do.

```php
'trunk' => [
    'skill' => 'chop-wood',
    'tool' => 'axe',
    'yields' => 'timber',
    ...
],
```

`HarvestNode` gains one condition after its existing skill check, and a fourth refusal joins
`CompanionEconomyException`:

```php
public static function toolNotHeld(string $node, string $tool): self
```

Same shape and same register as the other three: something was asked for that the current state
cannot pay for, nothing is destroyed, and it is not a failure on the user's part.

**Order matters.** The skill check runs first. A node you have not bought the skill for is a node
whose tool is not yet your problem, and reversing the order would refuse for a reason that is one
step further away than the real one.

### 4.2 `bag.*.tool` — the recipe gate

An optional key on a recipe. A recipe without it needs nothing, which is every recipe F1 ships.

```php
'planks' => [
    'category' => 'material',
    'label' => 'planks',
    'tool' => 'handsaw',
    'recipe' => ['timber' => 1],
    'makes' => 3,
],
```

`BuildItem` gains one condition, raising the same `toolNotHeld`. It is checked **before** the
materials, so a build that is short of both names the tool rather than the timber: the tool is the
harder of the two to come by, and naming the nearer obstacle first would send the reader back for
timber they already cannot use.

### 4.3 `bag.*.makes` — more than one

Optional, default 1. `BuildItem` today calls `increment('quantity')` with no argument. It becomes
`increment('quantity', $makes)`.

**`makes`, not `yields`.** `nodes.*.yields` already exists and holds the *name of a material*.
A `bag.*.yields` holding an *integer count* would be the same word meaning two different types in
two adjacent config blocks, which is the kind of thing that reads fine on the day it is written and
wrong every time afterwards.

### 4.4 The capacity check on building

`BuildItem` has none. F1 never needed one because a container is not carried, so every F1 build was
net-negative on `held`. Sawing is net **+2**.

The check, stated exactly, and counting **only carried categories on both sides**:

```
held − consumedCarried + madeCarried  ≤  capacity
```

- The axe consumes 3 carried (2 deadfall, 1 rope) and makes a belt item: net −3, can never overflow.
- The crate consumes 4 carried and makes a container: net −4, likewise.
- Planks consume 1 carried and make 3: net **+2**, and this is the case that can refuse.

A refusal is whole: nothing is consumed. **Nothing is destroyed and nothing is partially built** — a
half-consumed recipe would be the first thing in this feature that ever took something away.

The refusal is a **second factory** on `CompanionEconomyException`, `noRoomFor(string $thing)`,
rather than a reworded `bagIsFull(string $node)`. The two say different sentences — one is about
what stays standing at a node, the other about a thing that cannot be put down — and rewording the
existing one would edit a message F1 already ships and asserts on.

### 4.5 Knowability becomes transitive, and tool-revealed

`CompanionBag::recipes()` currently asks: is every ingredient the yield of a node Blob has met? Two
changes.

**Transitive.** An ingredient is knowable if it is a met node's yield **or** if it is itself a
knowable recipe. Rope is knowable because fibre is; the axe is knowable because deadfall and rope
both are. Without this, no recipe naming rope can ever be listed — which is finding 1 in §1, and it
is a bug in shipped code rather than a new requirement.

The resolution must be **cycle-safe**: `config` is authored data, and an authored `a → b → a` pair
would otherwise recurse forever on a page load. Resolve by fixed point over the catalogue rather than
by recursion, and stop when a pass adds nothing.

**Tool-revealed.** A recipe whose item is named as the `tool` of some node is listed only once that
node has been **met**. The axe is knowable from deadfall and rope the moment both are met — but a
tool for a thing you have never seen is a tool with no reason attached, and this is the one place in
the feature where a reverse lookup buys a real beat:

```
click the trunk, first time            (reeds and deadfall already met)
        │
        ▼
"{name} pushes at the trunk and leaves it where it is."
        │
        ▼
the bag opens once, on BOTH halves of what the trunk needs:

        BUILD
        rope                    3 fibre
        axe          2 deadfall, 1 rope
        handsaw     2 timber, 1 rope     ← knowable, not yet buildable

        {name} COULD LEARN
        chop wood                 20 xp
```

This reuses `CompanionController::REVEALED_KEY` exactly as F1 built it. Nothing new is stored: the
reveal is a reverse lookup over `config('companion.nodes')` for any node naming this item as its
tool, intersected with what Blob has met.

The handsaw is **not** reverse-revealed, because no node names it — it gates a recipe rather than a
node. It is knowable from its ingredients alone, and since one of them is timber, it becomes knowable
at exactly the same moment the axe does: the first click on the trunk.

**That is accepted rather than worked around.** So the first meeting reveals three recipes — rope,
axe, handsaw — of which only rope and, after gathering, the axe can be built. A handsaw listed at
`2 timber, 1 rope` with a disabled button is a price you cannot pay yet, which is precisely the menu
the rewritten rule in arc §4 allows; the alternative is a staged reveal keyed on materials Blob has
*held* at some point, and "has ever held" is state that would have to be stored, would survive
spending the material, and would be the first thing in this feature stored purely to pace a
list.

**Every reveal above is conditional on the other nodes having been met**, because knowability is
about ingredients and every one of these chains bottoms out in fibre or deadfall. Clicking the trunk
first, on a clearing where nothing else has been touched, reveals `chop-wood` and no recipes at all —
the axe needs deadfall, and rope needs fibre. Nothing special-cases that and nothing should: the
lists are what Blob has met, and it has met one thing.

### 4.6 The tool is part of the price

The recipe row renders its tool exactly as it renders an ingredient:

```
planks                1 timber, handsaw
```

A recipe has always been a price rather than a requirement, and a tool is part of what the thing
costs. This is not the app naming something Blob does not have: `4 fibre` names something Blob may
not have, and has since F1.

Consequently there is **no "requires", no explanatory line and no locked row**. `buildable` stays
false and the button stays disabled; the row stays readable. The payload gains exactly one key,
`recipes[].tool`, which is `null` for every F1 recipe.

**The `nodes` payload shape is unchanged.** The scene's hotspot shows a label and a count and
nothing else; the tool refusal arrives as a flash line from the controller, the same route every
other thing a node says already takes.

### 4.7 `CompanionContentTest::test_each_material_builds_exactly_one_container`, restated

It asserts that each material builds exactly one container. That was true of F1 and was never a law —
it was F1 §1's claim that **the fork is real on day one**, expressed as a test. F2 breaks it
truthfully: timber builds a tool and a material, and builds no container at all.

It splits in two:

- **The fork, kept and narrowed.** Fibre builds exactly one container and deadfall builds exactly
  one container, named explicitly. That is the F1 claim, and it should go on being guarded.
- **No dead ends.** Every material is an ingredient of at least one recipe, or is the yield of a node
  that something downstream consumes. A material nothing consumes is content that leads nowhere, and
  that is the property actually worth holding across every later phase.

## 5. What it looks like

```
click the trunk, nothing learned
  → "{name} pushes at the trunk and leaves it where it is."
  → the bag opens once, on both halves of what the trunk needs

buy chop-wood · click again · no axe
  → "{name} looks at the trunk. Nothing it is carrying will bite into it."
  → nothing opens; nothing was revealed

build the axe · click again
  → "{name} drags back 3 timber."
```

`nodes.*` gains a fifth copy key, `blunt`, beside `met` / `took` / `empty` / `full`. It follows the
same rules as its neighbours: sentence case, no exclamation mark, never congratulating, `{name}`
rather than the literal. It describes what Blob is carrying, never what the user should go and get —
the difference between inference, which BLOB.md welcomes, and the app stating a plan, which it
forbids.

**The bag opens on the first meeting and at no other time.** A `blunt` refusal reveals nothing new,
so it interrupts nothing. Opening the bag every time Blob is turned back would turn the one honest
interruption in the feature into a nag.

### The clearing

`trunk` joins `SCENES.forest.nodes` in `scenes.ts` as a third labelled hotspot, matching the reeds
and the deadfall. F1 shipped hotspots rather than sprites deliberately and F2 keeps doing so — see
§7.

Its coordinate is **measured against the backdrop and looked at**, not computed. BLOB.md trap 4 is
that class-name assertions and geometry cannot judge a visual: the shoes shipped into the bottom-left
corner with 340 tests green. The two existing nodes sit at `[-46, 40]` and `[44, 36]`, and Blob
stands at the centre, so the trunk wants the lower foreground without occluding the creature.

## 6. The three refusals, and their guards

F2 refuses three things the sketch raised. A ruling nobody can falsify erodes, so each one gets a
test that fails if it is ever quietly reversed.

### Tools take no room

`config('companion.capacity.carried')` stays `['material', 'consumable']`. A tool is on the belt.

This was a working call in F1 rather than a ruled decision; it is ruled now, and the argument is
stronger than convenience. **A tool is permanent.** A tool that occupied a slot would be a permanent
tax on what Blob can carry — gaining the axe would shrink the bag forever. That is regression in
everything but name, and arc §4 holds the line that nothing about Blob ever regresses. It would also
make the first tool in the feature read as a punishment for having progressed.

*Guard:* hold the axe and the handsaw; `held()` is unchanged and `capacity.carried` still excludes
`tool`.

### A tool does not wear out

Arc §4: nothing decays, expires, or is removed for inactivity, ever. A tool that breaks is removal
with extra steps, so durability is a **rule violation rather than a mechanic** — and repair-with-rope
reintroduces upkeep, which arc §3 rules out on the same loss-aversion grounds as streaks and daily
energy bars.

Progression past a tool, if a later phase wants it, is a **second, better tool that opens something
new**. Addition, never repair.

*Guard:* log outcomes across several days; no item quantity ever falls except through a recipe that
consumed it.

### Insight events do not stock the world

F1 §4 deferred this to F2. F2 **rules it out** rather than deferring it a third time.

Two reasons, and the first is the one that matters: there is no insight event in this application.
The four insight kinds are *derived* from database state by `CompanionResolver::insightMoments()`,
with no seam to listen to. Stocking from them means new domain events dispatched from
`WriteReflection`, `UpdateIntention`, `StartExperiment` and `ConcludeExperiment` — new architecture
for a pacing adjustment, and a second opinion about what happened alongside a resolver that already
derives it.

Second, the pacing does not need it. The world now accrues across three nodes, so one outcome logged
yields three units. Insights are already paid generously and outside the taper, in the layer built
for them.

*Guard:* write a reflection and conclude an experiment; `companion_nodes.available` is unchanged.
This is the guard most worth having, because insight stocking is the one of the three that a later
phase might add by accident while implementing something else.

## 7. Art, deferred again

F2 ships the trunk as a labelled hotspot and its tools and materials as text rows.

The decisive argument is consistency rather than cost: **the bag either has icons for everything or
for nothing.** An axe and a coil of rope with icons beside a bare `fibre` row reads as unfinished,
and icons for all seven items is a scope of its own with a row-layout change attached.

The cost is real and worth stating: F2 adds nothing new to look at beyond one more label in the
clearing. That is the price of arc §6 reserving the feature's art risk for F3, and BLOB.md §7 is the
evidence — backdrops have to agree with foreground objects on one 144×114 grid, and that is recorded
as the slowest and least predictable part of this feature.

When the art is picked up, BLOB.md §7 already names the pipeline: `create_1_direction_object` is the
right tool for things that exist in the world — *"the axe, the handsaw, a coil of rope"* — because
they need no anchor, no worn-item lift, and raise no occlusion problem. Nothing in F2 makes that
harder later.

## 8. Testing

PHP (PHPUnit, feature tests preferred):

- The trunk refuses without `chop-wood` — the existing `skillNotLearned`, unchanged.
- The trunk refuses with the skill and no axe, raising `toolNotHeld`, and consumes nothing.
- The trunk yields with the skill and the axe.
- The skill is checked before the tool, so a companion with neither is told about the skill.
- Rope builds from fibre with no tool; the axe builds from deadfall and rope.
- Sawing with timber and no handsaw refuses and consumes nothing.
- One timber makes three planks.
- Sawing refuses when what it makes would overflow capacity, and **consumes nothing** — asserted on
  the timber still being held, not only on the planks being absent.
- The axe's recipe is absent before the trunk is met and present after.
- Rope's recipe is listed once fibre is met, which is the transitive case.
- An authored recipe cycle terminates rather than recursing.
- The three guards in §6.
- Content: every `tool` named by a node or a recipe exists in `bag` with category `tool`; every tool
  is buildable; no material is a dead end; the F1 fork still holds for fibre and deadfall.

JS (Vitest):

- The trunk renders as a third hotspot and is clickable.
- A recipe row renders its tool as part of the price, and `toHaveClass` rather than `toContain` for
  any class assertion.
- The existing `never shows what has not happened` guard passes with the new rows. **It runs twice,
  bag closed and bag open**, and both must stay green.
- `companion.fixture.ts` gains the new bag defaults so the other screens keep rendering.

Traps this repo has already paid for, all live here:

- **`CompanionVocabularyTest` scans source files including comments**, over `streak`,
  `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`,
  `hungry`, `misses you`, `neglect`, `cooldown`. `points` is a substring trap — *appoints* and
  *disappoints* trip it. **Any new companion source file must be added to `sourceFiles()`**; a file
  absent from that list is scanned by nothing.
- **`CompanionBagTest` asserts the payload's shape**, matching every key against
  `/total|count|percent|progress|next|remaining|locked|streak/i`. `tool` and `makes` pass; a key
  named for a quantity would not.
- **`CompanionLadderTest` forbids the literal "Blob"** in any authored message. The new `blunt` line
  and the trunk's four other lines carry `{name}`.
- **A `failed` outcome pays exactly what a `completed` one pays.** F2 touches nothing that reads an
  outcome, and must go on touching nothing.
- **Tests are SQLite and production is MySQL.** Bind values in raw SQL. `assertDatabaseMissing` on a
  column that does not exist passes forever on SQLite.
- **Column-limited eager loads hide new columns.** `CompanionBag` eager-loads `['items','nodes','skills']`
  whole; keep it that way.

## 9. Batches

Three, each shippable on its own and each leaving the application correct.

| Batch | What it delivers | Risk |
| --- | --- | --- |
| **1** | The node gate: `nodes.*.tool`, `toolNotHeld`, transitive and tool-revealed knowability, and the content that exercises it — rope, axe, chop-wood, the trunk, timber. Plus §6's three guards. | None on screen. |
| **2** | The recipe gate: `bag.*.tool`, `bag.*.makes`, the build capacity check, and the handsaw, planks and crate. | None on screen. |
| **3** | The clearing: the third hotspot, the tool in the recipe row, the refusal on screen, the fixture. | First UI. |

**Stop for review after every batch.**

The content is deliberately not a batch of its own. A node carrying a `tool` key that `HarvestNode`
does not yet read would be harvestable without the axe — a wrong behaviour sitting on `main` between
two reviews — so each batch carries the content its own guards need and no more.

## 10. Out of scope for F2

Named so they are not quietly absorbed: the lean-to, the hut and the shelter arc; the chest;
wearables migrating into the taxonomy; sprites for any node, tool or material; anything Blob *says*
about the tools through `CompanionRemarks`; and a second skill, which F2 has no node for.

## 11. A separate commit this phase should carry

`docs/BLOB.md` is the stated current-state reference and declares *"where this file and a spec
disagree, this file is right."* It predates F1 and is wrong in at least three places:

- §3 says **"There is no companion table"** and that nothing about Blob is stored. F1 shipped four.
- §11 calls the remaining work **"E2 (tools and materials) and E3 (shelter)"**, which is the old
  phase naming.
- §8's file map has none of F1's services, actions, models, controllers or `companion-bag.tsx`.

A reference that claims precedence over the specs and is stale is worse than no reference. Bringing
it current is **its own commit**, not folded into a batch, so the documentation fix is reviewable
apart from the behaviour.

## 12. What would make this wrong

Recorded so a later reader can check rather than re-derive:

- If a recipe ever gates on a skill, §2's rule has been broken and the two layers have collapsed into
  one.
- If a tool ever appears in `capacity.carried`, §6 has been broken and progressing has started
  costing the player room.
- If anything ever removes an item outside a recipe that consumed it, arc §4 has been broken.
- If a build ever consumes part of a recipe it cannot complete, the "nothing is destroyed" rule has
  been broken in the one place this feature writes to two tables at once.
- If a node's `blunt` line ever names the tool the user should go and build, the app has stated a
  plan rather than left an inference.
- If the skill list ever shows a skill whose node has not been met, arc §4's rewritten rule has been
  broken and the bag is a checklist again.
