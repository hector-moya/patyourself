# F3 — The shelter: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blob builds a shelter in the clearing out of planks it sawed, it upgrades lean-to → hut → cabin in place, you can go inside it, and an established account whose cabin the record used to grant gets its 26 planks back as a heap it can draw down.

**Architecture:** Three additions on F1/F2's rails. (1) Inventory gains two player-initiated choices — take less than a full bag, and drop a stack outright — which closes the timber soft-lock `docs/BLOB.md` §12 records. (2) The shelter is a single stored scalar on `companions` that only ever advances, built by its own action against its own config block, with only the one choosable stage ever listed. (3) A node may now exist without a skill, which is what the one-time salvage heap is, and that one property drives three behaviours coherently.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit 12 (SQLite in tests, MySQL in production), Inertia v3 + React 19, Vitest + Testing Library, Radix dialog primitives, Wayfinder for typed routes, Pint for formatting.

**Spec:** `docs/superpowers/specs/2026-09-18-companion-f3-the-shelter-design.md`
Read with it: `docs/superpowers/specs/2026-09-17-companion-progression-arc-design.md` (§2–§4 are the invariants), `docs/superpowers/specs/2026-09-17-companion-f1-the-bag-design.md` (the rails), `docs/superpowers/specs/2026-09-17-companion-f2-the-tools-design.md` (what shipped), `docs/BLOB.md` (current state; it claims precedence over the specs).
Prior ledger: `.superpowers/sdd/2026-09-17-companion-f2-the-tools/progress.md` — every ruling from F2 with its reasoning.

---

## Global Constraints

Every task's requirements implicitly include this section. Values are copied verbatim from the specs and from shipped config.

**Invariants that must survive every task**

- **A failed outcome pays exactly what a completed one pays.** Nothing in this feature branches on an outcome. F3 touches nothing that reads one and must go on touching nothing.
- **Never show a total, a completion share, or an end state. Show what is choosable now.** The guard is `/locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i`, and it runs **twice** in `resources/js/pages/companion.test.tsx` — bag closed and bag open. Both must stay green.
- **Never show a locked row.** A thing you cannot account for is absent, not greyed. This binds the shelter offer as hard as it binds the skill list.
- **Nothing regresses**, with the two overturns F3 records explicitly (spec §2). **Destruction is always player-initiated and never automatic** — nothing may drop, decay, expire or discard on the player's behalf, for any reason, including a full bag.
- **No authored message contains the literal "Blob".** Every one carries `{name}`, substituted server-side.
- **The cabin's floor is `insights: 5` and never moves.** The number may stop granting; it may never relocate.
- **The shelter stage never decreases**, and no code path lowers it.
- **Two stages are never drawn at once.** The shelter upgrades in place.

**Traps this repo has already paid for — all of them live in F3**

- `CompanionVocabularyTest::sourceFiles()` scans companion source files **including comments** for: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` is a substring trap — *appoints* and *disappoints* trip it. **Any new file must join `sourceFiles()` in the same task that creates it.** A file absent from that list is scanned by nothing.
- `CompanionBagTest::test_the_payload_names_no_total_and_no_next` sweeps every payload key against `/total|count|percent|progress|next|remaining|locked|streak/i`. **`next` is banned**, which is why the shelter's choosable stage is called `offer`.
- **Anchor asserted rows by item name, never by index.** Six assertions changed meaning silently during F2 by addressing rows positionally while content grew around them. Use `collect($rows)->firstWhere('item', 'x')`.
- **`config()->set` replaces an existing key in place and appends a new one.** A test whose meaning depends on catalogue *order* must pin the whole array, not set two keys into the live one. (F2 finding F4.)
- **Tests are SQLite, production is MySQL.** Bind values in raw SQL, never embed quoted literals. `assertDatabaseMissing` on a column that does not exist is a constant-false predicate that passes forever on SQLite. F3's migration is the first place this can bite for real.
- **jsdom has no layout engine.** It proves what a component emits; it cannot prove two things do not overlap. F2 shipped a hotspot collision exactly that way. Any placement claim needs a render someone looks at.
- **`className`: use `cn()`, never a template literal** — a template literal loses its leading space under prettier's tailwind plugin and ships `class="c-entryis-new"`. Assert with `toHaveClass`, never `toContain`.
- **Never `npm run lint` from the repo root** — it descends into `.claude/worktrees/*` and rewrites files. Scope it: `npx eslint resources/js/<path>`.
- **Never `prettier --write` on `resources/css/*.css`.** Both CSS files are hand-formatted single-line rules and already fail `format:check` on main. Edit by hand, matching the surrounding style exactly.
- **Run `vendor/bin/pint --dirty --format agent` before any commit touching PHP.**
- **Column-limited eager loads hide new columns.** `CompanionBag` eager-loads `['items','nodes','skills']` whole; keep it that way.
- **A guard over a state the system cannot reach costs you the state it can** (`docs/BLOB.md` §12, the `blob` form's sleep row). Assert what is reachable.

**Environment**

- Worktree `.claude/worktrees/companion-f2-tools`, branch `worktree-companion-f2-tools`. **Already fully bootstrapped** — `.env`, app key, Passport keys, `node_modules`, generated Wayfinder routes, built assets. Do not re-bootstrap.
- Baseline at plan time: PHP `php artisan test --compact` → **1299/1299**; Vitest → **665/665 across 49 files**. Every task measures against the previous task's number.
- Adding a route means re-running `php artisan wayfinder:generate --with-form` before the client can import it.
- Herd serves the main checkout, never a worktree. To look at something: dump to HTML and `php -S 127.0.0.1:8899 -t .`.

**Batching.** Three batches, per spec §9. **Stop after each batch for the human partner's review.** They merge to `main` themselves, fast-forward, no PR.

---

## Findings from probing the spec before writing tasks

The human partner asked for "no new architecture" to be treated as a claim to test. F3 does not make that claim — §2, §3, §5 and §6 each admit a new mechanism — so the probe instead asked what F3 adds that the spec does **not** enumerate. Seven things, each encoded in a task below rather than discovered mid-build.

**Finding 1 — `CompanionBag` must learn the insight count, and that is a new dependency.**
The cabin's `insights ≥ 5` floor gates what the bag offers. F2's C1 ruling is that a read which predicts a build and the action that performs it must call the same code, or they drift. So `CompanionBag` needs `CompanionResolver`. Encoded in Task 10, computed **lazily** — only when the offer would be the cabin — so no account pays four extra queries for a floor that does not apply to it.

**Finding 2 — `config('companion.scenes')` must lose its `cabin` entry, and four shipped tests assert otherwise.**
Spec §1 says the forest is always the world and is never replaced, but never says the config changes. It has to: `CompanionState::scene()` walks thresholds independently, so an established account derives `cabin` today and would be teleported indoors with no clearing and no salvage heap visible. `CompanionSceneTest` asserts the derived `'cabin'` in four places. Encoded in Task 14.

**Finding 2b — two of those tests stop discriminating if repaired naively.** `test_the_override_wins_over_the_scene_the_record_derives` sets the override to `'forest'` and expects `'forest'`; once the record *also* derives `'forest'`, a mutant that ignores the override entirely passes. Same for `test_an_absent_or_empty_override_leaves_the_record_deciding`. This is the same silent-drift class as F2's findings F4 and the `recipes[0]` index drift — green, and meaningless. Both must be flipped to override *to* `'cabin'`. Encoded in Task 14.

**Finding 3 — a skill-less node needs three coordinated behaviours; the spec names one.**
Spec §6 consequence 1 says only that `nodes.*.skill` becomes optional. Three things actually follow, and getting any one wrong is a visible bug:
- `StockCompanionNodes` reads `$node['skill']` unguarded — an undefined-index warning, and worse, a heap that restocks forever would rebuild the cabin out of thin air.
- `CompanionBag::nodes()` lists **every authored node whether met or not**, because all three F1/F2 nodes stand in the clearing from the start. Authoring `salvage` there would put a salvage hotspot in front of every account that never had a cabin.
- `HarvestNode` and `CompanionNodeController` both check the skill before anything else.
The resolution is one rule, stated as F3's own one-liner, that drives all three:
```
a node with a skill  is the world:  always standing, and the record stocks it
a node without one   is a heap:     there only if something put it there, and nothing restocks it
```
Encoded in Task 12.

**Finding 4 — "idempotent" needs a stored marker, because the heap is deleted when drained.**
Spec §6 requires the migration to be idempotent and consequence 2 requires an emptied heap to be **deleted**. Those two together mean "does a salvage row exist?" is not a safe idempotence check — after the player drains it, re-running would grant a second cabin's worth. A nullable `companions.salvaged_at` is the marker, and it satisfies *store only what cannot be derived*: once the heap is gone, nothing else records that the conversion happened. Encoded in Tasks 7 and 13.

**Finding 5 — the shelter is not a `bag` recipe, which contradicts arc §5's taxonomy table.**
Arc §5 files `structure` as a `bag` category "in the world, permanent". Putting the three stages in `config('companion.bag')` would mean `CompanionBag::recipes()` lists all three at once — knowability is ingredient-based and all three cost planks — which breaks spec §4's "only the next stage is ever listed", and `BuildItem` would write a `companion_items` row for a thing that is not carried and replaces its predecessor rather than stacking. So F3 gives the shelter its own config block and its own action. **This is a deliberate departure from the arc's taxonomy table and is recorded in `docs/BLOB.md` in Task 19.** The taxonomy's other five categories are untouched.

**Finding 6 — 26 planks does not let an established account rebuild its cabin unaided, and spec §6's arithmetic says it does.**
Spec §6 reasons: "Reaching a cabin from nothing costs 4 + 8 + 14 = 26 planks. Returning fewer than 26 means the account cannot rebuild what it had." That omits capacity. **The cabin consumes 14 planks in one act, so the bag must hold 14**, and the bag is 5 plus 5 per container. Without gathering, the only container buildable from planks alone is the `crate` (4 planks, +5), so reaching capacity 15 costs **two crates, 8 planks**. Total from a standing start with no gathering: 8 + 4 + 8 + 14 = **34**. With 26 the player reaches the hut and is 8 planks short of the cabin, and must re-enter the F1/F2 economy — buy a gather skill, build a basket or barrow, chop and saw — to finish.

Whether that is wrong depends on which sentence you weigh. Spec §2's first overturn says building it *is* the reward and a handed-over cabin was never earned, which makes "you have to play for the last stretch" correct. Spec §6's stated criterion — that the account can rebuild what it had — is not met by 26.

**The human partner has read §6 and ruled that the salvage pile stands as written, so this plan implements 26.** The finding is recorded here, surfaced in the batch-2 review, and written into `docs/BLOB.md` so a later reader does not re-derive it. If they want self-sufficiency instead, the change is one number in `config('companion.salvage.stock')` and one line in the content test that ties it to the shelter costs.

**Finding 7 — the shelter offer must respect knowability, which spec §4 does not say.**
§4 says only the next stage is ever listed. Taken literally, a brand-new account with nothing met sees `lean-to — 4 planks` before it has ever seen a plank, a handsaw or the trunk — the exact "price quoted in a currency nobody has been shown" that F2's Task 11b/11c fixed for recipes. The offer is therefore withheld unless every ingredient is in `CompanionBag::knowable()`, reusing the fixed point already in the file. Encoded in Task 10.

---

## File structure

**New files**

| Path | Responsibility |
| --- | --- |
| `app/Actions/DropItem.php` | Destroys one held stack, on the player's say-so. The only delete path for an item outside a recipe. |
| `app/Actions/BuildShelter.php` | Puts up one stage. Owns the predecessor rule and the insight floor. |
| `app/Actions/SalvageTheCabin.php` | The one-time conversion. Testable apart from the migration that calls it. |
| `app/Http/Controllers/CompanionItemController.php` | `DELETE companion/items/{item}` — dropping, from inside the bag. |
| `app/Http/Controllers/CompanionShelterController.php` | `POST companion/shelter` — putting up a stage, from inside the bag. |
| `database/migrations/2026_09_18_000001_add_the_shelter_to_companions_table.php` | `shelter` and `salvaged_at` columns. |
| `database/migrations/2026_09_18_000002_salvage_granted_cabins.php` | Calls `SalvageTheCabin` once, on deploy. |
| `tests/Feature/Companion/DropItemTest.php` | The drop action. |
| `tests/Feature/Companion/BuildShelterTest.php` | Stages, predecessor, floor, consumption, no regress. |
| `tests/Feature/Companion/SalvageTheCabinTest.php` | Who qualifies, idempotence, the drained case. |
| `tests/Feature/Companion/CompanionShelterScreenTest.php` | The two new routes end to end. |
| `tests/Feature/Companion/CompanionEconomyWalkTest.php` | The whole economy, played as a player, on shipped config. |
| `tests/Unit/Companion/CompanionShelterContentTest.php` | The shelter and salvage config, checked for the ways it can lie. |

**Modified files**

| Path | Change |
| --- | --- |
| `config/companion.php` | `drop` line; `shelter` block; `salvage` block; the `salvage` node; `scenes` loses `cabin`. |
| `app/Models/Companion.php` | `shelter` + `salvaged_at` fillable and cast; `shortfallFor()` and `spend()` extracted for two callers. |
| `app/Actions/HarvestNode.php` | Optional amount; skill optional; delete a drained heap. |
| `app/Actions/BuildItem.php` | Consumption moves to `Companion::spend()`. Behaviour unchanged. |
| `app/Listeners/StockCompanionNodes.php` | Skip a node that names no skill. |
| `app/Services/Companion/CompanionBag.php` | `droppable` on items; `skill` nullable; heaps listed only when placed; `shelter` payload; `CompanionResolver` injected. |
| `app/Services/Companion/CompanionEconomyException.php` | `notOffered()`. |
| `app/Http/Controllers/CompanionNodeController.php` | Optional `take`; skill-less nodes harvest straight away; stay open when posted from the bag. |
| `routes/web.php` | Two new routes. |
| `resources/js/patyourself/companion.tsx` | `droppable`, nullable `skill`, `BagShelterData`. |
| `resources/js/patyourself/companion-bag.tsx` | Drop buttons; the clearing group; the shelter row. |
| `resources/js/patyourself/companion-room.tsx` | `inside` and `shelter` props; the interior drawn per stage. |
| `resources/js/patyourself/scenes.ts` | The shelter's and the heap's coordinates. |
| `resources/js/pages/companion.tsx` | `inside` state, the go-inside controls, the shelter object. |
| `resources/js/patyourself/companion.fixture.ts` | New payload defaults. |
| `resources/css/patyourself.css` | Four hand-written rules. **Never prettier.** |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | Five new source files. |
| `tests/Unit/Companion/CompanionContentTest.php` | Skill and `met` become conditional. |
| `tests/Feature/Companion/CompanionSceneTest.php` | The derived cabin is gone; two override tests re-pointed so they still discriminate. |
| `tests/Feature/Companion/CompanionRulingsTest.php` | The guard on §2's second overturn. |
| `docs/BLOB.md` | Its own commit, last. |

---

# BATCH 1 — Inventory

Partial harvest and drop. Closes the timber soft-lock `docs/BLOB.md` §12 records as open. Entirely verifiable without anyone looking at a screen, which is why it goes first.

---

### Task 1: `HarvestNode` takes an amount

**Files:**
- Modify: `app/Actions/HarvestNode.php:34-94`
- Test: `tests/Feature/Companion/HarvestNodeTest.php` (append after `test_a_node_with_no_tool_needs_none`, which ends around line 275)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `HarvestNode::handle(User $user, string $node, ?int $wanted = null): int` — returns how many units moved. `null` means *fill the bag*, which is exactly what every existing caller gets today.

- [ ] **Step 1: Write the failing tests**

`HarvestNodeTest` already has a `clearing(int $available, string $skill = 'gather-fibre', string $node = 'reeds'): array` helper returning `[$user, $companion]`, a `held(Companion $companion, string $item): int` and a `standing(Companion $companion, string $node): int`. Use them. Append:

```php
    /**
     * The choice this adds, and the reason for it: `HarvestNode` force-fills
     * the bag, which is what springs the trap a bag full of one material is.
     * Asking for less is the deliberate act; asking for nothing in particular
     * is still what every click in the clearing does.
     */
    public function test_harvesting_takes_only_what_was_asked_for(): void
    {
        [$user, $companion] = $this->clearing(4);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(2, $this->held($companion->fresh()->load('items'), 'fibre'));
        $this->assertSame(2, $this->standing($companion->fresh(), 'reeds'));
    }

    /** Asking for more than is standing takes what is there, and does not fail. */
    public function test_asking_for_more_than_is_standing_takes_what_is_there(): void
    {
        [$user, $companion] = $this->clearing(2);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 5);

        $this->assertSame(2, $moved);
        $this->assertSame(0, $this->standing($companion->fresh(), 'reeds'));
    }

    /** And asking for more than fits takes what fits. The rest stays standing. */
    public function test_asking_for_more_than_fits_takes_what_fits(): void
    {
        [$user, $companion] = $this->clearing(10);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 8);

        $this->assertSame(5, $moved);
        $this->assertSame(5, $this->standing($companion->fresh(), 'reeds'));
    }

    /** Absent means fill, so nothing about the existing gesture changes. */
    public function test_asking_for_nothing_in_particular_still_fills_the_bag(): void
    {
        [$user, $companion] = $this->clearing(10);

        $this->assertSame(5, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertSame(5, $this->standing($companion->fresh(), 'reeds'));
    }
```

- [ ] **Step 2: Run them and watch the first three fail**

Run: `php artisan test --compact --filter='HarvestNodeTest'`
Expected: the three cases passing an amount fail with `ArgumentCountError` / too many arguments; `test_asking_for_nothing_in_particular_still_fills_the_bag` passes already, which is the point — it pins the behaviour the change must not alter.

- [ ] **Step 3: Add the parameter**

In `app/Actions/HarvestNode.php`, change the signature and the one line that decides how much moves:

```php
    /**
     * @param  ?int  $wanted  How much to take, or null to take what fits.
     *                        Absent is the clearing's own gesture: one click
     *                        fills the bag. Naming an amount is the deliberate
     *                        act, and it is what stops a node force-filling the
     *                        bag with one material and leaving nothing buildable.
     *
     * @return int How many units moved into the bag.
     *
     * @throws InvalidArgumentException when no such node is authored.
     * @throws CompanionEconomyException when the skill is unlearned, the tool
     *                                   is not held, or the bag is full while
     *                                   stock is standing.
     */
    public function handle(User $user, string $node, ?int $wanted = null): int
```

Thread `$wanted` through the `DB::transaction` closure's `use (...)` list, and replace the `$moved` line:

```php
            // Floored at one rather than clamped to zero: a caller asking for
            // nothing has asked a question this action has no answer for, and
            // taking nothing while reporting success would say "the reeds are
            // empty" about a node that is not. The route validates `min:1`, so
            // this is the second of the two.
            $moved = min($standing->available, $room);

            if ($wanted !== null) {
                $moved = min($moved, max(1, $wanted));
            }
```

- [ ] **Step 4: Run the whole companion suite**

Run: `php artisan test --compact --filter='Companion'`
Expected: all four new cases pass; nothing else moves. Then `php artisan test --compact` — expected **1303/1303** (1299 + 4).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/HarvestNode.php tests/Feature/Companion/HarvestNodeTest.php
git commit -m "feat(companion): let a harvest take less than a full bag"
```

---

### Task 2: `DropItem` — the one thing in this feature that destroys

**Files:**
- Create: `app/Actions/DropItem.php`
- Create: `tests/Feature/Companion/DropItemTest.php`
- Modify: `config/companion.php` (a new `drop` line, after the `capacity` block)
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` (register the new file)

**Interfaces:**
- Consumes: `Companion::items()`, `config('companion.capacity.carried')` = `['material', 'consumable']`.
- Produces: `DropItem::handle(User $user, string $item): int` — returns how many units were destroyed; `0` when the stack is not held. Throws `InvalidArgumentException` for an item config does not know, or one whose category is not carried.

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\DropItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The one place in this feature that destroys something.
 *
 * It exists because F2 left a real dead end: a bag at capacity holding timber
 * and no rope can build nothing, and the world keeps accruing while you are
 * away, so the trap gets MORE likely the longer someone is gone.
 *
 * This is not "nothing regresses" breaking. Every prohibition in that rule
 * names something the system does TO you — decay, expiry, upkeep, removal for
 * inactivity. A player choosing to tip out timber is the same category as
 * choosing to spend fibre on a basket: the player spends, and worlds diverge.
 * The line that must hold is that destruction is always player-initiated and
 * never automatic, and {@see CompanionRulingsTest} is where that is guarded.
 */
class DropItemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Companion} */
    private function carrying(array $items): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        foreach ($items as $item => $quantity) {
            $companion->items()->create(['item' => $item, 'quantity' => $quantity]);
        }

        return [$user, $companion];
    }

    private function held(\App\Models\Companion $companion, string $item): int
    {
        return (int) $companion->items()->where('item', $item)->value('quantity');
    }

    /** The whole stack, and only that stack. */
    public function test_dropping_destroys_the_whole_stack(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 3, 'fibre' => 2]);

        $dropped = app(DropItem::class)->handle($user, 'timber');

        $this->assertSame(3, $dropped);
        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
        $this->assertSame(2, $this->held($companion, 'fibre'));
    }

    /**
     * It is destroyed, not put back. Returning it to the node was considered
     * and rejected: it would make the bag a lossless scratchpad and quietly
     * remove the weight from every decision to pick something up.
     */
    public function test_dropping_never_touches_node_stock(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);

        app(DropItem::class)->handle($user, 'fibre');

        $this->assertSame(6, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** And it frees exactly that much room. */
    public function test_dropping_frees_the_room_it_took(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 5]);

        $this->assertSame(0, $companion->fresh()->load('items')->room());

        app(DropItem::class)->handle($user, 'timber');

        $this->assertSame(5, $companion->fresh()->load('items')->room());
    }

    /** Dropping what is not held is not a failure. There was nothing there. */
    public function test_dropping_something_not_held_takes_nothing(): void
    {
        [$user] = $this->carrying(['fibre' => 1]);

        $this->assertSame(0, app(DropItem::class)->handle($user, 'timber'));
    }

    /**
     * A tool is on the belt and takes no room, so tipping one out buys nothing
     * and costs something permanent. The overturn in spec §2 was argued on the
     * grounds that the player SPENDS; a destruction that gains nothing is not
     * spending, and letting it through would put the only permanent thing in
     * the feature one misclick from gone.
     */
    public function test_a_tool_is_not_something_to_tip_out(): void
    {
        [$user] = $this->carrying(['axe' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'axe');
    }

    /** Nor a container: dropping one would lower capacity, which is regression. */
    public function test_a_container_is_not_something_to_tip_out(): void
    {
        [$user] = $this->carrying(['basket' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'basket');
    }

    public function test_an_item_nobody_authored_is_rejected(): void
    {
        [$user] = $this->carrying([]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'anvil');
    }

    /** One person's bag is never another's. */
    public function test_another_users_bag_is_untouched(): void
    {
        [$user] = $this->carrying(['fibre' => 2]);
        [, $strangersCompanion] = $this->carrying(['fibre' => 4]);

        app(DropItem::class)->handle($user, 'fibre');

        $this->assertSame(4, $this->held($strangersCompanion, 'fibre'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/DropItemTest.php`
Expected: every case errors with `Class "App\Actions\DropItem" does not exist`.

- [ ] **Step 3: Write the action**

`php artisan make:class Actions/DropItem --no-interaction`, then:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob tips a stack out of the bag, because the player said so.
 *
 * THE ONLY PATH IN THIS FEATURE THAT DESTROYS ANYTHING OUTSIDE A RECIPE, and
 * it is reached only by a person pressing a button. What is dropped does not
 * go back to the node it came from, does not go into the world, and does not
 * come back. Returning it was considered and rejected: a lossless bag is a
 * scratchpad, and it would take the weight out of every decision to pick
 * something up.
 *
 * Only CARRIED categories may be dropped. A tool is on the belt and occupies
 * no room, so tipping one out gains nothing and loses something permanent; a
 * container would lower capacity, which is the regression the whole feature
 * refuses. The overturn this action rests on was argued on the grounds that
 * the player SPENDS, and a destruction that buys nothing is not spending.
 *
 * Whole stacks only. A quantity would be a second way to say the same thing —
 * a harvest that takes less is how you avoid carrying more than you want, and
 * that choice belongs at the moment of picking up rather than of putting down.
 *
 * Returns how many units went, so the caller can say so in the app's own
 * voice. Zero is an ordinary answer: there was nothing there.
 */
final readonly class DropItem
{
    /**
     * @return int How many units were destroyed.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  its category is not one Blob carries.
     */
    public function handle(User $user, string $item): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        if (! in_array((string) ($catalogue[$item]['category'] ?? ''), $carried, true)) {
            throw new InvalidArgumentException("[{$item}] is not carried, so there is nothing to tip out.");
        }

        return DB::transaction(function () use ($user, $item): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            $quantity = $stack->quantity;

            // Deleted rather than zeroed, the same rule BuildItem already
            // follows for a stack spent to nothing: an empty row would render
            // as a line saying Blob is carrying no timber, which is not a
            // thing worth saying.
            $stack->delete();

            return $quantity;
        });
    }
}
```

- [ ] **Step 4: Author the line Blob says**

In `config/companion.php`, directly after the closing `],` of the `capacity` block and before the final `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | Dropping
    |--------------------------------------------------------------------------
    |
    | What Blob does when the player tips a stack out. Same copy rules as
    | everywhere else, and one more that matters here: IT DESCRIBES THE ACT AND
    | NEVER COMMENTS ON THE CHOICE. No "are you sure", no tally of what has been
    | discarded, nothing that reads as the app having an opinion about it.
    |
    | It also must not imply the thing can be fetched back. It cannot: what is
    | dropped does not return to the node, to the world, or to anywhere else.
    |
    | `{label}` is the thing that went.
    |
    */

    'drop' => '{name} tips out the {label}. The bag is lighter.',
```

- [ ] **Step 5: Put the new file under the vocabulary scan**

In `tests/Feature/Companion/CompanionVocabularyTest.php::sourceFiles()`, beside the other actions (after the `$root.'/app/Actions/BuildItem.php',` line):

```php
            // The one path in the feature that destroys something. It is the
            // likeliest place in the whole app for a scolding word to appear,
            // which is exactly why it is scanned.
            $root.'/app/Actions/DropItem.php',
```

- [ ] **Step 6: Run and verify**

Run: `php artisan test --compact --filter='DropItemTest|CompanionVocabularyTest|CompanionContentTest'`
Expected: all green. Then `php artisan test --compact` — expected **1311/1311** (1303 + 8).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/DropItem.php config/companion.php tests/Feature/Companion/DropItemTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): let the player tip a stack out of the bag"
```

---

### Task 3: The two routes, and what the screen says

**Files:**
- Create: `app/Http/Controllers/CompanionItemController.php`
- Modify: `app/Http/Controllers/CompanionNodeController.php:41-100`
- Modify: `routes/web.php:163-169`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`
- Create: `tests/Feature/Companion/CompanionShelterScreenTest.php` — created here with the inventory cases; Task 11 appends the shelter cases to it.

**Interfaces:**
- Consumes: `HarvestNode::handle($user, $node, ?int $wanted)` (Task 1), `DropItem::handle($user, $item)` (Task 2), `CompanionController::SAID_KEY`, `::STAY_KEY`, `::REVEALED_KEY`.
- Produces: route names `companion.nodes.store` (unchanged, now accepting an optional `take`) and `companion.items.destroy` (`DELETE companion/items/{item}`).

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\Companion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write routes this phase adds, driven the way the screen drives them.
 *
 * Every refusal here is an ordinary state of the world rather than an error:
 * it comes back as a line in Blob's own voice with the bag still open, because
 * these are posted from inside the bag and the point of the press is to watch
 * something change.
 */
class CompanionShelterScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function clearing(): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);

        return [$user, $companion];
    }

    /**
     * Taking a chosen amount is the same route as clicking the node, because
     * from the user's side it is the same gesture with an amount attached.
     */
    public function test_taking_a_chosen_amount_leaves_the_rest_standing(): void
    {
        [$user, $companion] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 2])
            ->assertRedirect()
            ->assertSessionHas(CompanionControllerKeys::SAID, '{name} comes back with 2 fibre.');

        $this->assertSame(2, (int) $companion->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame(4, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** A take comes from inside the bag, so the bag stays up to show the result. */
    public function test_taking_a_chosen_amount_leaves_the_bag_open(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 1])
            ->assertSessionHas(CompanionControllerKeys::STAY, true);
    }

    /** A plain click in the clearing does not, and still fills the bag. */
    public function test_a_plain_click_still_fills_the_bag_and_opens_nothing(): void
    {
        [$user, $companion] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']))
            ->assertSessionMissing(CompanionControllerKeys::STAY);

        $this->assertSame(5, (int) $companion->items()->where('item', 'fibre')->value('quantity'));
    }

    /** Nought and less are not amounts. The route says so before Blob moves. */
    public function test_asking_for_none_is_rejected_by_the_route(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 0])
            ->assertSessionHasErrors('take');
    }

    /** Dropping says what Blob did, names the thing, and leaves the bag open. */
    public function test_dropping_says_what_blob_did(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'timber', 'quantity' => 3]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertRedirect()
            ->assertSessionHas(CompanionControllerKeys::SAID, '{name} tips out the timber. The bag is lighter.')
            ->assertSessionHas(CompanionControllerKeys::STAY, true);

        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
    }

    /** A renamed companion is named in it, because every line carries {name}. */
    public function test_the_drop_line_carries_the_companions_name(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->update(['name' => 'Pebble']);
        $companion->items()->create(['item' => 'timber', 'quantity' => 1]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertSessionHas(CompanionControllerKeys::SAID, 'Pebble tips out the timber. The bag is lighter.');
    }

    /** A tool has no drop route at all: there is nothing there to reach. */
    public function test_a_tool_has_no_drop_route(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'axe']))
            ->assertNotFound();

        $this->assertSame(1, (int) $companion->items()->where('item', 'axe')->value('quantity'));
    }

    public function test_dropping_requires_signing_in(): void
    {
        $this->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertRedirect(route('login'));
    }
}
```

**Note for the implementer:** `CompanionControllerKeys::SAID` above is shorthand for readability in this plan only. Write the real constants — `\App\Http\Controllers\CompanionController::SAID_KEY` and `::STAY_KEY` — and import `CompanionController` at the top. Do not create a `CompanionControllerKeys` class.

Also: the expected `said` for the take case is the substituted line. `Companion::nameFor()` returns `'Blob'` for an unnamed companion, so the literal expectation is `'Blob comes back with 2 fibre.'`, not `'{name} comes back with 2 fibre.'`. Write the substituted strings; the `{name}` above is showing you which config line is being read.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/CompanionShelterScreenTest.php`
Expected: failures on `Route [companion.items.destroy] not defined` and on `take` being ignored.

- [ ] **Step 3: Teach the node route an amount**

In `app/Http/Controllers/CompanionNodeController.php`, change `store()` and `gather()`:

```php
    public function store(Request $request, string $node): RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        abort_unless(array_key_exists($node, $authored), Status::HTTP_NOT_FOUND);

        // An amount is optional and, when present, says where the press came
        // from: the clearing's own click sends none and fills the bag, and
        // only the bag's own control names a number.
        $validated = $request->validate([
            'take' => ['sometimes', 'integer', 'min:1'],
        ]);

        $wanted = array_key_exists('take', $validated) ? (int) $validated['take'] : null;

        $user = $request->user();
        $entry = $authored[$node];
        $name = Companion::nameFor($user);

        if ($this->hasSkill($user, (string) $entry['skill'])) {
            $tool = (string) ($entry['tool'] ?? '');

            if ($tool !== '' && ! $this->hasTool($user, $tool)) {
                return $this->stayIfAsked(
                    back()->with(
                        CompanionController::SAID_KEY,
                        $this->say($entry['blunt'] ?? '', $name),
                    ),
                    $wanted,
                );
            }

            return $this->gather($user, $node, $entry, $name, $wanted);
        }

        $firstLook = $this->neverMet($user, $node);

        app(MeetNode::class)->handle($user, $node);

        return back()
            ->with(CompanionController::SAID_KEY, $this->say($entry['met'] ?? '', $name))
            ->with(CompanionController::REVEALED_KEY, $firstLook);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function gather(User $user, string $node, array $entry, string $name, ?int $wanted): RedirectResponse
    {
        try {
            $moved = app(HarvestNode::class)->handle($user, $node, $wanted);
        } catch (CompanionEconomyException) {
            return $this->stayIfAsked(
                back()->with(CompanionController::SAID_KEY, $this->say($entry['full'] ?? '', $name)),
                $wanted,
            );
        }

        $line = $moved === 0
            ? $this->say($entry['empty'] ?? '', $name)
            : str_replace('{count}', (string) $moved, $this->say($entry['took'] ?? '', $name));

        return $this->stayIfAsked(back()->with(CompanionController::SAID_KEY, $line), $wanted);
    }

    /**
     * An amount could only have come from inside the bag, so the bag stays up
     * to show what changed. A click in the clearing sends none and leaves the
     * screen exactly as it was.
     */
    private function stayIfAsked(RedirectResponse $response, ?int $wanted): RedirectResponse
    {
        return $wanted === null
            ? $response
            : $response->with(CompanionController::STAY_KEY, true);
    }
```

Extend the class docblock with one paragraph:

```
 * WITH AN AMOUNT, it does the same thing and takes only that much. The amount
 * is also how this route knows the press came from inside the bag rather than
 * from the clearing, which is what decides whether the bag stays up afterwards.
```

- [ ] **Step 4: Write the drop controller**

`php artisan make:controller CompanionItemController --no-interaction`, then replace its body:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\DropItem;
use App\Models\Companion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Tipping a stack out of the bag.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is
 * to watch the room appear.
 *
 * NO CONFIRMATION. Spec §5 rules it out and the reason is the copy rule rather
 * than convenience — an "are you sure" is the app having an opinion about a
 * choice that is the player's, and every other refusal in this feature is
 * careful not to. What comes back describes what Blob did and stops there.
 *
 * Only carried categories are routable at all. A tool or a container reaching
 * this is a malformed request rather than a state of the world, so it is a 404
 * rather than a line in Blob's voice: there is no sentence to say about a
 * gesture the screen never offers.
 */
class CompanionItemController extends Controller
{
    /** Only the entries in `companion.bag` a bag can actually be relieved of. */
    private function droppable(): array
    {
        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_keys(array_filter(
            (array) config('companion.bag', []),
            static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), $carried, true),
        ));
    }

    public function destroy(Request $request, DropItem $drop, string $item): RedirectResponse
    {
        abort_unless(in_array($item, $this->droppable(), true), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $label = (string) (config("companion.bag.{$item}.label") ?? $item);

        $dropped = $drop->handle($user, $item);

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($dropped === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{label}'],
                [Companion::nameFor($user), $label],
                (string) config('companion.drop'),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, directly after the `companion/build` line:

```php
    // Tipping a stack out. The one route in this feature that destroys, and it
    // is reachable only for the categories the bag actually carries — a tool
    // is on the belt and a container is the room itself, so neither is here.
    Route::delete('companion/items/{item}', [CompanionItemController::class, 'destroy'])
        ->name('companion.items.destroy');
```

Add `use App\Http\Controllers\CompanionItemController;` to the imports, in alphabetical position.

- [ ] **Step 6: Register the controller with the vocabulary scan**

In `CompanionVocabularyTest::sourceFiles()`, after `$root.'/app/Http/Controllers/CompanionBuildController.php',`:

```php
            $root.'/app/Http/Controllers/CompanionItemController.php',
```

- [ ] **Step 7: Regenerate the typed routes**

Run: `php artisan wayfinder:generate --with-form`
Expected: `resources/js/routes/companion/items/index.ts` appears. Nothing else in `resources/js/routes/` or `resources/js/actions/` should change; if other files move, that is drift from an earlier generate and should be reported rather than committed silently.

- [ ] **Step 8: Run and verify**

Run: `php artisan test --compact --filter='CompanionShelterScreenTest|CompanionClearingTest|HarvestNodeTest'`
Expected: green. Then `php artisan test --compact` — expected **1319/1319** (1311 + 8).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers routes/web.php resources/js/routes tests/Feature/Companion
git commit -m "feat(companion): route a chosen amount and a dropped stack"
```

---

### Task 4: The bag shows both choices

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php:42-53, 98-123`
- Modify: `resources/js/patyourself/companion.tsx:38-44` (`BagItemData`)
- Modify: `resources/js/patyourself/companion-bag.tsx:99-119` (`Held`), plus a new `Clearing` section
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Modify: `resources/css/patyourself.css` (three rules, **by hand**)
- Test: `tests/Feature/Companion/CompanionBagTest.php`, `resources/js/patyourself/companion-bag.test.tsx`

**Interfaces:**
- Consumes: route `companion.items.destroy` and `companion.nodes.store` from Task 3.
- Produces: `items[].droppable: bool` on the payload; `BagItemData.droppable: boolean` in TypeScript.

- [ ] **Step 1: Write the failing PHP test**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * A row says whether it is a thing the bag can be relieved of. The client
     * could work it out from the category, but that would put the carried list
     * in two places and they would eventually disagree about what a tool is.
     */
    public function test_a_held_row_says_whether_it_can_be_tipped_out(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);
        $companion->items()->create(['item' => 'basket', 'quantity' => 1]);

        $rows = collect($this->bag($user)['items'])->keyBy('item');

        $this->assertTrue($rows['fibre']['droppable']);
        $this->assertFalse($rows['axe']['droppable']);
        $this->assertFalse($rows['basket']['droppable']);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter='test_a_held_row_says_whether_it_can_be_tipped_out'`
Expected: `Undefined array key "droppable"`.

- [ ] **Step 3: Add the key**

In `CompanionBag::items()`, and in the `@return` shape on both `items()` and `forUser()`:

```php
     * @return list<array{item: string, label: string, category: string, quantity: int, droppable: bool}>
     */
    private function items(Companion $companion): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_values(array_filter(array_map(
            static function (CompanionItem $held) use ($catalogue, $carried): ?array {
                $entry = $catalogue[$held->item] ?? null;

                return $entry === null ? null : [
                    'item' => $held->item,
                    'label' => (string) $entry['label'],
                    'category' => (string) $entry['category'],
                    'quantity' => $held->quantity,
                    // Answered here rather than from the category on the
                    // client, so the list of what Blob carries has one author.
                    // A tool is on the belt and a container is the room
                    // itself; neither is a thing the bag can be relieved of.
                    'droppable' => in_array((string) $entry['category'], $carried, true),
                ];
            },
            $companion->items->all(),
        )));
    }
```

- [ ] **Step 4: Write the failing Vitest cases**

Append to `resources/js/patyourself/companion-bag.test.tsx`:

```tsx
    /**
     * Two choices this phase adds, and the reason both are here rather than in
     * the clearing: deciding how much to carry is a bag question, and the
     * clearing is already at the limit of what its labels can hold.
     */
    it('offers to tip out a carried stack, and never a tool', () => {
        open(
            bag({
                held: 4,
                items: [
                    {
                        item: 'timber',
                        label: 'timber',
                        category: 'material',
                        quantity: 3,
                        droppable: true,
                    },
                    {
                        item: 'axe',
                        label: 'axe',
                        category: 'tool',
                        quantity: 1,
                        droppable: false,
                    },
                ],
            }),
        );

        const rows = screen.getAllByRole('listitem');
        const timber = rows.find((row) => row.textContent?.includes('timber'));
        const axe = rows.find((row) => row.textContent?.includes('axe'));

        expect(timber).toBeDefined();
        expect(axe).toBeDefined();
        expect(
            within(timber as HTMLElement).getByRole('button', { name: /drop/i }),
        ).toBeInTheDocument();
        expect(
            within(axe as HTMLElement).queryByRole('button', { name: /drop/i }),
        ).toBeNull();
    });

    /** What is standing, and a control to take some of it rather than all. */
    it('lists what is standing at a node whose skill is known', () => {
        open(
            bag({
                nodes: [
                    {
                        node: 'reeds',
                        label: 'the reeds',
                        available: 6,
                        skill: 'gather-fibre',
                        met: true,
                        known: true,
                    },
                    {
                        node: 'trunk',
                        label: 'the fallen trunk',
                        available: 0,
                        skill: 'chop-wood',
                        met: true,
                        known: true,
                    },
                    {
                        node: 'deadfall',
                        label: 'the fallen branches',
                        available: 4,
                        skill: 'gather-wood',
                        met: false,
                        known: false,
                    },
                ],
            }),
        );

        expect(screen.getByText('the reeds')).toBeInTheDocument();
        expect(
            screen.getByRole('spinbutton', { name: /how much to take from the reeds/i }),
        ).toBeInTheDocument();

        // Nothing standing, and a node whose skill is not known, are both
        // absent rather than listed at zero or greyed.
        expect(screen.queryByText('the fallen trunk')).toBeNull();
        expect(screen.queryByText('the fallen branches')).toBeNull();
    });
```

Add `within` to the `@testing-library/react` import at the top of the file.

- [ ] **Step 5: Run it and watch it fail**

Run: `npx vitest run resources/js/patyourself/companion-bag.test.tsx`
Expected: both new cases fail — no drop button, no spinbutton. The TypeScript build also complains that `droppable` is not a property of `BagItemData`.

- [ ] **Step 6: Widen the payload type and the fixture**

In `resources/js/patyourself/companion.tsx`:

```ts
/** One stack Blob is carrying. */
export interface BagItemData {
    item: string;
    label: string;
    category: string;
    quantity: number;
    /**
     * Whether the bag can be relieved of it. Answered by the server from
     * `capacity.carried` rather than derived from `category` here, so what
     * Blob carries has one author — a tool is on the belt and a container is
     * the room itself.
     */
    droppable: boolean;
}
```

`companion.fixture.ts` needs no change: its `items` default is `[]`. Callers that build an item row must now pass `droppable`; the two in `pages/companion.test.tsx` and the one in `companion-bag.test.tsx`'s existing guard case do. Update them to `droppable: true`.

- [ ] **Step 7: Render both controls**

In `resources/js/patyourself/companion-bag.tsx`, add the imports:

```tsx
import { destroy as dropRoute } from '@/routes/companion/items';
import { store as takeRoute } from '@/routes/companion/nodes';
```

Replace `Held`'s row and add `Clearing`, and call `<Clearing nodes={bag.nodes} />` between `<Held />` and `<Build />` in `CompanionBag`:

```tsx
function Held({ bag }: { bag: CompanionBagData }) {
    if (bag.items.length === 0) {
        return (
            <p className="c-bagnone">{bag.name} is not carrying anything yet.</p>
        );
    }

    return (
        <>
            <p className="c-baggrp">Held</p>
            <ul className="c-bagrows">
                {bag.items.map((item) => (
                    <li key={item.item} className="c-bagrow">
                        <span>{item.label}</span>
                        <span className="c-bagheld">
                            <b className="c-bagqty">{item.quantity}</b>
                            {/* Offered only for what the bag actually
                                carries. A tool takes no room, so tipping one
                                out would buy nothing and lose something
                                permanent — and a container IS the room.

                                No confirmation, by rule: an "are you sure" is
                                the app having an opinion about a choice that
                                belongs to the player. */}
                            {item.droppable && (
                                <Form
                                    {...dropRoute.form(item.item)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <button
                                            type="submit"
                                            className="c-bagdrop"
                                            disabled={processing}
                                        >
                                            drop
                                        </button>
                                    )}
                                </Form>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * What is standing out there, and how much of it to carry back.
 *
 * Here rather than in the clearing for two reasons. Deciding how much to carry
 * is a bag question — the clearing is where a thing IS, the bag is what you
 * are holding — and the clearing's hotspot labels are already at the limit of
 * what the scene can hold at small sizes, which `scenes.ts` records at length.
 *
 * Only nodes whose skill is known and which actually have something standing.
 * A node with nothing at it is absent rather than listed at zero: a zero is a
 * count of what you have not got.
 */
function Clearing({ nodes }: { nodes: CompanionBagData['nodes'] }) {
    const standing = nodes.filter((node) => node.known && node.available > 0);

    if (standing.length === 0) {
        return null;
    }

    return (
        <>
            <p className="c-baggrp">In the clearing</p>
            <ul className="c-bagrows">
                {standing.map((node) => (
                    <li key={node.node} className="c-bagrow">
                        <span>{node.label}</span>
                        <Form
                            {...takeRoute.form(node.node)}
                            options={{ preserveScroll: true }}
                            className="c-bagbuy"
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="number"
                                        name="take"
                                        min={1}
                                        max={node.available}
                                        defaultValue={1}
                                        className="c-bagtake"
                                        aria-label={`How much to take from ${node.label}`}
                                    />
                                    <button
                                        type="submit"
                                        className="pixel-button"
                                        disabled={processing}
                                    >
                                        take
                                    </button>
                                </>
                            )}
                        </Form>
                    </li>
                ))}
            </ul>
        </>
    );
}
```

- [ ] **Step 8: Add the CSS, by hand**

In `resources/css/patyourself.css`, immediately after the `.c-bagqty{...}` line (around line 1146), insert three lines in exactly this style — single-line rules, no spaces after colons, semicolon before the closing brace. **Do not run prettier on this file.**

```css
.c-bagheld{display:flex;align-items:baseline;gap:9px;margin-left:auto;}
.c-bagdrop{padding:0;border:0;background:none;font-family:var(--font-pixel);font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#A58D64;cursor:pointer;}
.c-bagdrop:hover{color:#E9B96B;}
.c-bagdrop:focus-visible{outline:2px solid #E9B96B;outline-offset:1px;}
.c-bagtake{width:40px;min-width:0;margin-right:7px;background:#241C15;border:2px solid #3D3124;padding:3px 4px;font-family:var(--font-pixel);font-size:9.5px;color:#F2E3BF;text-align:center;}
.c-bagtake:focus-visible{outline:2px solid #E9B96B;outline-offset:1px;}
```

- [ ] **Step 9: Run everything**

```bash
php artisan test --compact --filter='CompanionBagTest'
npx vitest run resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx
npx eslint resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.tsx
npx prettier --check resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.tsx
```
Expected: all green. Both runs of the `never shows what has not happened` guard must still pass — the `take` control shows a number bounded by what is standing, which is a fact about the world, not a share of a whole. Then the full suites: `php artisan test --compact` → **1320/1320**; `npx vitest run` → **667/667**.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php resources/js resources/css/patyourself.css tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): put taking some and dropping a stack in the bag"
```

---

### Task 5: The guard on the overturn

**Files:**
- Modify: `tests/Feature/Companion/CompanionRulingsTest.php` (append; update the class docblock from "three things F2 refuses" to name F3's addition)

**Interfaces:**
- Consumes: `DropItem` (Task 2), `HarvestNode` (Task 1), the existing `clearing()` / `logOnce()` / `standing()` helpers.
- Produces: nothing. This task exists so the ruling cannot be quietly reversed.

- [ ] **Step 1: Write the guard**

Update the class docblock's first line to *"The things F2 and F3 refuse, each written as a test that fails if the refusal is ever quietly reversed."*, then append:

```php
    /**
     * Spec §2's second overturn, guarded from the side that matters.
     *
     * F3 adds a destruction, and the whole justification is that the PLAYER
     * initiates it. The line that must hold is therefore not "nothing is
     * destroyed" — that one is genuinely overturned — but that nothing in this
     * feature ever drops, decays, expires or discards on the player's behalf,
     * for any reason, INCLUDING A FULL BAG.
     *
     * A full bag is the case worth driving, because it is the one place where
     * a later phase might "helpfully" make room. Here the bag is full, more is
     * standing than will fit, and a week of the record goes past: the harvest
     * refuses, the build refuses, and every quantity is exactly what it was.
     */
    public function test_nothing_is_dropped_on_the_players_behalf(): void
    {
        [$user, $companion] = $this->clearing();

        // Full to the brim, and holding something with no single-material
        // exit — the exact shape of the trap F3 exists to defuse.
        $companion->items()->create(['item' => 'timber', 'quantity' => 5]);
        $companion->nodes()->updateOrCreate(['node' => 'reeds'], ['available' => 9]);

        $before = $companion->fresh()->items->pluck('quantity', 'item')->all();

        for ($index = 0; $index < 7; $index++) {
            $this->logOnce($user);
        }

        // A harvest into a bag with no room refuses, and refuses WHOLE.
        try {
            app(\App\Actions\HarvestNode::class)->handle($user, 'reeds');
            $this->fail('a full bag should refuse rather than make room');
        } catch (\App\Services\Companion\CompanionEconomyException) {
            // The refusal is the expected outcome; what matters is below.
        }

        // So does a build that cannot fit what it would make.
        try {
            app(\App\Actions\BuildItem::class)->handle($user, 'basket');
            $this->fail('a recipe short of materials should refuse');
        } catch (\App\Services\Companion\CompanionEconomyException) {
            // Likewise.
        }

        $after = $companion->fresh()->items->pluck('quantity', 'item')->all();

        $this->assertSame($before, $after, 'something was taken that nobody asked to lose');

        // And the world only ever grew: 3 seeded plus one per logged outcome
        // against the one learned skill, seven times over.
        $this->assertSame(16, $this->standing($companion->fresh(), 'reeds'));
    }

    /**
     * The other half of the same rule: when the player DOES ask, exactly what
     * they asked for goes and nothing else does — including at the node the
     * material came from, which is not a place things go back to.
     */
    public function test_a_drop_takes_exactly_what_was_dropped_and_nothing_else(): void
    {
        [$user, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'timber', 'quantity' => 3]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $standingBefore = $this->standing($companion, 'reeds');

        app(\App\Actions\DropItem::class)->handle($user, 'timber');

        $fresh = $companion->fresh();

        $this->assertSame(0, $fresh->items()->where('item', 'timber')->count());
        $this->assertSame(2, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame($standingBefore, $this->standing($fresh, 'reeds'));
    }
```

Use proper `use` imports at the top of the file rather than the fully-qualified names above — `App\Actions\BuildItem`, `App\Actions\DropItem`, `App\Actions\HarvestNode`, `App\Services\Companion\CompanionEconomyException`.

- [ ] **Step 2: Prove the guard can fail**

Before running it green, demonstrate it discriminates. Temporarily edit `HarvestNode` so that a full bag drops the smallest stack instead of throwing:

```php
            if ($room === 0) {
                $companion->items()->orderBy('quantity')->first()?->delete();  // MUTANT
                $companion->load('items');
                $room = $companion->room();
            }
```

Run: `php artisan test --compact --filter='test_nothing_is_dropped_on_the_players_behalf'`
Expected: **FAIL** with `something was taken that nobody asked to lose`. **Then revert the mutation** — `git checkout app/Actions/HarvestNode.php` — and confirm the file is byte-identical with `git diff --stat`.

Record the mutation, the failure message, and the revert in the task report. A guard nobody has seen fail is decoration.

- [ ] **Step 3: Run it green**

Run: `php artisan test --compact --filter='CompanionRulingsTest'`
Expected: 5 passing. Then `php artisan test --compact` — expected **1322/1322**.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Companion/CompanionRulingsTest.php
git commit -m "test(companion): guard that nothing is discarded unasked"
```

---

## Batch 1 review checkpoint

**Stop here.** Report to the human partner:

- PHP `php artisan test --compact` (expected **1322**, from a 1299 baseline: +23, no regressions) and Vitest `npx vitest run` (expected **667**).
- `git log --oneline` for the batch, and confirmation that `main` fast-forwards cleanly (`git merge-base --is-ancestor main HEAD`).
- The one design call this batch makes that the spec left open, stated as a question: **the take control lives in the bag, not on the node hotspot.** The reasoning is that the clearing's labels are already the sorest spot in the layout and spec §7 forbids F3 making that worse, and that "how much am I carrying" is a bag question. If they would rather it sat on the hotspot, it is one component move.
- The other call: **a drop takes the whole stack.** A quantity would be a second way to say what partial harvest already says, at the moment of putting down rather than picking up.

---

# BATCH 2 — The shelter

Stages, storage, build rules, the interior parameterised, the migration. Server-side and component-level; nothing reaches the page until Batch 3.

**One interim state to state plainly at review:** Task 14 removes the `cabin` entry from `config('companion.scenes')`, so between this batch and the next, an established account stands in its clearing — with its salvage heap and its whole economy visible for the first time — and cannot go indoors, because the control that takes it there ships in Batch 3. That is strictly more of the app than it can reach today, where `insights: 5` pins it indoors with no clearing at all. It is still a gap, and it closes in one batch.

---

### Task 6: One arithmetic, two callers — `shortfallFor()` and `spend()`

**Files:**
- Modify: `app/Models/Companion.php` (append two methods after `wouldFit()`)
- Modify: `app/Actions/BuildItem.php:77-127`
- Test: `tests/Feature/Companion/BuildItemTest.php` (append two cases). **Its 22 existing cases are the guard: they must pass unedited.**

**Interfaces:**
- Consumes: `Companion::items()`.
- Produces: `Companion::shortfallFor(array $recipe): array<string,int>` — ingredient to how many more are needed, empty when the recipe is affordable. `Companion::spend(array $recipe): void` — consumes it, deleting a stack spent to nothing. Task 9's `BuildShelter` is the second caller.

**Why now, before `BuildShelter` exists.** F2's C1 finding was two capacity rules that disagreed, fixed by extracting `wouldFit()` so a read and a write could not drift. A shelter consumes materials exactly as a recipe does, including the rule that a stack spent to nothing is deleted rather than left at zero. Writing that loop a second time in a new action is how the two start disagreeing about an empty row.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/BuildItemTest.php`:

```php
    /**
     * The consumption arithmetic, addressed directly rather than through a
     * recipe, because a second caller is about to use it: a shelter consumes
     * materials exactly as a recipe does and must agree about what an emptied
     * stack becomes.
     */
    public function test_a_shortfall_names_everything_that_is_missing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 1]);

        $companion = $companion->fresh();

        $this->assertSame([], $companion->shortfallFor(['fibre' => 1]));
        $this->assertSame(
            ['fibre' => 2, 'deadfall' => 3],
            $companion->shortfallFor(['fibre' => 3, 'deadfall' => 3]),
        );
    }

    /** And spending it deletes what it emptied rather than leaving a zero row. */
    public function test_spending_a_recipe_leaves_no_emptied_row(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        $companion->items()->create(['item' => 'deadfall', 'quantity' => 4]);

        $companion->fresh()->spend(['fibre' => 3, 'deadfall' => 1]);

        $this->assertSame(0, $companion->items()->where('item', 'fibre')->count());
        $this->assertSame(3, (int) $companion->items()->where('item', 'deadfall')->value('quantity'));
    }
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/BuildItemTest.php`
Expected: both new cases fail with `Call to undefined method App\Models\Companion::shortfallFor()`. The other 22 pass — note the number, because it is the guard for Step 4.

- [ ] **Step 3: Add the two methods**

Append to `app/Models/Companion.php`, after `wouldFit()`:

```php
    /**
     * What a recipe is still short of: ingredient to how many more are needed.
     *
     * Empty means affordable. Everything missing rather than the first thing,
     * so a recipe short on two ingredients takes one look rather than two
     * attempts.
     *
     * Here rather than in the action for the same reason {@see wouldFit()} is:
     * a shelter stage consumes materials exactly as a recipe does, and two
     * copies of this loop would eventually disagree about what an emptied
     * stack becomes.
     *
     * @param  array<string, int>  $recipe
     * @return array<string, int>
     */
    public function shortfallFor(array $recipe): array
    {
        $stacks = $this->items()
            ->whereIn('item', array_keys($recipe))
            ->get()
            ->keyBy(static fn (CompanionItem $stack): string => $stack->item);

        $missing = [];

        foreach ($recipe as $ingredient => $needed) {
            $have = (int) ($stacks->get($ingredient)?->quantity ?? 0);

            if ($have < $needed) {
                $missing[$ingredient] = $needed - $have;
            }
        }

        return $missing;
    }

    /**
     * Spends a recipe. Call only once {@see shortfallFor()} has come back
     * empty — this assumes every ingredient is there, because a partial spend
     * would be the first thing in this feature that ever took something away
     * without giving anything back.
     *
     * A stack spent to nothing is REMOVED rather than left at zero: an empty
     * row would render as a line in the bag saying Blob is carrying no fibre,
     * which is not a thing worth saying.
     *
     * @param  array<string, int>  $recipe
     */
    public function spend(array $recipe): void
    {
        $stacks = $this->items()
            ->whereIn('item', array_keys($recipe))
            ->get()
            ->keyBy(static fn (CompanionItem $stack): string => $stack->item);

        foreach ($recipe as $ingredient => $needed) {
            /** @var CompanionItem $stack */
            $stack = $stacks->get($ingredient);

            if ($stack->quantity === $needed) {
                $stack->delete();

                continue;
            }

            $stack->decrement('quantity', $needed);
        }
    }
```

- [ ] **Step 4: Move `BuildItem` onto them**

In `app/Actions/BuildItem.php`, replace the block from `$stacks = $companion->items()` down to the end of the consumption `foreach` (currently lines 77–127) with:

```php
            $missing = $companion->shortfallFor($recipe);

            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($item, $missing);
            }

            // Capacity last, because you cannot overflow with materials you do
            // not have — a short recipe should say what it is short of rather
            // than complain about room.
            //
            // Only CARRIED categories count, on both sides. A recipe that makes
            // a tool or a container is always net-negative and can never
            // refuse here; one that makes three carried things out of one is
            // net +2, and that is the case this exists for. The arithmetic
            // itself lives on the model — {@see Companion::wouldFit()} — so
            // CompanionBag's read of "will this build" can never drift from
            // what this action actually enforces.
            $companion->load('items');

            if (! $companion->wouldFit($item, $recipe, $makes)) {
                throw CompanionEconomyException::noRoomFor($item);
            }

            $companion->spend($recipe);
```

The `use App\Models\CompanionItem;` import is now unused in `BuildItem` except for the return type — check and keep it if the method still returns `CompanionItem`, which it does.

- [ ] **Step 5: Run and verify nothing moved**

Run: `php artisan test --compact tests/Feature/Companion/BuildItemTest.php`
Expected: **24 passing** (22 + 2). All 22 originals must pass **unedited** — if any needed a change, stop and report: that means the extraction changed behaviour rather than location.

Then `php artisan test --compact` — expected **1324/1324**.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Companion.php app/Actions/BuildItem.php tests/Feature/Companion/BuildItemTest.php
git commit -m "refactor(companion): one place spends a recipe"
```

---

### Task 7: The two columns

**Files:**
- Create: `database/migrations/2026_09_18_000001_add_the_shelter_to_companions_table.php`
- Modify: `app/Models/Companion.php` (`#[Fillable]`, a `casts()` method)
- Test: `tests/Feature/Companion/CompanionStorageTest.php` (append)

**Interfaces:**
- Produces: `companions.shelter` (nullable string, null = nothing built) and `companions.salvaged_at` (nullable timestamp). `Companion::$shelter` and `Companion::$salvaged_at`, the latter cast to a `CarbonImmutable`/`Carbon` instance.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Companion/CompanionStorageTest.php` (it already uses `RefreshDatabase`; add `use Illuminate\Support\Facades\Schema;` if absent):

```php
    /**
     * The shelter is the one stored value in this feature that only ever moves
     * one way. It is a single value rather than a collection because the stages
     * REPLACE one another — building the hut is the lean-to becoming a hut, and
     * the lean-to's planks are in it.
     */
    public function test_the_companion_row_can_hold_a_shelter(): void
    {
        $this->assertTrue(Schema::hasColumn('companions', 'shelter'));

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $this->assertNull($companion->shelter);

        $companion->update(['shelter' => 'lean-to']);

        $this->assertSame('lean-to', $companion->fresh()->shelter);
    }

    /**
     * And a mark saying its cabin was already converted into a heap.
     *
     * Stored rather than derived because the heap is DELETED once it is
     * drained, so "is there a salvage node?" cannot answer "has this account
     * been converted?" — and an unmarked account would be handed a second
     * cabin's worth of planks the next time the conversion ran.
     */
    public function test_the_companion_row_remembers_a_cabin_it_already_gave_back(): void
    {
        $this->assertTrue(Schema::hasColumn('companions', 'salvaged_at'));

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $this->assertNull($companion->salvaged_at);

        $companion->forceFill(['salvaged_at' => now()])->save();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $companion->fresh()->salvaged_at);
    }
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact --filter='CompanionStorageTest'`
Expected: both fail on `Failed asserting that false is true` for the column checks.

- [ ] **Step 3: Write the migration**

`php artisan make:migration add_the_shelter_to_companions_table --no-interaction` produces a dated filename; rename it to `2026_09_18_000001_add_the_shelter_to_companions_table.php` so it sorts before Task 13's backfill. Body:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Blob has built, and whether the cabin the record used to grant has
 * already been handed back as materials.
 *
 * `shelter` is a single value rather than a collection because the stages
 * REPLACE one another: building the hut is the lean-to becoming a hut, and the
 * lean-to's planks are in it. It only ever advances — nothing in the
 * application lowers it, and {@see \App\Actions\BuildShelter} is its only
 * writer. Null is "nothing built", which is where every account starts.
 *
 * WHERE BLOB IS is deliberately NOT here. Inside or outside is where you are
 * looking, not something you own, and it resets to outside on load the way a
 * modal does. What is built and where Blob stands are independent facts — you
 * can stand outside a cabin or sit inside a lean-to — and conflating them is
 * what the arc's own sentence about this phase got wrong. (F3 §1, §3)
 *
 * `salvaged_at` is the mark that an established account's cabin was already
 * converted. It has to be stored: the heap is deleted once it is drained, so
 * the heap's own existence cannot answer the question, and an unmarked account
 * would be handed a second cabin's worth of planks. It is a choice the app
 * made rather than a claim about the record, which is what keeps it inside
 * "store only what cannot be derived".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->string('shelter')->nullable()->after('name');
            $table->timestamp('salvaged_at')->nullable()->after('xp_spent');
        });
    }

    public function down(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->dropColumn(['shelter', 'salvaged_at']);
        });
    }
};
```

`after()` is a MySQL positional hint and is ignored on SQLite, so it is safe in both.

- [ ] **Step 4: Teach the model**

In `app/Models/Companion.php`:

```php
#[Fillable([
    'name',
    'xp_spent',
    // Only ever written by BuildShelter, and only ever forward. `salvaged_at`
    // is deliberately absent: it is written once, by the conversion, through
    // forceFill — it is not a thing any request should be able to set.
    'shelter',
])]
```

and, after the `$attributes` property:

```php
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salvaged_at' => 'datetime',
        ];
    }
```

- [ ] **Step 5: Run and verify**

Run: `php artisan test --compact --filter='CompanionStorageTest'` → green.
Then `php artisan test --compact` — expected **1326/1326**.

**Also check the local SQLite dev database.** `database/database.sqlite` drifts behind. Back it up before migrating it:

```bash
cp database/database.sqlite database/database.sqlite.bak
php artisan migrate
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Companion.php tests/Feature/Companion/CompanionStorageTest.php
git commit -m "feat(companion): give the companion row a shelter to hold"
```

---

### Task 8: The shelter's content

**Files:**
- Modify: `config/companion.php` (a `shelter` block and a `salvage` block, after `bag` and before `capacity`)
- Create: `tests/Unit/Companion/CompanionShelterContentTest.php`

**Interfaces:**
- Produces: `config('companion.shelter')` — an **ordered** map, stage name to `['label' => string, 'recipe' => array<string,int>, 'insights'? => int, 'built' => string]`. A stage's predecessor is **the previous key**. `config('companion.salvage')` — `['node' => string, 'stock' => int]`.

- [ ] **Step 1: Write the failing content test**

```php
<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

/**
 * F3's content, checked for the ways config can lie.
 *
 * A pure read of `config/companion.php`, in the same style as
 * {@see CompanionContentTest} and {@see CompanionLadderTest}: no database, no
 * container, just the authored data and the invariants anything reading it
 * depends on.
 */
class CompanionShelterContentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require __DIR__.'/../../../config/companion.php';
    }

    public function test_every_stage_is_priced_in_things_that_exist(): void
    {
        $config = $this->config();

        $this->assertNotEmpty($config['shelter']);

        foreach ($config['shelter'] as $stage => $entry) {
            $this->assertNotSame('', trim((string) $entry['label']), "{$stage} has no label");
            $this->assertNotEmpty($entry['recipe'] ?? [], "{$stage} is built from nothing");

            foreach ($entry['recipe'] as $ingredient => $count) {
                $this->assertArrayHasKey(
                    $ingredient,
                    $config['bag'],
                    "{$stage} needs an item that does not exist",
                );
                $this->assertGreaterThan(0, $count, "{$stage} asks for no {$ingredient}");
            }
        }
    }

    /**
     * The order in this map IS the arc: a stage's predecessor is the key
     * before it. A later stage that cost less than an earlier one would make
     * the upgrade read as a discount.
     */
    public function test_each_stage_costs_more_than_the_one_before_it(): void
    {
        $costs = array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $this->config()['shelter'],
        );

        $ascending = array_values($costs);
        sort($ascending);

        $this->assertSame($ascending, array_values($costs));
        $this->assertSame(count($costs), count(array_unique($costs)), 'two stages cost the same');
    }

    /**
     * E1's rule, in the one place F3 could break it: the cabin's floor may
     * stop GRANTING the cabin, but the number may never relocate.
     */
    public function test_only_the_cabin_names_a_floor_and_it_is_still_five(): void
    {
        $withFloors = array_filter(
            $this->config()['shelter'],
            static fn (array $entry): bool => array_key_exists('insights', $entry),
        );

        $this->assertSame(['cabin'], array_keys($withFloors));
        $this->assertSame(5, $withFloors['cabin']['insights']);
    }

    /**
     * A stage is consumed in ONE act, so the bag has to be able to hold its
     * whole price at once. The dearest stage against every container the game
     * has is the check that a later tuning pass cannot quietly price a shelter
     * out of reach of any bag that can be built.
     *
     * Note what this does NOT claim: that the price fits in the BASE bag. It
     * does not, deliberately — the cabin needs at least two containers, which
     * is a real fact about the arc and is written up in `docs/BLOB.md`.
     */
    public function test_the_dearest_stage_fits_in_a_bag_that_can_actually_be_built(): void
    {
        $config = $this->config();

        $reachable = (int) $config['capacity']['base'] + (int) array_sum(array_map(
            static fn (array $item): int => (int) ($item['capacity'] ?? 0),
            array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container',
            ),
        ));

        $dearest = max(array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $config['shelter'],
        ));

        $this->assertLessThanOrEqual(
            $reachable,
            $dearest,
            'the dearest shelter cannot be held in any bag this game can build',
        );
        $this->assertGreaterThan(
            (int) $config['capacity']['base'],
            $dearest,
            'this assertion stops meaning anything if the base bag can hold it',
        );
    }

    /**
     * What the cabin cost to put up is what comes back when it comes apart.
     * Tied to the recipes rather than written out twice, so retuning a stage
     * cannot leave an established account short without anything saying so.
     */
    public function test_the_salvage_is_worth_exactly_what_the_whole_arc_costs(): void
    {
        $config = $this->config();

        $whole = array_sum(array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $config['shelter'],
        ));

        $this->assertSame($whole, (int) $config['salvage']['stock']);
    }

    /** And the heap yields the material every stage is priced in. */
    public function test_the_salvage_yields_what_the_shelter_is_built_from(): void
    {
        $config = $this->config();

        $priced = array_unique(array_merge(...array_map(
            static fn (array $entry): array => array_keys($entry['recipe']),
            array_values($config['shelter']),
        )));

        $this->assertSame([$config['nodes'][$config['salvage']['node']]['yields']], array_values($priced));
    }

    /**
     * A stage's line is shown to the user, so it follows the same copy rules
     * as the ladder's: it describes Blob, it never congratulates, it carries no
     * exclamation mark, and it names the companion with a token rather than
     * with the literal.
     */
    public function test_a_stages_copy_follows_the_same_rules_as_the_ladders(): void
    {
        foreach ($this->config()['shelter'] as $stage => $entry) {
            $this->assertArrayHasKey('built', $entry, "{$stage} says nothing when it goes up");
            $this->assertStringNotContainsString('!', $entry['built'], "{$stage}.built exclaims");
            $this->assertStringNotContainsString('Blob', $entry['built'], "{$stage}.built hardcodes the name");
            $this->assertStringContainsString('{name}', $entry['built'], "{$stage}.built never names the companion");
        }
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Unit/Companion/CompanionShelterContentTest.php`
Expected: every case errors on `Undefined array key "shelter"`.

- [ ] **Step 3: Author the content**

In `config/companion.php`, after the `bag` block's closing `],` and before the `capacity` block:

```php
    /*
    |--------------------------------------------------------------------------
    | The shelter
    |--------------------------------------------------------------------------
    |
    | What stands in the clearing, built out of what was sawn. ORDERED, and the
    | order IS the arc: a stage's predecessor is the key before it, and the
    | first has none. They REPLACE one another rather than accumulating —
    | building the hut is the lean-to becoming a hut, and the lean-to's planks
    | are in it — which is why what is built is one stored value rather than a
    | table of them.
    |
    | Not in `bag` above, and that is deliberate rather than an oversight. A
    | recipe in `bag` becomes knowable from its ingredients, so all three stages
    | would be listed at once; ONLY THE NEXT STAGE IS EVER LISTED, because a
    | stage whose predecessor does not exist is absent rather than greyed — the
    | same rule the skill list has followed since F1. A structure is also not a
    | thing that stacks in `companion_items`.
    |
    | NO TOOL AND NO SKILL. F2's rule stands unchanged — a recipe gates on a
    | tool, never a skill — and planks already carry the handsaw's gate
    | upstream. A second gate here would tax the same work twice.
    |
    | `insights` is an optional FLOOR, and only the cabin has one. It is the
    | same 5 the cabin has always sat at: E1's rule is that the threshold never
    | moves, and it has not — it has stopped GRANTING the cabin and started
    | being the floor at which the cabin may be BUILT. The thing being taken
    | away from an established record is drawn geometry rather than the pixel
    | art the forest has, and putting it up is the reward the whole arc was
    | designed around: people value what they built.
    |
    | Copy rules as everywhere else: sentence case, one or two sentences, no
    | exclamation marks, never congratulating, `{name}` rather than the literal.
    |
    */

    'shelter' => [
        'lean-to' => [
            'label' => 'lean-to',
            'recipe' => ['planks' => 4],
            'built' => '{name} leans the planks against each other until they stay up. It is not much, and it is out of the rain.',
        ],
        'hut' => [
            'label' => 'hut',
            'recipe' => ['planks' => 8],
            'built' => '{name} walls the lean-to in. The wind has stopped finding a way through, which {name} appears to regard as a personal victory.',
        ],
        'cabin' => [
            'label' => 'cabin',
            'recipe' => ['planks' => 14],
            'insights' => 5,
            'built' => '{name} put up a cabin. There is a window in it, and {name} has already looked out of it twice.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The salvage
    |--------------------------------------------------------------------------
    |
    | An established account was given a cabin by the record, and the record no
    | longer gives one. The cabin is not taken: it COMES APART into the
    | materials it was worth, and rebuilding it becomes the first thing there
    | is to do.
    |
    | Those materials go into the CLEARING rather than into the bag, and the
    | arithmetic is why: the whole arc costs more planks than any bag in this
    | game can hold at once, so handing them over directly is impossible. A
    | heap standing in the world is the rule "the world holds the overflow"
    | doing exactly the job it was written for, using machinery that already
    | exists — a node, its standing stock, and a harvest that takes what it is
    | asked for.
    |
    | `stock` is tied to the shelter's own prices by a test rather than written
    | out twice, so retuning a stage cannot leave an established account short
    | without something saying so.
    |
    */

    'salvage' => [
        'node' => 'salvage',
        'stock' => 26,
    ],
```

- [ ] **Step 4: Author the heap as a node**

Still in `config/companion.php`, append to the `nodes` block after `trunk`:

```php
        // The cabin, in pieces. NO SKILL, and that absence is the whole rule:
        //
        //   a node with a skill  is the world — always standing, and the
        //                        record stocks it
        //   a node without one   is a heap — there only if something put it
        //                        there, and nothing restocks it
        //
        // So this is absent from the clearing for every account that never had
        // a cabin, it never grows by one unit per outcome logged, and it is
        // removed once it is drained rather than standing there forever as an
        // empty label. Removing it takes nothing from Blob — there is nothing
        // left in it — and it is the only delete path in the feature that is
        // not a stack spent on something.
        //
        // No `met` line, because there is no state in which Blob meets this
        // without being able to use it: there is no skill to be without.
        'salvage' => [
            'yields' => 'planks',
            // Eight characters, and that is a placement decision rather than a
            // stylistic one: hotspot labels are real buttons at a fixed 8px
            // font that does not scale with the svg, so every character costs
            // the same pixels at every stage size. "the salvage" is three
            // characters — about 18px — wider, which is the difference between
            // fitting in the one gap the grass tufts leave and not. The node's
            // other three lines already call it a heap.
            'label' => 'the heap',
            'took' => '{name} carries {count} planks up from the heap.',
            'empty' => '{name} turns over what is left of the heap. There is nothing in it.',
            'full' => 'There is nowhere to put them. The planks stay in the heap.',
        ],
```

- [ ] **Step 5: Run the content tests**

Run: `php artisan test --compact tests/Unit/Companion/CompanionShelterContentTest.php`
Expected: 7 passing.

Run: `php artisan test --compact --filter='CompanionContentTest'`
Expected: **two failures**, and they are the ones Task 12 exists to fix — `test_every_node_names_a_skill_and_a_material_that_exist` and `test_a_nodes_copy_follows_the_same_rules_as_the_ladders` both assume every node names a skill and has a `met` line. **Do not fix them here and do not loosen them.** Report the exact failure text; Task 12 narrows them properly, alongside the code that makes a skill-less node work.

Because this task knowingly leaves the suite red, it is the one exception to the rule below. Commit it anyway — Task 12 follows immediately and the pair is what makes sense.

**If the executor would rather not leave a red commit:** run Task 12 before committing this one and make them a single commit. That is acceptable. What is not acceptable is editing the two content assertions to make them pass without the code changes behind them.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php tests/Unit/Companion/CompanionShelterContentTest.php
git commit -m "feat(companion): author the shelter and the salvage heap"
```

---

### Task 9: `BuildShelter`

**Files:**
- Create: `app/Actions/BuildShelter.php`
- Modify: `app/Services/Companion/CompanionEconomyException.php` (a seventh factory)
- Create: `tests/Feature/Companion/BuildShelterTest.php`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

**Interfaces:**
- Consumes: `Companion::shortfallFor()` / `spend()` (Task 6), `companions.shelter` (Task 7), `config('companion.shelter')` (Task 8), `CompanionResolver::insightMoments(User): list<array{at: CarbonImmutable, kind: string}>`.
- Produces: `BuildShelter::handle(User $user, string $stage): string` — returns the stage now standing. Throws `CompanionEconomyException::notOffered($stage)` when the predecessor is not built or the floor is not met, and `::missingMaterials($stage, $missing)` when the planks are short.

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildShelter;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Putting up one stage of the shelter.
 *
 * The arc is lean-to → hut → cabin and the stages REPLACE one another: two are
 * never standing at once, and what is built only ever advances. A stage whose
 * predecessor does not exist is not refused with an explanation — it is never
 * offered in the first place — so every refusal here is a request the screen
 * does not make.
 */
class BuildShelterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function carrying(int $planks, ?string $shelter = null): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        if ($planks > 0) {
            $companion->items()->create(['item' => 'planks', 'quantity' => $planks]);
        }

        if ($shelter !== null) {
            $companion->update(['shelter' => $shelter]);
        }

        return [$user, $companion->fresh()];
    }

    /** Five concluded experiments, which is the cabin's floor exactly. */
    private function withInsights(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }
    }

    public function test_the_first_stage_goes_up_on_an_empty_clearing(): void
    {
        [$user, $companion] = $this->carrying(4);

        $this->assertSame('lean-to', app(BuildShelter::class)->handle($user, 'lean-to'));
        $this->assertSame('lean-to', $companion->fresh()->shelter);
    }

    /** It consumes exactly its price, and leaves no emptied row behind. */
    public function test_building_consumes_exactly_the_price(): void
    {
        [$user, $companion] = $this->carrying(6);

        app(BuildShelter::class)->handle($user, 'lean-to');

        $this->assertSame(2, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    public function test_a_stage_whose_predecessor_is_missing_is_refused(): void
    {
        [$user, $companion] = $this->carrying(20);

        try {
            app(BuildShelter::class)->handle($user, 'hut');
            $this->fail('the hut should need a lean-to to become');
        } catch (CompanionEconomyException) {
            // Expected. What matters is that it took nothing on the way out.
        }

        $this->assertNull($companion->fresh()->shelter);
        $this->assertSame(20, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    /** And the same stage twice is the same refusal: its predecessor is itself. */
    public function test_a_stage_that_already_stands_is_refused(): void
    {
        [$user, $companion] = $this->carrying(20, 'lean-to');

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildShelter::class)->handle($user, 'lean-to');
        } finally {
            $this->assertSame('lean-to', $companion->fresh()->shelter);
        }
    }

    public function test_the_hut_goes_up_on_a_lean_to(): void
    {
        [$user, $companion] = $this->carrying(8, 'lean-to');

        $this->assertSame('hut', app(BuildShelter::class)->handle($user, 'hut'));
        $this->assertSame('hut', $companion->fresh()->shelter);
    }

    /**
     * The floor E1 pinned, still at five and no longer granting anything. A hut
     * with four insights and fourteen planks is not a cabin.
     */
    public function test_the_cabin_needs_the_floor_as_well_as_the_hut(): void
    {
        [$user, $companion] = $this->carrying(14, 'hut');
        $this->withInsights($user, 4);

        try {
            app(BuildShelter::class)->handle($user, 'cabin');
            $this->fail('four insights is below the cabin floor');
        } catch (CompanionEconomyException) {
            // Expected.
        }

        $this->assertSame('hut', $companion->fresh()->shelter);
        $this->assertSame(14, (int) $companion->items()->where('item', 'planks')->value('quantity'));

        $this->withInsights($user, 1);

        $this->assertSame('cabin', app(BuildShelter::class)->handle($user, 'cabin'));
    }

    public function test_short_planks_refuse_and_consume_nothing(): void
    {
        [$user, $companion] = $this->carrying(3);

        try {
            app(BuildShelter::class)->handle($user, 'lean-to');
            $this->fail('three planks is not four');
        } catch (CompanionEconomyException $refusal) {
            $this->assertStringContainsString('1 planks', $refusal->getMessage());
        }

        $this->assertSame(3, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertNull($companion->fresh()->shelter);
    }

    /**
     * Building needs no skill and no tool. F2's rule from this side: a recipe
     * gates on a tool and never on a skill, and planks already carry the
     * handsaw's gate upstream, so gating here would tax the same work twice.
     */
    public function test_building_a_shelter_needs_no_skill_and_no_tool(): void
    {
        [$user, $companion] = $this->carrying(4);

        $this->assertSame(0, $companion->skills()->count());
        $this->assertSame(0, $companion->items()->where('item', 'handsaw')->count());

        $this->assertSame('lean-to', app(BuildShelter::class)->handle($user, 'lean-to'));
    }

    /** A structure is not carried, so putting one up can never overflow a bag. */
    public function test_a_shelter_never_takes_up_bag_room(): void
    {
        [$user, $companion] = $this->carrying(4);

        app(BuildShelter::class)->handle($user, 'lean-to');

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(0, $fresh->held());
        $this->assertSame(5, $fresh->capacity());
    }

    public function test_a_stage_nobody_authored_is_rejected(): void
    {
        [$user] = $this->carrying(40);

        $this->expectException(InvalidArgumentException::class);

        app(BuildShelter::class)->handle($user, 'castle');
    }

    /**
     * The logs a record has do not stand in for insights. The cabin floor reads
     * the same four insight kinds the resolver derives and nothing else.
     */
    public function test_logging_alone_never_clears_the_cabin_floor(): void
    {
        [$user, $companion] = $this->carrying(14, 'hut');

        for ($index = 0; $index < 30; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(30 - $index),
            ]);
        }

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildShelter::class)->handle($user, 'cabin');
        } finally {
            $this->assertSame('hut', $companion->fresh()->shelter);
        }
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/BuildShelterTest.php`
Expected: every case errors with `Class "App\Actions\BuildShelter" does not exist`.

- [ ] **Step 3: Add the refusal**

In `app/Services/Companion/CompanionEconomyException.php`, update the class docblock's count ("Six factories now" → "Seven") and append:

```php
    /**
     * Something was asked for that the screen never offered.
     *
     * The shelter's own refusal, and the one factory here whose message is not
     * really for a user: a stage whose predecessor is missing, or whose floor
     * the record has not reached, is ABSENT from the bag rather than listed
     * and disabled — so reaching this means a request the screen does not
     * make. It stays a refusal rather than an abort because it is still a
     * statement about the current state, and because the one thing it must not
     * do is take anything on the way out.
     */
    public static function notOffered(string $thing): self
    {
        return new self("[{$thing}] is not something Blob can put up yet.");
    }
```

- [ ] **Step 4: Write the action**

`php artisan make:class Actions/BuildShelter --no-interaction`, then:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob puts up one stage of the shelter.
 *
 * The arc is lean-to → hut → cabin, and the stages REPLACE one another: two
 * are never standing at once, because building the hut is the lean-to becoming
 * a hut and the lean-to's planks are in it. What is built is therefore one
 * stored value, and this is its only writer.
 *
 * IT ONLY EVER ADVANCES. Every stage requires the key immediately before it in
 * `config('companion.shelter')` to be what is currently standing, which makes
 * "already built" and "built out of order" the same refusal, and makes going
 * backwards unexpressible rather than merely forbidden.
 *
 * NO SKILL AND NO TOOL, and that is F2's rule rather than an omission: a recipe
 * gates on a tool and never on a skill, and planks already carry the handsaw's
 * gate upstream. A second gate here would tax the same work twice.
 *
 * NO CAPACITY CHECK, and that is not an omission either: a structure is not
 * carried, so putting one up is always net-negative on what the bag holds and
 * can never overflow it. {@see BuildItem} needs the check because sawing makes
 * three carried things out of one; this cannot.
 *
 * The cabin additionally names a FLOOR of five insights. That number has not
 * moved since E1 — it has stopped granting the cabin and started being the
 * point at which the cabin may be built, which is spec §2's first overturn and
 * the reason the whole arc exists: people value what they built.
 */
final readonly class BuildShelter
{
    public function __construct(private CompanionResolver $resolver) {}

    /**
     * @return string The stage now standing.
     *
     * @throws InvalidArgumentException when no such stage is authored.
     * @throws CompanionEconomyException when the predecessor is not standing,
     *                                   the floor is not reached, or the
     *                                   materials are short.
     */
    public function handle(User $user, string $stage): string
    {
        /** @var array<string, array<string, mixed>> $stages */
        $stages = (array) config('companion.shelter', []);

        if (! array_key_exists($stage, $stages)) {
            throw new InvalidArgumentException("[{$stage}] is not something Blob can put up.");
        }

        $order = array_keys($stages);
        $at = (int) array_search($stage, $order, true);

        // The key before it, or null for the first. The ORDER of the config is
        // the arc; a back-pointer on each entry would be a second copy of it.
        $predecessor = $at === 0 ? null : $order[$at - 1];

        /** @var array<string, int> $recipe */
        $recipe = (array) ($stages[$stage]['recipe'] ?? []);
        $floor = (int) ($stages[$stage]['insights'] ?? 0);

        return DB::transaction(function () use ($user, $stage, $predecessor, $recipe, $floor): string {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            if ($companion->shelter !== $predecessor) {
                throw CompanionEconomyException::notOffered($stage);
            }

            // Counted only when a stage actually names a floor, so nobody pays
            // four queries for a gate that does not apply to them.
            if ($floor > 0 && count($this->resolver->insightMoments($user)) < $floor) {
                throw CompanionEconomyException::notOffered($stage);
            }

            $missing = $companion->shortfallFor($recipe);

            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($stage, $missing);
            }

            $companion->spend($recipe);

            $companion->update(['shelter' => $stage]);

            return $stage;
        });
    }
}
```

- [ ] **Step 5: Register it with the vocabulary scan**

In `CompanionVocabularyTest::sourceFiles()`, after the `DropItem.php` line:

```php
            $root.'/app/Actions/BuildShelter.php',
```

- [ ] **Step 6: Run and verify**

Run: `php artisan test --compact tests/Feature/Companion/BuildShelterTest.php`
Expected: 11 passing.

Run: `php artisan test --compact --filter='Companion'` — the two `CompanionContentTest` failures from Task 8 are still there and still the only ones. Report the number.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/BuildShelter.php app/Services/Companion/CompanionEconomyException.php tests/Feature/Companion
git commit -m "feat(companion): put up one stage of the shelter"
```

---

### Task 10: What the bag says about the shelter

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php` (constructor, `forUser()` shape, a new `shelter()` method)
- Modify: `resources/js/patyourself/companion.tsx` (`BagShelterData`, `CompanionBagData.shelter`)
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: `companions.shelter` (Task 7), `config('companion.shelter')` (Task 8), `CompanionBag::knowable(array $met): list<string>` (already in the file), `CompanionResolver::insightMoments()`.
- Produces on the payload:
```
shelter: {
    built: string | null,       // the stage standing, by name
    label: string | null,       // what to call it
    offer: {                    // the ONE choosable stage, or null
        stage: string,
        label: string,
        recipe: Record<string, number>,
        buildable: boolean,
    } | null,
}
```
**`offer`, never `next`.** `next` is one of the words `test_the_payload_names_no_total_and_no_next` bans outright.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * Only the stage that is choosable now, and only once its price is in a
     * currency Blob has been shown. A brand-new account seeing "lean-to, 4
     * planks" before it has met the trunk would be a price quoted in something
     * that does not exist for it yet — the same defect the recipe list's own
     * reveal gate was built to stop, one list along.
     */
    public function test_the_shelter_is_absent_until_its_price_can_be_accounted_for(): void
    {
        $user = User::factory()->create();

        $shelter = $this->bag($user)['shelter'];

        $this->assertNull($shelter['built']);
        $this->assertNull($shelter['offer']);
    }

    /** Once planks are accountable, the first stage — and only the first. */
    public function test_only_the_stage_that_is_choosable_now_is_listed(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $offer = $this->bag($user)['shelter']['offer'];

        $this->assertSame('lean-to', $offer['stage']);
        $this->assertSame(['planks' => 4], $offer['recipe']);
        $this->assertFalse($offer['buildable']);
    }

    /** It advances with what is built, one at a time and never two. */
    public function test_the_offer_advances_as_the_shelter_does(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'lean-to']);

        $bag = $this->bag($user);

        $this->assertSame('lean-to', $bag['shelter']['built']);
        $this->assertSame('hut', $bag['shelter']['offer']['stage']);
    }

    /**
     * A stage behind a floor the record has not reached is ABSENT, not listed
     * and greyed. A price you cannot pay yet is a menu; a fact about the record
     * you cannot pay at all is a lock, and the feature answers it with silence
     * rather than naming what you are waiting for.
     */
    public function test_a_stage_behind_a_floor_the_record_has_not_reached_is_absent(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'hut']);

        $this->assertNull($this->bag($user)['shelter']['offer']);
    }

    /** And arrives, unannounced, when the record reaches it. */
    public function test_the_cabin_arrives_on_the_offer_once_the_floor_is_reached(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'hut']);

        for ($index = 0; $index < 5; $index++) {
            \App\Models\Strategy::factory()
                ->for(\App\Models\Intention::factory()->for($user))
                ->create([
                    'verdict' => \App\Models\Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }

        $this->assertSame('cabin', $this->bag($user)['shelter']['offer']['stage']);
    }

    /** Nothing is offered once the arc is finished. There is no fourth stage. */
    public function test_a_finished_shelter_offers_nothing(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'cabin']);

        $bag = $this->bag($user);

        $this->assertSame('cabin', $bag['shelter']['built']);
        $this->assertNull($bag['shelter']['offer']);
    }

    /**
     * `buildable` asks the same question BuildShelter asks before it writes
     * anything, for the same reason the recipe list does: a read that promises
     * a build the action is about to refuse is the defect F2 shipped once.
     */
    public function test_buildable_flips_when_the_planks_are_there(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $offer = fn (): array => $this->bag($user)['shelter']['offer'];

        $this->assertFalse($offer()['buildable']);

        // Exactly the price, so this pins the boundary rather than clearing it.
        $user->companion->items()->create(['item' => 'planks', 'quantity' => 4]);

        $this->assertTrue($offer()['buildable']);
    }
```

Also extend the existing payload sweep so the new keys are actually covered — replace the `foreach (['items', 'nodes', 'skills', 'recipes'] as $list)` block in `test_the_payload_names_no_total_and_no_next` with:

```php
        foreach (['items', 'nodes', 'skills', 'recipes'] as $list) {
            foreach ($bag[$list] as $row) {
                $keys = [...$keys, ...array_keys($row)];
            }
        }

        // The shelter is a shape rather than a list, and its inner keys would
        // otherwise never be swept. `offer` is named the way it is precisely
        // because `next` is on the list below.
        $keys = [...$keys, ...array_keys($bag['shelter'])];

        if ($bag['shelter']['offer'] !== null) {
            $keys = [...$keys, ...array_keys($bag['shelter']['offer'])];
        }
```

and add, before the `$bag = $this->bag($user);` line in that same test, enough setup for the offer to be present:

```php
        // Without a met trunk the shelter offer is null and its own keys never
        // enter the sweep — the one shape this phase added would go unchecked.
        // Do not delete this as unused setup.
        app(MeetNode::class)->handle($user, 'trunk');
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/CompanionBagTest.php`
Expected: the seven new cases and the widened sweep all fail on `Undefined array key "shelter"`.

- [ ] **Step 3: Add the payload**

In `app/Services/Companion/CompanionBag.php`:

```php
    public function __construct(
        private CompanionWallet $wallet,
        private CompanionResolver $resolver,
    ) {}
```

with `use App\Services\Companion\CompanionResolver;` — it is the same namespace, so no import is needed; delete this note if the file already sits in `App\Services\Companion`, which it does.

Extend the `forUser()` return shape with:

```php
     *     shelter: array{built: string|null, label: string|null, offer: array{stage: string, label: string, recipe: array<string, int>, buildable: bool}|null},
```

Add `'shelter' => $this->shelter(null, collect(), [], $user),` to the companion-less branch — or, more simply and with fewer moving parts, the literal it always produces there:

```php
                // Nothing built, and nothing offered: the offer waits on a
                // price Blob can account for, and an account that has met
                // nothing can account for nothing.
                'shelter' => ['built' => null, 'label' => null, 'offer' => null],
```

In the main branch, compute `knowable` once and share it, so the fixed point runs a single time:

```php
        $knowable = $this->knowable($met);

        return [
            'xp' => $balance,
            'capacity' => $companion->capacity(),
            'held' => $companion->held(),
            'name' => $companion->displayName(),
            'items' => $this->items($companion),
            'nodes' => $this->nodes($companion, $met, $learned),
            'skills' => $this->skills($met, $learned, $balance),
            'recipes' => $this->recipes($knowable, $held, $companion),
            'shelter' => $this->shelter($companion, $held, $knowable, $user),
        ];
```

and change `recipes()`'s signature from `array $met` to `array $knowable`, deleting its internal `$knowable = $this->knowable($met);` line. That is the only change to `recipes()` — its body is otherwise untouched.

Add the method:

```php
    /**
     * What is standing in the clearing, and the ONE stage that can be chosen
     * next.
     *
     * Only the next stage is ever listed, and a stage whose predecessor does
     * not exist is ABSENT rather than greyed — the same rule the skill list has
     * followed since F1. A player at the hut with three insights sees no
     * shelter row at all, and the feature answers that with silence: naming
     * what they are waiting for would be the app stating a plan.
     *
     * Two things withhold the offer, and they are different in kind. A price in
     * a material Blob has never been shown is a price quoted in a currency
     * nobody has seen, so the offer waits on `knowable()` exactly as a recipe
     * does. A floor on the record is not a price at all — it cannot be paid
     * from the bag — so listing the cabin at "14 planks" while the real
     * obstacle is the record would be a lie about what the thing costs.
     *
     * `offer`, not `next`. The payload-shape guard bans `next` outright, and it
     * is right to: a "next" is the first half of a checklist.
     *
     * @param  Collection<string, CompanionItem>  $held
     * @param  list<string>  $knowable
     * @return array{built: string|null, label: string|null, offer: array{stage: string, label: string, recipe: array<string, int>, buildable: bool}|null}
     */
    private function shelter(Companion $companion, Collection $held, array $knowable, User $user): array
    {
        /** @var array<string, array<string, mixed>> $stages */
        $stages = (array) config('companion.shelter', []);

        $order = array_keys($stages);
        $built = $companion->shelter;

        $standing = $built === null ? null : ($stages[$built] ?? null);

        // A stage config no longer knows still stands: nothing about Blob is
        // ever taken because an author edited a list. Nothing follows it,
        // because there is no position in the arc to follow from.
        if ($built !== null && $standing === null) {
            return ['built' => $built, 'label' => $built, 'offer' => null];
        }

        $at = $built === null ? -1 : (int) array_search($built, $order, true);
        $stage = $order[$at + 1] ?? null;

        $offer = null;

        if ($stage !== null) {
            $entry = $stages[$stage];

            /** @var array<string, int> $recipe */
            $recipe = (array) ($entry['recipe'] ?? []);
            $floor = (int) ($entry['insights'] ?? 0);

            $accountable = array_diff(array_keys($recipe), $knowable) === [];

            // Short-circuited on purpose: only a stage that names a floor pays
            // for the four queries that answer it.
            if ($accountable && ($floor === 0 || count($this->resolver->insightMoments($user)) >= $floor)) {
                $buildable = true;

                foreach ($recipe as $ingredient => $needed) {
                    if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                        $buildable = false;

                        break;
                    }
                }

                $offer = [
                    'stage' => $stage,
                    'label' => (string) $entry['label'],
                    'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                    'buildable' => $buildable,
                ];
            }
        }

        return [
            'built' => $built,
            'label' => $standing === null ? null : (string) $standing['label'],
            'offer' => $offer,
        ];
    }
```

- [ ] **Step 4: Widen the client type and the fixture**

In `resources/js/patyourself/companion.tsx`, after `BagRecipeData`:

```ts
/**
 * The shelter: what is standing, and the one stage that can be chosen now.
 *
 * `offer` is null far more often than not — before planks are accountable,
 * once the arc is finished, and whenever the record has not reached a stage's
 * floor. A null offer renders NOTHING, not a placeholder and not an
 * explanation: a stage you cannot reach is absent, the same way an unmet
 * skill is.
 */
export interface BagShelterData {
    built: string | null;
    label: string | null;
    offer: {
        stage: string;
        label: string;
        recipe: Record<string, number>;
        buildable: boolean;
    } | null;
}
```

and add to `CompanionBagData`:

```ts
    shelter: BagShelterData;
```

In `resources/js/patyourself/companion.fixture.ts`, add to the `bag()` default, after `recipes: []`:

```ts
        // Nothing built and nothing offered, which is what an untouched
        // clearing sends: the offer waits on a price Blob can account for.
        shelter: { built: null, label: null, offer: null },
```

- [ ] **Step 5: Run and verify**

```bash
php artisan test --compact tests/Feature/Companion/CompanionBagTest.php
npx vitest run resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx
npx tsc --noEmit -p tsconfig.json
```
Expected: all green; the two `CompanionContentTest` failures from Task 8 remain the only red in the PHP suite.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php resources/js/patyourself tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): carry the one choosable shelter stage in the bag"
```

---

### Task 11: The shelter's route and its row

**Files:**
- Create: `app/Http/Controllers/CompanionShelterController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/patyourself/companion-bag.tsx` (a `Shelter` section, rendered above `Build`)
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`
- Test: `tests/Feature/Companion/CompanionShelterScreenTest.php` (append), `resources/js/patyourself/companion-bag.test.tsx` (append)

**Interfaces:**
- Consumes: `BuildShelter::handle()` (Task 9), `bag.shelter` (Task 10).
- Produces: route `companion.shelter.store` (`POST companion/shelter`, body `stage`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionShelterScreenTest.php`:

```php
    /** Putting one up says so, and leaves the bag open to show it. */
    public function test_putting_up_a_stage_says_what_blob_did(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'planks', 'quantity' => 4]);

        $this->actingAs($user)
            ->post(route('companion.shelter.store'), ['stage' => 'lean-to'])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::STAY_KEY, true);

        $this->assertSame('lean-to', $companion->fresh()->shelter);
        $this->assertStringContainsString(
            'leans the planks',
            (string) session(CompanionController::SAID_KEY),
        );
    }

    /** Short planks come back as a line, not an error page, and take nothing. */
    public function test_short_planks_are_refused_without_an_error_page(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'planks', 'quantity' => 2]);

        $this->actingAs($user)
            ->post(route('companion.shelter.store'), ['stage' => 'lean-to'])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'There is not enough for a lean-to yet.');

        $this->assertNull($companion->fresh()->shelter);
        $this->assertSame(2, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    /** A stage the screen never offers is refused the same way, and silently. */
    public function test_a_stage_out_of_order_is_refused_without_an_error_page(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'planks', 'quantity' => 20]);

        $this->actingAs($user)
            ->post(route('companion.shelter.store'), ['stage' => 'cabin'])
            ->assertRedirect();

        $this->assertNull($companion->fresh()->shelter);
        $this->assertSame(20, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    public function test_a_stage_nobody_authored_is_rejected(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.shelter.store'), ['stage' => 'castle'])
            ->assertSessionHasErrors('stage');
    }
```

Append to `resources/js/patyourself/companion-bag.test.tsx`:

```tsx
    /** The one stage that can be chosen now, priced like anything else. */
    it('offers the stage that is choosable now, with its price', () => {
        open(
            bag({
                shelter: {
                    built: null,
                    label: null,
                    offer: {
                        stage: 'lean-to',
                        label: 'lean-to',
                        recipe: { planks: 4 },
                        buildable: false,
                    },
                },
            }),
        );

        expect(screen.getByText('lean-to')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: '4 planks' }),
        ).toBeDisabled();
    });

    /** No offer renders nothing at all — not a placeholder, not an explanation. */
    it('says nothing about a stage it is not offering', () => {
        open(bag({ shelter: { built: 'hut', label: 'hut', offer: null } }));

        expect(screen.queryByRole('button', { name: /planks/i })).toBeNull();
        expect(screen.queryByText(/cabin/i)).toBeNull();
    });
```

- [ ] **Step 2: Run and watch both fail**

```bash
php artisan test --compact tests/Feature/Companion/CompanionShelterScreenTest.php
npx vitest run resources/js/patyourself/companion-bag.test.tsx
```
Expected: `Route [companion.shelter.store] not defined`, and no lean-to in the rendered bag.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildShelter;
use App\Models\Companion;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Putting up one stage of the shelter.
 *
 * Posted from inside the bag and leaves it open, the same as buying a skill or
 * building an item: the point of the press is to watch the thing appear.
 *
 * Every refusal comes back as one line. A stage the screen never offered — out
 * of order, or behind a floor the record has not reached — is deliberately
 * given the SAME line as a shortfall rather than one of its own, because there
 * is no honest sentence for it: the row was never on the screen, so a message
 * explaining why would be the app naming a thing it has been careful not to
 * name.
 */
class CompanionShelterController extends Controller
{
    public function store(Request $request, BuildShelter $build): RedirectResponse
    {
        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::in(array_keys((array) config('companion.shelter', [])))],
        ]);

        $stage = (string) $validated['stage'];
        $name = Companion::nameFor($request->user());
        $label = (string) (config("companion.shelter.{$stage}.label") ?? $stage);

        try {
            $build->handle($request->user(), $stage);
        } catch (CompanionEconomyException) {
            return back()
                ->with(CompanionController::SAID_KEY, "There is not enough for a {$label} yet.")
                ->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                '{name}',
                $name,
                (string) config("companion.shelter.{$stage}.built"),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
```

- [ ] **Step 4: Add the route and register the file**

In `routes/web.php`, after the `companion/items` line:

```php
    // Putting up a stage of the shelter. Posted from inside the bag like the
    // other two, and the only writer of what is built.
    Route::post('companion/shelter', [CompanionShelterController::class, 'store'])
        ->name('companion.shelter.store');
```

with the import, and in `CompanionVocabularyTest::sourceFiles()` after the `CompanionItemController` line:

```php
            $root.'/app/Http/Controllers/CompanionShelterController.php',
```

Then: `php artisan wayfinder:generate --with-form`

- [ ] **Step 5: Render the row**

In `resources/js/patyourself/companion-bag.tsx`, import `store as shelterRoute from '@/routes/companion/shelter'` and add `<Shelter shelter={bag.shelter} />` immediately before `<Build recipes={bag.recipes} />`:

```tsx
/**
 * The shelter: the one stage that can be chosen now, and nothing else.
 *
 * A null offer renders NOTHING — no placeholder, no explanation, no greyed
 * row. A stage whose predecessor does not exist, or whose floor the record has
 * not reached, is absent the same way an unmet skill is, and the feature
 * answers it with silence rather than naming what is being waited for.
 *
 * What IS standing is not drawn here either. That is the clearing's job, and
 * the bag is about what can be chosen.
 */
function Shelter({ shelter }: { shelter: CompanionBagData['shelter'] }) {
    if (shelter.offer === null) {
        return null;
    }

    const offer = shelter.offer;

    return (
        <>
            <p className="c-baggrp">Put up</p>
            <ul className="c-bagrows">
                <li className="c-bagrow">
                    <span>{offer.label}</span>
                    <Form
                        {...shelterRoute.form()}
                        options={{ preserveScroll: true }}
                        className="c-bagbuy"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="stage"
                                    value={offer.stage}
                                />
                                <button
                                    type="submit"
                                    className="pixel-button"
                                    disabled={processing || !offer.buildable}
                                >
                                    {Object.entries(offer.recipe)
                                        .map(
                                            ([item, count]) =>
                                                `${count} ${item}`,
                                        )
                                        .join(', ')}
                                </button>
                            </>
                        )}
                    </Form>
                </li>
            </ul>
        </>
    );
}
```

- [ ] **Step 6: Run and verify**

```bash
php artisan test --compact tests/Feature/Companion/CompanionShelterScreenTest.php
npx vitest run resources/js/patyourself
npx eslint resources/js/patyourself/companion-bag.tsx
npx prettier --check resources/js/patyourself/companion-bag.tsx
```
Expected: green. Both runs of the `never shows what has not happened` guard still pass.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers routes/web.php resources/js tests
git commit -m "feat(companion): offer the next shelter stage from the bag"
```

---

### Task 12: A node without a skill is a heap

**Files:**
- Modify: `app/Listeners/StockCompanionNodes.php:52-71`
- Modify: `app/Services/Companion/CompanionBag.php` (`nodes()`, `worldBeforeAnythingHappened()`)
- Modify: `app/Actions/HarvestNode.php`
- Modify: `app/Http/Controllers/CompanionNodeController.php` (`hasSkill`)
- Modify: `resources/js/patyourself/companion.tsx` (`BagNodeData.skill`)
- Modify: `tests/Unit/Companion/CompanionContentTest.php` (two assertions narrowed)
- Test: `tests/Feature/Companion/HarvestNodeTest.php`, `tests/Feature/Companion/CompanionBagTest.php`, `tests/Feature/Companion/StockCompanionNodesTest.php`

**Interfaces:**
- Consumes: the `salvage` node authored in Task 8.
- Produces: the rule
```
a node with a skill  is the world:  always standing, and the record stocks it
a node without one   is a heap:     there only if something put it there, and nothing restocks it
```
and `BagNodeData.skill: string | null` on the payload.

**This task also repairs the two `CompanionContentTest` failures Task 8 knowingly left.**

- [ ] **Step 1: Write the failing tests**

To `tests/Feature/Companion/HarvestNodeTest.php`:

```php
    /**
     * A node that names no skill needs none. There is nothing to have learned:
     * a heap in the clearing is not a thing you learn to use, it is a thing
     * somebody put there.
     */
    public function test_a_node_with_no_skill_is_harvestable_without_one(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'salvage', 'available' => 9]);

        $this->assertSame(0, $companion->skills()->count());
        $this->assertSame(5, app(HarvestNode::class)->handle($user, 'salvage'));
        $this->assertSame(5, $this->held($companion->fresh()->load('items'), 'planks'));
    }

    /**
     * And a heap drained to nothing is REMOVED rather than left at zero.
     *
     * A drained node would otherwise stand in the clearing forever as an empty
     * label. This is the one delete path in the feature that is not a stack
     * spent on something, and it takes nothing from Blob — there is nothing
     * left in it to take.
     */
    public function test_a_drained_heap_is_removed_rather_than_left_at_zero(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'salvage', 'available' => 3]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 2]);

        app(HarvestNode::class)->handle($user, 'salvage');

        $this->assertSame(0, $companion->nodes()->where('node', 'salvage')->count());

        // And removing it changed nothing else about Blob.
        $this->assertSame(3, $this->held($companion->fresh()->load('items'), 'planks'));
        $this->assertSame(2, $this->standing($companion->fresh(), 'reeds'));
    }

    /** A node that still has a skill is never removed, however empty it gets. */
    public function test_a_node_of_the_world_is_never_removed_when_it_empties(): void
    {
        [$user, $companion] = $this->clearing(2);

        app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(1, $companion->nodes()->where('node', 'reeds')->count());
        $this->assertSame(0, $this->standing($companion->fresh(), 'reeds'));
    }
```

To `tests/Feature/Companion/StockCompanionNodesTest.php`:

```php
    /**
     * A heap does not grow back. It is what is left of something, not a thing
     * the world produces, and stocking it would rebuild a cabin out of nothing
     * one outcome at a time.
     */
    public function test_a_node_with_no_skill_is_never_stocked_by_the_record(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'salvage', 'available' => 4]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->logOnce($user);

        $this->assertSame(4, (int) $companion->nodes()->where('node', 'salvage')->value('available'));
        $this->assertSame(1, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }
```

**Read the existing `StockCompanionNodesTest` first** and reuse whatever helper it already has for logging an outcome; if it has none named `logOnce`, copy the one from `CompanionRulingsTest:45-61` verbatim rather than inventing a different one.

To `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * A heap is absent from the clearing until something puts it there.
     *
     * Every node of the WORLD is listed whether or not Blob has met it, because
     * a node standing there is a thing that is there rather than a preview. A
     * heap is not in the world until it exists, so listing it would put a
     * salvage pile in front of every account that never had a cabin.
     */
    public function test_a_heap_is_absent_until_something_puts_it_there(): void
    {
        $user = User::factory()->create();

        $this->assertNotContains('salvage', array_column($this->bag($user)['nodes'], 'node'));

        $user->companion()->firstOrCreate([])->nodes()->create([
            'node' => 'salvage',
            'available' => 26,
        ]);

        $heap = collect($this->bag($user)['nodes'])->firstWhere('node', 'salvage');

        $this->assertSame(26, $heap['available']);
        $this->assertNull($heap['skill']);
        // Nothing to learn, so nothing is withheld: `known` is what gates the
        // take control, and a heap is usable the moment it is there.
        $this->assertTrue($heap['known']);
        $this->assertTrue($heap['met']);
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact --filter='Companion'`
Expected: the four new cases fail (undefined index `skill`, a stocked heap, a heap listed for everyone), on top of the two `CompanionContentTest` failures carried from Task 8.

- [ ] **Step 3: The listener skips a heap**

In `app/Listeners/StockCompanionNodes.php`, replace the loop body's guard:

```php
        foreach ($nodes as $name => $node) {
            $skill = (string) ($node['skill'] ?? '');

            // A node that names no skill is a heap rather than part of the
            // world: something put it there and nothing restocks it. Skipping
            // it is not an optimisation — stocking it would rebuild the thing
            // it is the remains of, one outcome at a time.
            if ($skill === '' || ! $learned->has($skill)) {
                continue;
            }
```

and update the `@var` above it to `array<string, array{skill?: string, yields: string, label: string}>`.

- [ ] **Step 4: The bag lists a heap only where it stands**

In `CompanionBag::nodes()`:

```php
        foreach ($authored as $name => $entry) {
            $skill = (string) ($entry['skill'] ?? '');
            $standingHere = $standing->get($name);

            // A heap is absent until something puts it there. Every node of
            // the WORLD is listed whether met or not — a thing standing in the
            // clearing is not a preview of anything — but a heap is not part
            // of the world, so listing one would put a salvage pile in front
            // of an account that never had a cabin.
            if ($skill === '' && $standingHere === null) {
                continue;
            }

            $listed[] = [
                'node' => $name,
                'label' => (string) $entry['label'],
                'available' => (int) ($standingHere?->available ?? 0),
                // Null rather than '' so the client has one falsy case to
                // test, the same choice `recipes[].tool` already made.
                'skill' => $skill === '' ? null : $skill,
                'met' => $standingHere !== null || in_array($name, $met, true),
                // Nothing to learn is nothing withheld.
                'known' => $skill === '' || in_array($skill, $learned, true),
            ];
        }
```

and in `worldBeforeAnythingHappened()`, which runs for an account with no companion row at all and therefore has no heaps to show:

```php
        foreach ($authored as $name => $entry) {
            // A heap needs a row to exist, and this branch is the case where
            // there is no companion row at all — so there can be none.
            if (! isset($entry['skill'])) {
                continue;
            }
```

Update both `@return` shapes to `skill: string|null`, and the one on `forUser()`.

- [ ] **Step 5: Harvesting a heap, and removing a drained one**

In `app/Actions/HarvestNode.php`:

```php
        $skill = (string) ($authored[$node]['skill'] ?? '');
```

and inside the transaction, the skill check becomes conditional and the drain gets its delete:

```php
            // A node that names no skill needs none: a heap is not a thing you
            // learn to use, it is a thing somebody put there.
            if ($skill !== '' && $companion->skills()->where('name', $skill)->doesntExist()) {
                throw CompanionEconomyException::skillNotLearned($node, $skill);
            }
```

```php
            $standing->decrement('available', $moved);

            // A drained heap is removed rather than left standing as an empty
            // label forever. Nothing is taken by it — there is nothing left in
            // it to take — and it is the only delete path in this feature that
            // is not a stack spent on something. A node of the world is never
            // removed, however empty it gets: the record refills it.
            if ($skill === '' && $standing->fresh()->available === 0) {
                $standing->delete();
            }
```

Update the `@var` on `$authored` to make `skill` optional, and add a line to the class docblock:

```
 * A node that names no SKILL is a heap rather than part of the world: it needs
 * nothing learned, nothing restocks it, and it is removed once it is drained.
```

- [ ] **Step 6: The controller too**

In `app/Http/Controllers/CompanionNodeController.php`, the `store()` branch and the helper:

```php
        if ($this->hasSkill($user, (string) ($entry['skill'] ?? ''))) {
```

```php
    /** A node that names no skill needs none — there is nothing to have learned. */
    private function hasSkill(User $user, string $skill): bool
    {
        if ($skill === '') {
            return true;
        }

        return Companion::query()
            ->where('user_id', $user->id)
            ->whereHas('skills', fn ($query) => $query->where('name', $skill))
            ->exists();
    }
```

- [ ] **Step 7: Narrow the two content assertions**

In `tests/Unit/Companion/CompanionContentTest.php`:

```php
    /**
     * A node names a material that exists, and — if it names a skill at all —
     * one that exists.
     *
     * The skill is optional now, exactly as the tool already was. A node with a
     * skill is part of the world: always standing, and the record stocks it. A
     * node without one is a heap: there only if something put it there, and
     * nothing restocks it. Requiring a skill would make the second kind
     * unexpressible.
     */
    public function test_every_node_names_a_material_that_exists_and_any_skill_it_claims(): void
    {
        $config = $this->config();

        $this->assertNotEmpty($config['nodes']);

        foreach ($config['nodes'] as $name => $node) {
            if (isset($node['skill'])) {
                $this->assertArrayHasKey(
                    $node['skill'],
                    $config['skills'],
                    "{$name} needs a skill that does not exist",
                );
            }

            $this->assertArrayHasKey(
                $node['yields'],
                $config['bag'],
                "{$name} yields an item that does not exist",
            );
            $this->assertSame(
                'material',
                $config['bag'][$node['yields']]['category'],
                "{$name} yields something that is not a material",
            );
        }
    }
```

and in `test_a_nodes_copy_follows_the_same_rules_as_the_ladders`, make `met` conditional the same way `blunt` already is. Replace the loop's opening with:

```php
        foreach ($this->config()['nodes'] as $name => $node) {
            foreach (['met', 'blunt', 'took', 'empty', 'full'] as $line) {
                // Each line is required exactly when the state it describes is
                // reachable. `blunt` only for a node that asks for a tool;
                // `met` only for a node that asks for a skill, since meeting a
                // node you cannot use has no meaning where there is nothing to
                // have learned.
                if ($line === 'blunt' && ! array_key_exists('tool', $node)) {
                    continue;
                }

                if ($line === 'met' && ! array_key_exists('skill', $node)) {
                    continue;
                }

                $this->assertArrayHasKey($line, $node, "{$name} has no {$line} line");
                $this->assertStringNotContainsString('!', $node[$line], "{$name}.{$line} exclaims");
                $this->assertStringNotContainsString('Blob', $node[$line], "{$name}.{$line} hardcodes the name");
            }
```

and guard the final `met` assertion at the bottom of that loop:

```php
            if (isset($node['skill'])) {
                $this->assertStringContainsString('{name}', $node['met'], "{$name}.met never names the companion");
            }
```

Leave `test_skills_and_nodes_point_at_each_other` alone — it walks skills, and no skill points at a heap.

- [ ] **Step 8: The client type**

In `resources/js/patyourself/companion.tsx`:

```ts
/** A node Blob has met, and what is standing at it. */
export interface BagNodeData {
    node: string;
    label: string;
    available: number;
    /**
     * The skill that unlocks it, or null for a heap — something that was put
     * in the clearing rather than something Blob learns to use. Null is the
     * one falsy case, the same choice `BagRecipeData.tool` made.
     */
    skill: string | null;
    /** Whether Blob has walked over and looked at it. */
    met: boolean;
    /** Whether the skill that unlocks it has been bought, or there is none. */
    known: boolean;
}
```

- [ ] **Step 9: Run everything**

```bash
php artisan test --compact --filter='Companion'
npx vitest run resources/js/patyourself
npx tsc --noEmit -p tsconfig.json
```
Expected: **fully green** — the two `CompanionContentTest` failures Task 8 left are now repaired by the code that earns the repair. Then `php artisan test --compact`; report the number.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources/js tests
git commit -m "feat(companion): a node without a skill is a heap, not the world"
```

---

### Task 13: The cabin comes apart

**Files:**
- Create: `app/Actions/SalvageTheCabin.php`
- Create: `database/migrations/2026_09_18_000002_salvage_granted_cabins.php`
- Create: `tests/Feature/Companion/SalvageTheCabinTest.php`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

**Interfaces:**
- Consumes: `companions.salvaged_at` (Task 7), `config('companion.salvage')` (Task 8), the heap rules (Task 12), `CompanionResolver::insightMoments()`.
- Produces: `SalvageTheCabin::handle(): int` — how many companions were given a heap. Safe to run repeatedly.

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\SalvageTheCabin;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cabin the record used to grant, handed back as what it was worth.
 *
 * `insights: 5` has stopped granting a cabin and started being the floor at
 * which one may be built. An established account must not simply lose what it
 * had — so the cabin COMES APART, into a heap standing in its own clearing,
 * and putting it back up becomes the first thing there is to do.
 *
 * The materials go into the world rather than into the bag because they cannot
 * fit in a bag: the whole arc costs more planks than any container this game
 * can build will hold at once. A heap is the rule "the world holds the
 * overflow" doing exactly the job it was written for.
 */
class SalvageTheCabinTest extends TestCase
{
    use RefreshDatabase;

    private function withInsights(User $user, int $count): User
    {
        for ($index = 0; $index < $count; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }

        return $user;
    }

    private function heap(User $user): ?int
    {
        $available = $user->companion()->first()?->nodes()
            ->where('node', 'salvage')
            ->value('available');

        return $available === null ? null : (int) $available;
    }

    public function test_a_record_past_the_floor_gets_its_cabin_back_as_a_heap(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        $this->assertSame(1, app(SalvageTheCabin::class)->handle());

        $this->assertSame(26, $this->heap($user));
        $this->assertNotNull($user->companion()->first()->salvaged_at);
    }

    /**
     * Below the floor, nothing happens — and specifically, no companion row is
     * written. Looking at an account must not create one: the row is the
     * chosen half of Blob, and nothing has been chosen.
     */
    public function test_a_record_below_the_floor_is_untouched(): void
    {
        $user = $this->withInsights(User::factory()->create(), 4);

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());

        $this->assertNull($user->companion()->first());
        $this->assertDatabaseCount('companions', 0);
    }

    /** Running it twice grants one cabin, not two. */
    public function test_it_is_idempotent(): void
    {
        $user = $this->withInsights(User::factory()->create(), 6);

        app(SalvageTheCabin::class)->handle();

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());
        $this->assertSame(26, $this->heap($user));
    }

    /**
     * The case the mark exists for, and the reason it cannot be derived: once
     * the heap is drained it is DELETED, so its own absence cannot tell a
     * converted account from an unconverted one.
     */
    public function test_a_drained_heap_is_not_granted_a_second_time(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        app(SalvageTheCabin::class)->handle();

        $user->companion()->first()->nodes()->where('node', 'salvage')->delete();

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());
        $this->assertNull($this->heap($user));
    }

    /** A heap already standing is left exactly as it is, not topped back up. */
    public function test_a_partly_drawn_heap_is_left_alone(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        app(SalvageTheCabin::class)->handle();

        $user->companion()->first()->nodes()->where('node', 'salvage')->update(['available' => 9]);

        app(SalvageTheCabin::class)->handle();

        $this->assertSame(9, $this->heap($user));
    }

    /** It converts every qualifying account and only those. */
    public function test_it_converts_every_qualifying_account_and_no_others(): void
    {
        $deep = $this->withInsights(User::factory()->create(), 7);
        $alsoDeep = $this->withInsights(User::factory()->create(), 5);
        $shallow = $this->withInsights(User::factory()->create(), 2);

        $this->assertSame(2, app(SalvageTheCabin::class)->handle());

        $this->assertSame(26, $this->heap($deep));
        $this->assertSame(26, $this->heap($alsoDeep));
        $this->assertNull($this->heap($shallow));
    }

    /** Nothing about what Blob already had is disturbed by the conversion. */
    public function test_the_conversion_takes_nothing(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);
        $companion = $user->companion()->firstOrCreate([]);
        $companion->update(['name' => 'Pebble', 'xp_spent' => 40]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 7]);

        app(SalvageTheCabin::class)->handle();

        $fresh = $companion->fresh();

        $this->assertSame('Pebble', $fresh->name);
        $this->assertSame(40, $fresh->xp_spent);
        $this->assertSame(3, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame(7, (int) $fresh->nodes()->where('node', 'reeds')->value('available'));
        $this->assertSame(1, $fresh->skills()->count());
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/Companion/SalvageTheCabinTest.php`
Expected: `Class "App\Actions\SalvageTheCabin" does not exist`.

- [ ] **Step 3: Write the action**

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * An established account's cabin, handed back as what it was worth.
 *
 * `insights: 5` used to grant a cabin and now marks the floor at which one may
 * be BUILT. The threshold has not moved — it has changed what it does — and an
 * account that passed it must not simply lose what it had. So the cabin comes
 * apart into its materials, and putting it back up becomes the first thing
 * there is to do, which is the experience the whole arc was designed around.
 *
 * The materials go into the CLEARING, not into the bag, and the arithmetic
 * forces it: the whole arc costs more planks than any bag this game can build
 * will hold at once, so there is no way to hand them over directly. A heap
 * standing in the world is the rule "the world holds the overflow" doing
 * exactly what it was written for, out of machinery that already exists.
 *
 * SAFE TO RUN AGAIN. `salvaged_at` is why, and it is why that column exists: a
 * drained heap is deleted, so the heap's own presence cannot tell a converted
 * account from an unconverted one, and an unmarked account would be handed a
 * second cabin's worth of planks every time this ran.
 *
 * An account below the floor is left completely alone, including its ABSENCE
 * of a companion row: the row is the chosen half of Blob, and nothing has been
 * chosen. The insight count is therefore checked before anything is written.
 */
final readonly class SalvageTheCabin
{
    public function __construct(private CompanionResolver $resolver) {}

    /**
     * @return int How many companions were given a heap.
     */
    public function handle(): int
    {
        $floor = (int) config('companion.shelter.cabin.insights', 5);
        $node = (string) config('companion.salvage.node', 'salvage');
        $stock = (int) config('companion.salvage.stock', 0);

        $given = 0;

        // Chunked rather than loaded whole: this runs once, at deploy, over
        // every account there is.
        User::query()->chunkById(100, function (Collection $users) use ($floor, $node, $stock, &$given): void {
            foreach ($users as $user) {
                // Checked BEFORE anything is written, so an account below the
                // floor does not even gain a companion row.
                if (count($this->resolver->insightMoments($user)) < $floor) {
                    continue;
                }

                /** @var Companion $companion */
                $companion = $user->companion()->firstOrCreate([]);

                if ($companion->salvaged_at !== null) {
                    continue;
                }

                // firstOrCreate, never updateOrCreate: a heap already standing
                // is left exactly as it is rather than topped back up.
                $companion->nodes()->firstOrCreate(['node' => $node], ['available' => $stock]);

                // forceFill, because `salvaged_at` is deliberately not
                // fillable — it is written once, here, and by nothing a
                // request can reach.
                $companion->forceFill(['salvaged_at' => now()])->save();

                $given++;
            }
        });

        return $given;
    }
}
```

- [ ] **Step 4: Write the migration that calls it**

`database/migrations/2026_09_18_000002_salvage_granted_cabins.php`:

```php
<?php

use App\Actions\SalvageTheCabin;
use Illuminate\Database\Migrations\Migration;

/**
 * Hands every established account its cabin back as a heap of planks.
 *
 * The logic lives in {@see SalvageTheCabin} rather than here, and that is the
 * whole point of the split: what counts as an insight is four derivations, one
 * of which reads JSON out of `intentions.metadata`, and a hand-written SQL
 * copy of that would drift from the resolver the moment either changed — on
 * MySQL in production, against a suite that runs on SQLite. Calling the action
 * means the conversion is tested the way everything else is, with factories
 * and assertions, in `SalvageTheCabinTest`.
 *
 * Safe on a fresh database: there are no users at migration time, so the loop
 * body never runs.
 *
 * There is no `down()`. Nothing is taken back — the heap is Blob's, and this
 * whole feature refuses to remove anything from Blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SalvageTheCabin::class)->handle();
    }

    public function down(): void
    {
        // Deliberately empty. Nothing about Blob is ever taken away, and a
        // heap the player may already have drawn down is not something to
        // reverse.
    }
};
```

- [ ] **Step 5: Register it with the vocabulary scan**

In `CompanionVocabularyTest::sourceFiles()`, after `BuildShelter.php`:

```php
            $root.'/app/Actions/SalvageTheCabin.php',
```

- [ ] **Step 6: Run and verify**

Run: `php artisan test --compact tests/Feature/Companion/SalvageTheCabinTest.php`
Expected: 7 passing.

Run: `php artisan test --compact`
Expected: green throughout. Every test in the suite now runs this migration during `RefreshDatabase`; if the number of *errors* rises, it is this migration running against a schema state it did not expect — report the exact error rather than working around it.

Then migrate the local dev database, which was backed up in Task 7:

```bash
php artisan migrate
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/SalvageTheCabin.php database/migrations tests/Feature/Companion
git commit -m "feat(companion): hand a granted cabin back as a heap of planks"
```

---

### Task 14: The forest is always the world

**Files:**
- Modify: `config/companion.php` (the `scenes` block)
- Modify: `app/Services/Companion/CompanionState.php:128-169` (the `scene()` docblock)
- Modify: `resources/js/patyourself/companion-room.tsx` (an `inside` prop, a `shelter` prop, the interior per stage)
- Modify: `resources/js/patyourself/scenes.ts` (the cabin entry's comment)
- Test: `tests/Feature/Companion/CompanionSceneTest.php`, `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `bag.shelter.built` (Task 10).
- Produces: `CompanionRoom` gains two optional props — `inside?: boolean` (default `false`) and `shelter?: string | null` (default `null`). `indoors` becomes `inside || scene.name === 'cabin'`, and the stage drawn is `shelter ?? 'cabin'`. Both defaults are chosen so that **every existing test in `companion-room.test.tsx` passes unedited**: they drive the interior with `scene: 'cabin'` and expect today's drawing.

- [ ] **Step 1: Write the failing PHP tests**

In `tests/Feature/Companion/CompanionSceneTest.php`, rewrite the four cases that assert a derived `'cabin'`:

```php
    /**
     * The correction at the heart of this phase: THE FOREST IS ALWAYS THE
     * WORLD, and it is never replaced.
     *
     * `insights: 5` has not moved. It has stopped granting a cabin and started
     * being the floor at which one may be built, which is why an established
     * record now stands in its own clearing rather than being teleported
     * indoors — where, before this, it could not see the world it had been
     * gathering from at all.
     */
    public function test_an_established_record_still_stands_in_the_clearing(): void
    {
        $user = $this->userWithInsights(5);

        $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);
    }

    /**
     * The regression this threshold exists to prevent: an established record
     * must not lose sight of anything it earned. What it earned is the room
     * objects; the cabin they stand in is now something it builds.
     */
    public function test_an_established_record_keeps_every_object_it_earned(): void
    {
        $user = $this->userWithInsights(9);
        $state = $this->resolve($user)->toArray();

        $this->assertSame('forest', $state['scene']);
        $this->assertContains('bookshelf', $state['room_objects']);
    }
```

and in `test_resolving_the_scene_writes_nothing_back_to_the_record`, change the one expectation from `'cabin'` to `'forest'`.

Then repair the two override tests so they still discriminate — **this is the point of the task, not housekeeping.** With the record now deriving `'forest'`, an override test that overrides *to* `'forest'` passes under a mutant that ignores the override entirely:

```php
    /**
     * The normal path, and the one the override must not disturb: with nothing
     * set the record decides. `COMPANION_SCENE=` in a .env file reads back as
     * an empty string rather than as absent, so both have to mean the same
     * thing or half the ways of turning the override off would not.
     *
     * The override is set to something the record does NOT derive, on purpose.
     * Overriding to the value the record already produces would pass under an
     * implementation that ignored the override completely — a test that is
     * green and means nothing, which this file has come close to twice.
     */
    public function test_an_absent_or_empty_override_leaves_the_record_deciding(): void
    {
        $user = $this->userWithInsights(5);

        config(['companion.scene_override' => 'cabin']);
        $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);

        config(['companion.scene_override' => null]);
        $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);

        config(['companion.scene_override' => '']);
        $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);
    }

    /**
     * Why the override exists, restated: the interior is no longer a place the
     * record puts Blob, so without this there is no way to put the cabin's own
     * drawing on screen short of building one.
     */
    public function test_the_override_wins_over_the_scene_the_record_derives(): void
    {
        $user = $this->userWithInsights(5);

        config(['companion.scene_override' => 'cabin']);

        $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);
    }
```

- [ ] **Step 2: Write the failing Vitest cases**

Append to `resources/js/patyourself/companion-room.test.tsx`, using whatever `room(...)` helper the file already defines (read it first — around line 180 — and match its signature exactly):

```tsx
    /**
     * Where Blob IS and what Blob has BUILT are independent facts. You can
     * stand outside a cabin or sit inside a lean-to, which is precisely what
     * the arc's own sentence about this phase conflated.
     */
    it('goes inside without the scene changing underneath it', () => {
        const markup = room({ scene: 'forest' }, { inside: true, shelter: 'cabin' });

        expect(markup).toContain('data-scene="forest"');
        expect(markup).toContain('data-interior="cabin"');
    });

    /** Each stage is a different interior, and only ever one of them. */
    it('draws the interior the stage that is standing has', () => {
        const leanTo = room({ scene: 'forest' }, { inside: true, shelter: 'lean-to' });
        const hut = room({ scene: 'forest' }, { inside: true, shelter: 'hut' });
        const cabin = room({ scene: 'forest' }, { inside: true, shelter: 'cabin' });

        expect(leanTo).toContain('data-interior="lean-to"');
        expect(hut).toContain('data-interior="hut"');
        expect(cabin).toContain('data-interior="cabin"');

        // A lean-to is open on one side, so it has no wall and no window; a
        // hut has walls and a gap that has been boarded over; only the cabin
        // has a window to look out of.
        expect(leanTo).not.toContain('data-window');
        expect(hut).toContain('data-window="shuttered"');
        expect(cabin).toContain('data-window="open"');
    });

    /** Outside, no interior is drawn at all — never two at once. */
    it('draws no interior while Blob is outside', () => {
        const markup = room({ scene: 'forest' }, { shelter: 'cabin' });

        expect(markup).not.toContain('data-interior');
        expect(markup).toContain('scene-backdrop');
    });
```

The existing `room()` helper takes one argument. Widen it to take an optional second bag of props and spread it onto `<CompanionRoom>`; every existing call passes one argument and is unaffected.

- [ ] **Step 3: Run both and watch them fail**

```bash
php artisan test --compact --filter='CompanionSceneTest'
npx vitest run resources/js/patyourself/companion-room.test.tsx
```

- [ ] **Step 4: Take the cabin out of the scene list**

In `config/companion.php`, replace the `scenes` array and extend its comment block:

```php
    | THE FOREST IS ALWAYS THE WORLD. It is never replaced, which is why there
    | is one entry here rather than two. `cabin` used to sit at `insights: 5`
    | and swap the clearing out from under an established record; the shelter
    | replaced that with an object standing IN the clearing that upgrades in
    | place, and where Blob is standing became a transient thing the client
    | holds rather than something the record decides.
    |
    | THE NUMBER 5 HAS NOT MOVED. It is in `shelter.cabin.insights` now, doing
    | a different job: it has stopped granting a cabin and started being the
    | floor at which one may be built. E1's rule is that a threshold never
    | relocates, and it has not — what it does changed, not where it sits.
    |
    */

    'scenes' => [
        ['name' => 'forest', 'trigger' => 'logs', 'at' => 0],
    ],
```

Leave the walk in `CompanionState::scene()` exactly as it is — one entry is not a reason to delete a mechanism that still serves the override and that a later phase may give a second outdoor place to. Update its docblock's second paragraph to say so:

```
     * Walked in full rather than stopped at the first unsatisfied entry: a
     * scene threshold is independent of the others. There is one entry today —
     * the forest is always the world, and the interior is no longer a scene the
     * record puts Blob in but a view of something Blob built — so the walk has
     * nothing to choose between. It stays because the override still needs
     * somewhere to be overriding, and because a later outdoor place would slot
     * straight into it.
```

- [ ] **Step 5: Parameterise the interior**

In `resources/js/patyourself/companion-room.tsx`, add the two props, and replace the `indoors` derivation and the whole `{indoors && (...)}` block:

```tsx
export function CompanionRoom({
    companion,
    animation,
    frame,
    hour = new Date().getHours(),
    className = '',
    onPoke,
    inside = false,
    shelter = null,
}: {
    companion: CompanionData;
    animation: AnimationName;
    frame: number;
    hour?: number;
    className?: string;
    onPoke?: () => void;
    /**
     * Whether Blob is looking at the inside of what it built.
     *
     * Transient and never stored: where you are looking is not something you
     * own, and it resets to outside on load the way a modal does. Where Blob
     * IS and what Blob has BUILT are independent facts — you can stand outside
     * a cabin or sit inside a lean-to.
     */
    inside?: boolean;
    /**
     * Which stage is standing, when there is one. Null draws no interior.
     *
     * `'cabin'` is the default the scene override falls back to, so
     * `COMPANION_SCENE=cabin` still puts today's drawing on screen with
     * nothing built — which is the one way left to look at it.
     */
    shelter?: string | null;
}) {
    if (!companion.features.includes('blob')) {
        return null;
    }

    const scene = sceneFor(companion.scene);

    // The scene override is the second way in, and the only one left that does
    // not require building something: `COMPANION_SCENE=cabin` still draws the
    // interior, which is what that development affordance exists for.
    const indoors = inside || scene.name === 'cabin';
    const stage = shelter ?? 'cabin';
```

Add the attribute to the `<svg>` — beside `data-scene`, so a test can tell the two apart:

```tsx
            data-scene={scene.name}
            {...(indoors ? { 'data-interior': stage } : {})}
```

and replace the indoor branch:

```tsx
            {indoors && (
                <>
                    {/* A lean-to is open on every side but one, so what is
                        behind Blob is the clearing rather than a wall. Drawn
                        from the scene's own base colour rather than a literal,
                        so it agrees with whatever the outside is. */}
                    {stage === 'lean-to' ? (
                        <rect
                            x={ROOM.x}
                            y={ROOM.y}
                            width={ROOM.w}
                            height={FLOOR - ROOM.y}
                            fill={scene.base}
                        />
                    ) : (
                        <rect
                            x={ROOM.x}
                            y={ROOM.y}
                            width={ROOM.w}
                            height={FLOOR - ROOM.y}
                            fill={palette.wall}
                        />
                    )}

                    {/* The floor is the same in all three: a floor is a floor,
                        and it is what stops Blob standing on nothing. */}
                    <rect
                        x={ROOM.x}
                        y={FLOOR}
                        width={ROOM.w}
                        height={ROOM.y + ROOM.h - FLOOR}
                        fill={palette.wall}
                    />
                    <rect
                        x={ROOM.x}
                        y={FLOOR}
                        width={ROOM.w}
                        height={ROOM.y + ROOM.h - FLOOR}
                        fill={INK}
                        opacity={0.12}
                    />
                    <path
                        d={`M ${ROOM.x} ${FLOOR} H ${ROOM.x + ROOM.w}`}
                        stroke={INK}
                        strokeOpacity={0.35}
                        strokeWidth={1}
                    />

                    {/* The lean-to's own structure: one sloping beam on one
                        post, and nothing else. It reads as shelter because it
                        is over Blob's head, not because it encloses anything. */}
                    {stage === 'lean-to' && (
                        <g className="room-shelter room-shelter--lean-to">
                            <polygon
                                points={`${ROOM.x},-32 44,4 44,11 ${ROOM.x},-25`}
                                fill="#7A5B3A"
                            />
                            <rect
                                x={41}
                                y={8}
                                width={3}
                                height={FLOOR - 8}
                                fill="#6B5039"
                            />
                        </g>
                    )}

                    {/* A hut has the gap a window will one day be, boarded
                        over. Same geometry as the cabin's window, so the cabin
                        reads as the same building with the boards taken off. */}
                    {stage === 'hut' && (
                        <g data-window="shuttered">
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill={palette.wall}
                            />
                            <path
                                d={`M 16 -12 H 58 M 16 -2 H 58`}
                                stroke={INK}
                                strokeOpacity={0.3}
                                strokeWidth={3}
                            />
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill="none"
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={2}
                            />
                        </g>
                    )}

                    {/* The cabin's window, exactly as it has always been
                        drawn. This is the destination, not a thing being
                        replaced. */}
                    {stage === 'cabin' && (
                        <g data-window="open">
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill={palette.window}
                            />
                            <path
                                d={`M 37 -22 V 8 M 16 -7 H 58`}
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={1.5}
                            />
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill="none"
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={2}
                            />
                        </g>
                    )}

                    {/* Whatever the ladder handed over, drawn in whatever
                        exists. A bookshelf under a lean-to is funny rather
                        than wrong, and withholding it would mean the ladder
                        could hand over something invisible. */}
                    {objects.map(([name, spec]) => (
                        <g key={name} data-room-object={name}>
                            {spec.render(palette)}
                        </g>
                    ))}
                </>
            )}
```

Update the file's opening docblock: it says "one of the two `scenes.ts` knows about" and describes the cabin as a scene. Rewrite that paragraph to say the forest is the world, the interior is a view of what has been built, and which one is drawn is `inside`'s answer rather than the record's.

- [ ] **Step 6: Correct the comment in `scenes.ts`**

The `cabin` entry's comment still calls it a scene the record reaches. Replace it:

```ts
    // Not a place the record puts Blob any more: the forest is always the
    // world, and the interior is a view of something Blob BUILT. This entry
    // survives because `COMPANION_SCENE=cabin` still needs a name to resolve
    // to, and because `CompanionRoom` reads `base` from whatever scene it was
    // handed. The wall, floor and window are drawn by `CompanionRoom` itself,
    // per stage.
```

- [ ] **Step 7: Run everything**

```bash
php artisan test --compact --filter='CompanionSceneTest|CompanionScreenTest'
npx vitest run resources/js/patyourself/companion-room.test.tsx resources/js/patyourself/scenes.test.ts
```
Expected: green, and **every pre-existing case in `companion-room.test.tsx` passes unedited** — including `expect(inside).toMatch(/, at home$/)`, which still holds because `scene: 'cabin'` still reads as indoors. If any needed editing, stop and report which: the defaults were chosen precisely so none would.

Then the full suites, and report both numbers.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php app/Services/Companion/CompanionState.php resources/js tests
git commit -m "feat(companion): the forest is the world, the shelter is what you built"
```

---

## Batch 2 review checkpoint

**Stop here.** Report to the human partner:

- Both suite numbers, against Batch 1's.
- **Finding 6, plainly:** 26 planks gets an established account to the hut and leaves it 8 short of the cabin, because the cabin is consumed in one act and the bag must hold 14 — which costs two crates, 8 planks, that spec §6's arithmetic did not count. They approved 26 as written; this is the consequence, and `config('companion.salvage.stock')` is one number if they want 34 instead. The content test ties the number to the shelter's own prices, so changing it means changing the test's expectation too.
- **The interim state:** an established account can now see its clearing and its heap but cannot go indoors until Batch 3.
- **Finding 5, for the record:** the shelter is not a `bag` category, which departs from arc §5's taxonomy table. The reasoning is in `config/companion.php`'s own comment and goes into `docs/BLOB.md` in Batch 3.

---

# BATCH 3 — The clearing

The structure object, the *go inside* control, the interior wired up. The first batch anyone can look at, and the one whose placement needs eyes rather than arithmetic.

---

### Task 15: Going inside

**Files:**
- Modify: `resources/js/pages/companion.tsx` (`CompanionPage`, `RoomCard`)
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `bag.shelter.built` / `.label` (Task 10), `CompanionRoom`'s `inside` and `shelter` props (Task 14).
- Produces: `inside` state held in `CompanionPage`, passed down to `RoomCard`. Nothing is stored and nothing is posted — where you are looking is not something you own.

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/pages/companion.test.tsx`, inside the existing `describe('Companion screen', …)`:

```tsx
    /**
     * Where Blob IS and what Blob has BUILT are independent facts, and the
     * control that moves between them only exists once there is something to
     * go into. A "go inside" button with nothing built would be a door to a
     * room that does not exist — which is the preview this feature refuses.
     */
    it('offers no way inside until something has been built', () => {
        render(<CompanionPage bag={bag()} companion={companion({ scene: 'forest' })} />);

        expect(screen.queryByRole('button', { name: /go inside/i })).toBeNull();
    });

    it('goes inside what was built, and comes back out again', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: { built: 'lean-to', label: 'lean-to', offer: null },
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByRole('img')).toHaveAttribute('data-scene', 'forest');
        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        // The scene underneath is unchanged: the forest is always the world.
        expect(screen.getByRole('img')).toHaveAttribute('data-scene', 'forest');
        expect(screen.getByRole('img')).toHaveAttribute('data-interior', 'lean-to');

        fireEvent.click(screen.getByRole('button', { name: /go outside/i }));

        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');
    });

    /**
     * Inside is where you are looking, not something you own. A fresh render
     * is the reload, and it always starts outside — the way a modal does.
     */
    it('starts outside every time, because inside is not stored', () => {
        const props = {
            bag: bag({ shelter: { built: 'cabin', label: 'cabin', offer: null } }),
            companion: companion({ scene: 'forest' }),
        };

        const first = render(<CompanionPage {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));
        expect(screen.getByRole('img')).toHaveAttribute('data-interior', 'cabin');

        first.unmount();
        render(<CompanionPage {...props} />);

        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');
    });

    /** Nothing grows indoors: the clearing's own things are not reachable there. */
    it('draws no clearing while Blob is inside', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: { built: 'hut', label: 'hut', offer: null },
                    nodes: [
                        {
                            node: 'reeds',
                            label: 'the reeds',
                            available: 4,
                            skill: 'gather-fibre',
                            met: true,
                            known: true,
                        },
                    ],
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByRole('button', { name: /the reeds/i })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        expect(screen.queryByRole('button', { name: /the reeds/i })).toBeNull();
    });

    /** The place bar names where Blob actually is, not where the record says. */
    it('names the place Blob is standing in', () => {
        render(
            <CompanionPage
                bag={bag({ shelter: { built: 'hut', label: 'hut', offer: null } })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByText('the forest')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        expect(screen.getByText('the hut')).toBeInTheDocument();
        expect(screen.queryByText('the forest')).toBeNull();
    });
```

- [ ] **Step 2: Run and watch them fail**

Run: `npx vitest run resources/js/pages/companion.test.tsx`
Expected: no `go inside` button anywhere.

- [ ] **Step 3: Hold the state on the page**

In `resources/js/pages/companion.tsx`, inside `CompanionPage`, beside `bagOpen`:

```tsx
    // Where Blob is looking, and nothing more. NOT STORED and never posted:
    // inside/outside is a view, not a thing you own, and it resets on load the
    // way the bag does. What Blob has BUILT is the stored half, and it lives
    // on the bag payload.
    const [inside, setInside] = useState(false);
```

Pass it into `RoomCard`:

```tsx
                <RoomCard
                    companion={companion}
                    animation={animation}
                    frame={frame}
                    hour={hour}
                    now={now}
                    remark={remark}
                    bag={bag}
                    said={bagOpen ? null : said}
                    inside={inside}
                    onReact={react}
                    onOpenBag={() => setBagOpen(true)}
                    onTouchNode={touchNode}
                    onToggleInside={() => setInside((was) => !was)}
                />
```

- [ ] **Step 4: Use it in `RoomCard`**

Add `inside: boolean` and `onToggleInside: () => void` to `RoomCard`'s props and destructuring, then:

The place bar's first element becomes:

```tsx
            <div className="c-place">
                <b>
                    <CompanionGlyph kind="body" size={14} />
                    {/* Where Blob actually is. The record decides the world;
                        a press decides which side of the wall Blob is on, and
                        the bar says the one that is true right now. */}
                    the{' '}
                    {inside && bag.shelter.label !== null
                        ? bag.shelter.label
                        : sceneFor(companion.scene).name}
                </b>
```

Careful: the existing markup renders `the {name}` as one text node so `getByText('the forest')` matches. Keep it that way — `the{' '}` plus the expression produces the same single accessible string.

Pass both new props to `CompanionRoom`:

```tsx
                <CompanionRoom
                    companion={companion}
                    animation={animation}
                    frame={frame}
                    hour={hour}
                    className="c-scene"
                    onPoke={() => onReact('notice')}
                    inside={inside}
                    shelter={bag.shelter.built}
                />
```

Wrap the node-hotspot map so it only renders outdoors:

```tsx
                {!inside &&
                    sceneFor(companion.scene).nodes.map((spec) => {
                        const node = bag.nodes.find(
                            (candidate) => candidate.node === spec.node,
                        );

                        if (node === undefined) {
                            return null;
                        }

                        return (
                            <NodeSpot
                                key={spec.node}
                                label={node.label}
                                available={node.available}
                                known={node.known}
                                at={roomOffset(spec.at[0], spec.at[1])}
                                onClick={() => onTouchNode(spec.node)}
                            />
                        );
                    })}
```

And add the control to the plinth, after the Poke button and before the Bag button:

```tsx
                {/* Only once there is something to go into. A door to a room
                    that has not been built would be a preview, and this
                    feature shows what has happened.

                    A real button rather than a shape in the picture, the same
                    rule the node hotspots follow: everything reachable in this
                    frame has to be reachable from a keyboard. */}
                {bag.shelter.built !== null && (
                    <button
                        type="button"
                        className="pixel-button"
                        onClick={onToggleInside}
                    >
                        {inside ? 'Go outside' : 'Go inside'}
                    </button>
                )}
```

- [ ] **Step 5: Run and verify**

```bash
npx vitest run resources/js/pages/companion.test.tsx resources/js/patyourself
npx eslint resources/js/pages/companion.tsx
npx prettier --check resources/js/pages/companion.tsx
```
Expected: green, including the existing `draws no clearing in the cabin` case and **both** runs of the `never shows what has not happened` guard.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/companion.tsx resources/js/pages/companion.test.tsx
git commit -m "feat(companion): go inside what Blob built, and come back out"
```

---

### Task 16: The shelter and the heap, standing in the clearing

**Files:**
- Modify: `resources/js/patyourself/scenes.ts` (`NodeSpec` for the heap; a `shelter` coordinate on `SceneSpec`)
- Modify: `resources/js/pages/companion.tsx` (a `ShelterSpot`)
- Modify: `resources/css/patyourself.css` (one rule, **by hand**)
- Test: `resources/js/patyourself/scenes.test.ts`, `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `bag.shelter.built` / `.label`, `bag.nodes` including the heap (Task 12).
- Produces: `SceneSpec.shelter?: readonly [number, number]` — where the structure object stands, in the room's own coordinates. `SCENES.forest.nodes` gains `{ node: 'salvage', at: [20, 62] }`.

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/patyourself/scenes.test.ts`:

```ts
    /**
     * The heap has a place to stand even though most clearings never have one.
     * `scenes.ts` knows WHERE things are; whether a given clearing has one is
     * the server's answer, and the page skips any spec the payload does not
     * carry.
     */
    it('knows where the heap stands, whether or not one is there', () => {
        expect(
            SCENES.forest.nodes.map((node) => node.node).sort(),
        ).toEqual(['deadfall', 'reeds', 'salvage', 'trunk']);
    });

    /** And where the shelter stands, which is outdoors and nowhere else. */
    it('gives the forest somewhere to put a shelter, and the cabin none', () => {
        expect(SCENES.forest.shelter).toBeDefined();
        expect(SCENES.cabin.shelter).toBeUndefined();
    });

    /**
     * No two things in the clearing share a point. This cannot prove they do
     * not OVERLAP — jsdom has no layout engine and the labels are sized in
     * fixed pixels — but it does catch the one mistake arithmetic can catch,
     * which is two objects authored at the same place.
     */
    it('stands everything in the clearing somewhere different', () => {
        const points = [
            ...SCENES.forest.nodes.map((node) => `${node.at[0]},${node.at[1]}`),
            `${SCENES.forest.shelter?.[0]},${SCENES.forest.shelter?.[1]}`,
        ];

        expect(new Set(points).size).toBe(points.length);
    });
```

Append to `resources/js/pages/companion.test.tsx`:

```tsx
    /** What is standing there is clickable, and going inside is what it does. */
    it('stands the shelter in the clearing and lets Blob go into it', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: { built: 'cabin', label: 'cabin', offer: null },
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        const shelter = screen.getByRole('button', { name: /^cabin$/i });

        expect(shelter).toHaveClass('c-shelter');

        fireEvent.click(shelter);

        expect(screen.getByRole('img')).toHaveAttribute('data-interior', 'cabin');
    });

    /** Nothing built, nothing standing. Not an outline, not a footprint. */
    it('stands nothing in the clearing before anything is built', () => {
        render(<CompanionPage bag={bag()} companion={companion({ scene: 'forest' })} />);

        expect(document.querySelector('.c-shelter')).toBeNull();
    });

    /**
     * The heap is a hotspot like any other, and it is there only because the
     * payload says it is — the page draws a spec only where the server sent a
     * node to match it.
     */
    it('stands the heap in the clearing only when there is one', () => {
        const withoutHeap = render(
            <CompanionPage bag={bag()} companion={companion({ scene: 'forest' })} />,
        );

        expect(screen.queryByRole('button', { name: /the heap/i })).toBeNull();

        withoutHeap.unmount();

        render(
            <CompanionPage
                bag={bag({
                    nodes: [
                        {
                            node: 'salvage',
                            label: 'the heap',
                            available: 26,
                            skill: null,
                            met: true,
                            known: true,
                        },
                    ],
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByRole('button', { name: /the heap/i })).toBeInTheDocument();
    });
```

- [ ] **Step 2: Place them, and write the arithmetic down**

In `resources/js/patyourself/scenes.ts`, add to `SceneSpec`:

```ts
    /**
     * Where a structure stands, when one has been built. Absent indoors.
     *
     * Placement only, exactly as `nodes` is: WHETHER anything is standing
     * there, and which stage it is, both come from the server.
     */
    shelter?: readonly [number, number];
```

and in `SCENES.forest`, append to `nodes` and add `shelter`:

```ts
            // The heap: the cabin an established record used to be given,
            // in pieces. It stands in the one gap the grass leaves.
            //
            // The arithmetic, because BLOB.md trap 4 is that geometry cannot
            // judge a visual and this coordinate is therefore a STARTING
            // POINT that has to be looked at:
            //   - Hotspot labels are real buttons at a fixed 8px font that
            //     does NOT scale with the svg, so a box is the same pixel
            //     size at every stage width and takes up more ROOM UNITS the
            //     smaller the stage gets.
            //   - "THE HEAP" is 8 characters: roughly 48px of glyphs plus
            //     12px of padding and 2px of border, so about 62px wide and
            //     16px tall.
            //   - The three grass tufts occupy x −70..−38, −26..6 and 36..68
            //     below y=52, which leaves exactly one gap at x 6..36. At a
            //     400px stage 62px is 22 room units, so a box centred at
            //     x=20 spans 9..31 and sits inside that gap; at 300px it is
            //     30 units and spans 5..35, which still clears both tufts.
            //   - y=62 is below Blob's feet (FLOOR is 52) and above the
            //     room's own bottom edge at 76.
            { node: 'salvage', at: [20, 62] },
        ],
        // Back and to the right, against the treeline: a building belongs
        // behind the things you pick up rather than in front of them.
        //
        // Same caveat and same arithmetic. "LEAN-TO" is the longest of the
        // three stage labels at 7 characters — about 56px, or 20 room units
        // at a 400px stage — so a box centred here spans x 34..54 and stays
        // inside the room's right edge at 72. The reeds sit at [44, 36], 46
        // units below, against box heights of 6 units at 400px and 8 at
        // 300px: the two never come within 38 units of each other.
        shelter: [44, -10],
    },
```

- [ ] **Step 3: Draw it**

In `resources/js/pages/companion.tsx`, inside `RoomCard`'s `c-stage`, after the node map:

```tsx
                {/* What Blob built, standing where it was built. One at a
                    time and never two: the stages replace one another, so
                    there is only ever one thing here.

                    A real button over the picture rather than a shape inside
                    the svg, the same rule the node hotspots follow. */}
                {!inside && bag.shelter.built !== null && shelterAt !== undefined && (
                    <ShelterSpot
                        label={bag.shelter.label ?? bag.shelter.built}
                        at={roomOffset(shelterAt[0], shelterAt[1])}
                        onClick={onToggleInside}
                    />
                )}
```

with, above the `return` in `RoomCard`:

```tsx
    const shelterAt = sceneFor(companion.scene).shelter;
```

and the component beside `NodeSpot`:

```tsx
/**
 * What Blob built, standing in the clearing.
 *
 * It says what it is and nothing else — no stage number, no "1 of 3", no hint
 * of what it might become. A cabin is a cabin; that it used to be a lean-to is
 * in the record panel, where history belongs.
 *
 * Clicking it goes inside, which is the same thing the plinth's own control
 * does. Two ways in rather than one, for the same reason Poke is both the
 * scene's own tap and a button: the obvious gesture should work, and it must
 * not be the only one that does.
 */
function ShelterSpot({
    label,
    at,
    onClick,
}: {
    label: string;
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-shelter')}
            style={at}
            onClick={onClick}
        >
            {label}
        </button>
    );
}
```

- [ ] **Step 4: One CSS rule, by hand**

In `resources/css/patyourself.css`, immediately after the `.c-node.is-known{...}` line, in exactly the surrounding style. **Do not run prettier on this file.**

```css
.c-shelter{border-color:#E9B96B;background:rgba(15,12,9,.78);color:#FFD9A8;}
```

- [ ] **Step 5: Run and verify**

```bash
npx vitest run resources/js
npx eslint resources/js/pages/companion.tsx resources/js/patyourself/scenes.ts
npx prettier --check resources/js/pages/companion.tsx resources/js/patyourself/scenes.ts
php artisan test --compact --filter='Companion'
```
Expected: green throughout, both guard runs included.

- [ ] **Step 6: Commit**

```bash
git add resources/js resources/css/patyourself.css
git commit -m "feat(companion): stand the shelter and the heap in the clearing"
```

---

### Task 17: Walk the economy as a player

**Files:**
- Create: `tests/Feature/Companion/CompanionEconomyWalkTest.php`

**Interfaces:**
- Consumes: every action in the feature. **Nothing in this test may write to `companion_items`, `companion_nodes`, `companion_skills` or `companions.shelter` directly**, and nothing may override `companion.capacity` or any price. That restriction is the whole value of the task.

**Why this exists.** F2 was verified mechanism by mechanism and never once played end to end, and both of its worst findings — a `buildable` that promised a build the action refused, and a full bag of timber with no exit — lived in exactly that gap. This test plays from an empty record to a finished cabin on shipped config, so the next such gap fails here rather than on someone's screen.

- [ ] **Step 1: Write it**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Actions\BuildShelter;
use App\Actions\HarvestNode;
use App\Actions\LearnSkill;
use App\Actions\LogAction;
use App\Actions\MeetNode;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole economy, played from an empty record to a finished cabin, on the
 * content that actually ships.
 *
 * NOTHING HERE WRITES TO THE COMPANION'S TABLES DIRECTLY and nothing overrides
 * a price or a capacity. Every unit of every material is logged for, gathered
 * and built with, through the same actions a press on the screen reaches. That
 * restriction is the entire value of the test: F2 was verified mechanism by
 * mechanism and never once played, and both of its worst findings lived in the
 * gap that left — a `buildable` that promised a build the action refused, and a
 * bag of timber with no way out of it.
 *
 * What it pins, beyond "it can be done at all":
 *
 *   - The cabin is consumed in ONE act of fourteen planks, so the bag must
 *     hold fourteen. The base bag holds five. TWO CONTAINERS ARE THEREFORE
 *     REQUIRED before a cabin is reachable, and this walk builds them out of
 *     gathered fibre and deadfall rather than assuming them.
 *   - Sawing is net +2 on a bag, so every plank run has to be sequenced
 *     against what is already in there.
 *   - A failed outcome pays exactly what a completed one pays, which is why
 *     the record below is deliberately a mixture.
 */
class CompanionEconomyWalkTest extends TestCase
{
    use RefreshDatabase;

    private Action $action;

    private int $occasion = 0;

    /**
     * One loop with one action, so that logging many outcomes does not mean
     * creating many loops — which would quietly test a different thing, since
     * breadth is exactly what the taper exists not to reward.
     */
    private function aLoopToLog(User $user): void
    {
        $loop = Intention::factory()->for($user)->create();

        $this->action = Action::factory()
            ->for($loop)
            ->for(Strategy::factory()->for($loop))
            ->create();
    }

    /** `$days` days of the record, `$each` outcomes on each of them. */
    private function log(User $user, int $days, int $each): void
    {
        for ($day = 0; $day < $days; $day++) {
            for ($index = 0; $index < $each; $index++) {
                $occurrence = Occurrence::factory()->for($this->action)->create([
                    'scheduled_for' => now()->subMinutes(++$this->occasion),
                ]);

                app(LogAction::class)->handle($user, $this->action, [
                    // Deliberately mixed. A failure advances Blob exactly as
                    // far as a completion, and a walk that only ever logged
                    // successes would not be exercising the thing this whole
                    // feature is built on.
                    'outcome' => $index % 2 === 0
                        ? ActionLog::OUTCOME_COMPLETED
                        : ActionLog::OUTCOME_FAILED,
                ], $occurrence);
            }

            $this->travel(1)->days();
        }
    }

    /** Five concluded experiments: the cabin's floor, and nothing above it. */
    private function reachTheCabinFloor(User $user): void
    {
        for ($index = 0; $index < 5; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }
    }

    private function held(User $user, string $item): int
    {
        return (int) $user->companion()->first()?->items()
            ->where('item', $item)
            ->value('quantity');
    }

    private function capacity(User $user): int
    {
        return $user->companion()->first()->fresh()->load('items')->capacity();
    }

    public function test_an_empty_record_can_reach_a_finished_cabin(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->aLoopToLog($user);

        // ---- The record pays for the skills -------------------------------
        //
        // Twelve days of four outcomes each. The taper pays 3 + 2 + 1 + 1 on a
        // day, so this is 12 x 7 = 84 xp — enough for three 20 xp skills with
        // room to spare, and it takes twelve days rather than one because the
        // taper is what stops breadth out-earning depth.
        $this->log($user, 12, 4);

        $this->assertGreaterThanOrEqual(60, app(CompanionBag::class)->forUser($user)['xp']);

        // Meeting a node is what puts its skill on the list. Nothing is
        // buyable before it has been seen.
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');
        app(MeetNode::class)->handle($user, 'trunk');

        app(LearnSkill::class)->handle($user, 'gather-fibre');
        app(LearnSkill::class)->handle($user, 'gather-wood');
        app(LearnSkill::class)->handle($user, 'chop-wood');

        // ---- The record stocks the world ----------------------------------
        //
        // And only from here: stock accrues from the moment a skill is learned,
        // so the twelve days above put nothing in the clearing. Thirty more
        // outcomes is thirty units at each of the three nodes, comfortably
        // above the eleven timber, ten fibre and six deadfall the walk needs.
        $this->log($user, 10, 3);

        // ---- Rope, then the axe -------------------------------------------
        //
        // Capacity is five. Every step below is sequenced against it, and
        // nothing overrides it.
        app(HarvestNode::class)->handle($user, 'reeds', 3);
        app(BuildItem::class)->handle($user, 'rope');

        app(HarvestNode::class)->handle($user, 'deadfall', 2);
        app(BuildItem::class)->handle($user, 'axe');

        $this->assertSame(1, $this->held($user, 'axe'));
        $this->assertSame(0, $user->companion()->first()->fresh()->load('items')->held());

        // ---- Two containers, which the cabin makes compulsory --------------
        app(HarvestNode::class)->handle($user, 'reeds', 4);
        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(10, $this->capacity($user));

        app(HarvestNode::class)->handle($user, 'deadfall', 4);
        app(BuildItem::class)->handle($user, 'barrow');

        $this->assertSame(15, $this->capacity($user));

        // ---- The handsaw, which needs the trunk, which needs the axe ------
        app(HarvestNode::class)->handle($user, 'reeds', 3);
        app(BuildItem::class)->handle($user, 'rope');

        app(HarvestNode::class)->handle($user, 'trunk', 2);
        app(BuildItem::class)->handle($user, 'handsaw');

        $this->assertSame(1, $this->held($user, 'handsaw'));

        // ---- The lean-to: two timber, sawn twice, is six planks ------------
        app(HarvestNode::class)->handle($user, 'trunk', 2);
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(6, $this->held($user, 'planks'));

        app(BuildShelter::class)->handle($user, 'lean-to');

        $this->assertSame('lean-to', $user->companion()->first()->fresh()->shelter);
        $this->assertSame(2, $this->held($user, 'planks'));

        // ---- The hut: three more timber is nine more planks ---------------
        app(HarvestNode::class)->handle($user, 'trunk', 3);
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(11, $this->held($user, 'planks'));

        app(BuildShelter::class)->handle($user, 'hut');

        $this->assertSame('hut', $user->companion()->first()->fresh()->shelter);

        // ---- The cabin: fourteen planks held at once, in a bag of fifteen --
        //
        // This is the step the base bag cannot do, and the reason two
        // containers are not optional.
        app(HarvestNode::class)->handle($user, 'trunk', 4);

        for ($saw = 0; $saw < 4; $saw++) {
            app(BuildItem::class)->handle($user, 'planks');
        }

        $this->assertSame(15, $this->held($user, 'planks'));

        $this->reachTheCabinFloor($user);

        app(BuildShelter::class)->handle($user, 'cabin');

        $companion = $user->companion()->first()->fresh();

        $this->assertSame('cabin', $companion->shelter);
        $this->assertSame(1, $this->held($user, 'planks'));

        // Nothing was taken along the way that was not spent on something:
        // both tools are still on the belt and both containers are still
        // holding the bag open.
        $this->assertSame(1, $this->held($user, 'axe'));
        $this->assertSame(1, $this->held($user, 'handsaw'));
        $this->assertSame(15, $this->capacity($user));
    }

    /**
     * The one claim the walk above cannot make on its own: that the base bag
     * really is too small, so the two containers were necessary rather than
     * incidental.
     *
     * Stated as a test because it is a fact about the CONTENT that a later
     * tuning pass could change without noticing — and if it ever stops being
     * true, the walk above would still pass while meaning something else.
     */
    public function test_the_cabin_cannot_be_held_by_the_bag_alone(): void
    {
        $cabin = (int) array_sum(config('companion.shelter.cabin.recipe'));

        $this->assertGreaterThan((int) config('companion.capacity.base'), $cabin);
    }

    /**
     * And the screen agrees with the actions at the end of it: the bag says
     * the cabin is standing, offers nothing after it, and has stopped
     * offering the stages that are already up.
     */
    public function test_the_bag_agrees_with_what_was_built(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->aLoopToLog($user);
        $this->log($user, 2, 1);

        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        /** @var Companion $companion */
        $companion = $user->companion()->first();
        $companion->update(['shelter' => 'cabin']);

        $shelter = app(CompanionBag::class)->forUser($user)['shelter'];

        $this->assertSame('cabin', $shelter['built']);
        $this->assertNull($shelter['offer']);
    }
}
```

**Note on the last case:** it writes `shelter` directly, which the rest of the file forbids. That is deliberate and confined — it is asserting what the *bag says* about an end state, not how the end state was reached, and reaching it honestly would mean repeating the whole walk. Keep the comment above it explaining that.

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Companion/CompanionEconomyWalkTest.php`

**If it fails, do not adjust the numbers until you understand why.** This test is the phase's only end-to-end evidence and the most likely place for a real finding. Three things to check before touching the walk:

1. **An unexpected refusal from `BuildItem`** at a plank step means the capacity arithmetic in the comments is wrong — report the actual `held` / `capacity` at that point rather than adding a container.
2. **`LearnSkill` refusing** means the XP arithmetic is off; report the actual balance from `CompanionBag::forUser()` rather than logging more days.
3. **A harvest returning fewer units than asked** means node stock accrued differently than the comments claim; report the standing stock.

Any of the three is a finding worth carrying to the batch review, not a number to nudge.

- [ ] **Step 3: Run the full suite and commit**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add tests/Feature/Companion/CompanionEconomyWalkTest.php
git commit -m "test(companion): play the economy from an empty record to a cabin"
```

---

### Task 18: Look at it

**Files:**
- Temporary, deleted at the end: a throwaway test that dumps the rendered screen, and `public/clearing.html`.

**Interfaces:** none. This task produces screenshots and a judgement, not code.

**Why it is a task.** `docs/BLOB.md` trap 4: class-name assertions and geometry maths cannot judge a visual — the shoes shipped into the bottom-left corner with 340 tests green. F2 then shipped a hotspot collision the same way. Task 16's coordinates are computed from measured label boxes and **nobody has looked at them**, and F3 adds a fifth object to a clearing the human partner has already flagged as the sorest spot in the layout.

- [ ] **Step 1: Build the assets**

Run: `npm run build`
Expected: `public/build/manifest.json` is written. Without it the page throws `Unable to locate file in Vite manifest`.

- [ ] **Step 2: Dump the screen**

Create `tests/Feature/Companion/DumpTheClearingTest.php` — **temporary, deleted in Step 6**:

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Temporary. Renders the clearing to a file so a person can look at it. */
class DumpTheClearingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 6; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(10 - $index),
            ]);
        }

        $companion = $user->companion()->firstOrCreate([]);
        $companion->update(['shelter' => 'lean-to']);

        foreach (['reeds' => 'gather-fibre', 'deadfall' => 'gather-wood', 'trunk' => 'chop-wood'] as $node => $skill) {
            $companion->skills()->create(['name' => $skill, 'learned_at' => now()]);
            $companion->nodes()->create(['node' => $node, 'available' => 7]);
        }

        $companion->nodes()->create(['node' => 'salvage', 'available' => 26]);

        file_put_contents(
            public_path('clearing.html'),
            $this->actingAs($user)->get(route('companion'))->getContent(),
        );

        $this->assertFileExists(public_path('clearing.html'));
    }
}
```

Run: `php artisan test --compact tests/Feature/Companion/DumpTheClearingTest.php`

**This is a verification script, which CLAUDE.md discourages** — the exception is explicit: no test can judge whether two labels overlap, and this exists to put the pixels where a person can see them. It is deleted in Step 6 and never committed.

- [ ] **Step 3: Serve it**

Herd serves the main checkout, never a worktree, so:

```bash
php -S 127.0.0.1:8899 -t public
```
(in the background; the page is at `http://127.0.0.1:8899/clearing.html`)

- [ ] **Step 4: Look at it, at two widths**

Open it in Chrome. Take a screenshot at a normal desktop width, then resize to **390px** — the width `scenes.ts` records as where the labels are already larger than the room — and take a second.

What to judge, in order:

1. **Does any label overlap another?** Five objects now: the fallen branches, the reeds, the fallen trunk, the heap, and the lean-to. The trunk/deadfall pair is the one F2 already had to compute around.
2. **Does the heap's label land on a moving grass tuft?**
3. **Does the shelter read as standing behind the things you pick up**, or as floating?
4. **Is anything clipped by the room's edges?**

Blob's frames freeze under browser automation (`document.hidden` stops the clock), so `data-frame` reads 0 — that is expected and is not a fault. Trust `data-animation` and `data-scene`.

- [ ] **Step 5: Send both screenshots to the human partner**

With a short note naming what is and is not clear, and — if anything overlaps — the specific coordinate you would change and by how much. **Do not change a coordinate on your own judgement of a screenshot at this point:** the human partner has said they will check the clearing themselves, and the trunk's existing `[-24, 48]` is already an unreviewed computed placement. Propose; let them decide.

- [ ] **Step 6: Clean up**

```bash
rm tests/Feature/Companion/DumpTheClearingTest.php public/clearing.html
git status --short
```
Expected: nothing from this task left in the tree. If `public/build` changed, that is `npm run build` output — check whether the repo tracks it (`git check-ignore public/build`) and leave it however the repo already has it.

---

### Task 19: `docs/BLOB.md`, on its own

**Files:**
- Modify: `docs/BLOB.md`

**Interfaces:** none. Its own commit, not folded into a batch, so the documentation fix is reviewable apart from the behaviour — the same treatment F2 §11 gave it.

**Why it matters more than it looks.** The file's own second paragraph says *"Where this file and a spec disagree, this file is right."* A reference that claims precedence and is stale is worse than no reference, and F3 makes six of its sections wrong.

- [ ] **Step 1: Make each section true**

**§2, the rules table.** The `Nothing regresses` row now needs the overturn beside it. Add to that cell, or as a paragraph under the table:

> **The one exception, and its exact shape.** A player may destroy a material they are carrying. That is not this rule breaking: every prohibition in it names something *the system does to you* — decay, expiry, upkeep, removal for inactivity — and a player tipping out timber is the same category as spending fibre on a basket. The line that holds instead is that **destruction is always player-initiated and never automatic.** Nothing may drop, decay, expire or discard on the player's behalf, for any reason, including a full bag. `CompanionRulingsTest::test_nothing_is_dropped_on_the_players_behalf` is the guard.

**§3, "From record to drawing".** "Five tables hold the chosen half" is still true, but `companions` now holds four things rather than two. Update the sentence naming what is on it: the name, `xp_spent`, **what has been built**, and **whether a granted cabin was already handed back**. Note that where Blob is *looking* is deliberately not among them.

**§5, "Where Blob stands".** This section is the most wrong. The table's `cabin | insights: 5` row has to go, and the paragraph beginning *"The arc is `forest → lean-to → hut → cabin`"* needs rewriting to say what actually happened:

> The forest is always the world and is never replaced. What Blob built is an **object standing in the clearing** that upgrades in place — lean-to, then hut, then cabin, one at a time and never two — and going inside it is a view, held by the client and reset on load, rather than a scene the record puts Blob in. Where Blob **is** and what Blob has **built** are independent facts: you can stand outside a cabin or sit inside a lean-to.
>
> `insights: 5` has not moved. It has stopped *granting* a cabin and started being the *floor at which one may be built*. E1's rule is that a threshold never relocates, and it has not — what it does changed, not where it sits.

**§8, "The economy".** Add the shelter to the chain, and F3's own one-line rule beside F2's:

```
a node   gates on  skill + tool
a recipe gates on  tool, never skill

a node with a skill  is the world:  always standing, and the record stocks it
a node without one   is a heap:     there only if something put it there, and nothing restocks it
```

and a short subsection on the two inventory choices — take less than a bagful, and tip a stack out — naming what they close.

**§9, the file map.** Add `BuildShelter.php`, `DropItem.php`, `SalvageTheCabin.php`, `CompanionItemController.php`, `CompanionShelterController.php`, and `Companion.php`'s new `shortfallFor()` / `spend()`.

**§10, rulings that must not be reopened.** Four rows:

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **Destruction is always player-initiated** | Every prohibition in "nothing regresses" names something the system does to you. A player spending, or tipping out, is the thing arc §2 celebrates. | The one rule the whole feature rests on, broken in the one place that looks like a convenience. |
| **A node without a skill is a heap, not the world** | One property drives three behaviours: it is absent until placed, the record never stocks it, and it needs nothing learned. Stocking one would rebuild a cabin out of nothing, one outcome at a time. | A salvage pile in front of an account that never had a cabin, or a heap that regrows forever. |
| **The shelter is not a `bag` category** | This departs from arc §5's taxonomy table, deliberately. A recipe in `bag` becomes knowable from its ingredients, so all three stages would list at once — and only the next stage is ever listed. A structure also does not stack in `companion_items`. | The build list becomes a checklist of three, which is the one thing the arc forbids. |
| **The salvage is 26 planks, and 26 is not self-sufficient** | 4 + 8 + 14 is what the arc costs, but the cabin is consumed in one act of fourteen and the bag holds five plus five per container — so two crates, eight more planks, stand between the heap and the cabin. An established account reaches the hut and then has to play the economy for the rest. That is consistent with the cabin being something you build rather than something you are given; it is *not* what spec §6's own sentence claims. | An established account is told it can rebuild what it had and finds it cannot without gathering. |

**§12, "What is not done".** Delete the bullet beginning *"A full bag of timber has no exit"* — F3 closed it, with partial harvest and drop — and say so:

> - ~~A full bag of timber has no exit.~~ **Closed by F3**: a harvest can take less than a bagful, and a carried stack can be tipped out. Both are the player's act; nothing discards on their behalf.

Also update *"F3 (the shelter) and F4 (depth) are what remain"* to name only F4, and add the inherited constraint F3 did not solve:

> - **Node hotspot labels do not scale with the stage.** A fixed 8px font, so below roughly a 300px stage the labels are larger than the room. F3 added a fifth object to the clearing, which makes it urgent; it is a label-sizing problem and was deliberately left out of scope.

- [ ] **Step 2: Check it against the scan**

`docs/BLOB.md` is deliberately **not** on `CompanionVocabularyTest::sourceFiles()` and must not be added — it quotes the banned words in order to document them. Confirm it is still absent:

```bash
grep -c 'BLOB.md' tests/Feature/Companion/CompanionVocabularyTest.php
```
Expected: `0`.

- [ ] **Step 3: Commit, on its own**

```bash
git add docs/BLOB.md
git commit -m "docs(blob): bring the reference current with the shelter"
```

---

## Batch 3 review checkpoint

**Stop here.** Report to the human partner:

- Both suite numbers, and the diff from Batch 2's.
- **The screenshots from Task 18**, at desktop and 390px, with a plain judgement of whether any of the five labels overlap and which coordinate you would move if so. The trunk's `[-24, 48]` is still an unreviewed computed placement from F2, and this is the first time anyone has looked at the clearing.
- **Whatever Task 17's walk turned up**, including "nothing" — the walk completing on shipped config with no capacity override is itself the finding, because it is the first time this economy has been played.
- That `main` fast-forwards cleanly across all three batches.

---

## Self-review

Run against the spec with fresh eyes before dispatching Task 1.

**Spec coverage.** Every section of `2026-09-18-companion-f3-the-shelter-design.md` maps to a task:

| Spec | Task |
| --- | --- |
| §1 the forest is always the world; the shelter upgrades in place | 14, 16 |
| §2 overturn 1 — the cabin is no longer a gift | 8, 9, 13, 14 |
| §2 overturn 2 — a player may destroy a material; destruction is never automatic | 2, 3, 4, **5 (the guard)** |
| §3 inside/outside transient, stage stored, interior per stage | 7, 14, 15 |
| §4 content, costs, floors, only the next stage listed | 8, 9, 10 |
| §5 choose how much to take | 1, 3, 4 |
| §5 drop | 2, 3, 4 |
| §6 the migration, its three consequences | 7, 12, 13 |
| §6 the arithmetic problem | **Finding 6**, surfaced at the Batch 2 review and written into `docs/BLOB.md` in 19 |
| §7 no new backdrops; a real control, not a shape | 14, 15, 16 |
| §7 label sizing — explicitly not solved | named as out of scope in 19 |
| §8 every PHP test listed | 1, 2, 5, 9, 10, 12, 13 |
| §8 every Vitest test listed | 4, 11, 14, 15, 16 |
| §9 three batches, stop after each | the three checkpoints |
| §10 out of scope | nothing in this plan touches backdrops, the chest, wearables, `CompanionRemarks`, or label sizing |
| §11 what would make this wrong | each of the six is a test: the drop guard (5), the stage never regressing (9), one stage at a time (14, 16), the offer never showing an unreachable stage (10), the migration (13), the floor still at 5 (8) |

**Placeholder scan.** No step says "add error handling", "similar to Task N", "TBD", or "write tests for the above". Every code step carries the code. Three steps deliberately say *read the existing helper first* — `StockCompanionNodesTest`'s logging helper in Task 12, `companion-room.test.tsx`'s `room()` in Task 14, and `BuildItemTest`'s `carrying()` in Task 6 — because inventing a second helper beside an existing one is how a suite grows two ways to do the same thing. Those are instructions, not gaps.

**Type consistency.**
- `HarvestNode::handle($user, $node, ?int $wanted = null): int` — defined in Task 1, called with three arguments in Task 3 and with three in Tasks 12 and 17.
- `DropItem::handle($user, $item): int` — Task 2, called in Tasks 3 and 5.
- `BuildShelter::handle($user, $stage): string` — Task 9, called in Tasks 11 and 17.
- `SalvageTheCabin::handle(): int` — Task 13, called in the migration in the same task.
- `Companion::shortfallFor(array): array` and `spend(array): void` — Task 6, called in Tasks 6 and 9.
- Payload keys: `items[].droppable` (4), `nodes[].skill: ?string` (12), `shelter.built|label|offer` (10). `offer.stage|label|recipe|buildable` throughout — never `next`, never `count`.
- `CompanionRoom`'s `inside?: boolean` and `shelter?: string | null` — Task 14, passed in Task 15.
- `SceneSpec.shelter?: readonly [number, number]` — Task 16, read in Task 16.

**One ordering hazard, stated rather than discovered.** Task 8 knowingly leaves two `CompanionContentTest` assertions red, and Task 12 repairs them with the code that earns the repair. The step text says so in both places and says explicitly that loosening them in Task 8 is the one wrong move. If the executor prefers a green tree at every commit, Tasks 8 and 12 may be run as one commit; that is the only permitted merge of tasks in this plan.
