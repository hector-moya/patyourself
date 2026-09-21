# Companion F4.1 — the stash: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** A chest, built from 3 planks, standing in the clearing, holding materials at home rather
than on Blob — uncapped, and spent from never.

**Architecture:** One config row makes the chest a `structure` recipe; `BuildItem` refuses a second
one and `CompanionBag::recipes()` stops offering it, so the action and the read agree. Contents live
in a **separate** `companion_stash_items` table, never a column on `companion_items`, because
`Companion::held()` and `capacity()` sum `$this->items` unfiltered and a shared table would make
both silently wrong. Two actions move stacks between bag and stash; the bag modal grows an *at home*
section; the clearing grows one sprite and one hotspot.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, Inertia v3 + React 19, TypeScript, vitest, Pixel Lab
MCP for the art.

**Spec:** `docs/superpowers/specs/2026-09-22-companion-f4.1-the-stash-design.md`

## Global Constraints

Copied verbatim from the spec and from `docs/BLOB.md`. Every task's requirements implicitly include
this section.

- **Only ever show what has happened.** No locked slot, no greyed-out item, no "next up", no
  remaining count, no preview. An empty stash shows an empty section, never a slot naming what
  belongs in it.
- **Never show a total, a completion figure, or an end state.** Guarded by `companion.test.tsx`'s
  `never shows what has not happened`, matching `/locked|next up|to unlock|remaining|streak|\d+ of \d+/`.
- **Never state a plan.** A recipe is a price, which is allowed.
- **Blob's life is never a mirror.** Copy says what Blob did, never how the person is doing.
- **Nothing regresses.** No decay, expiry or upkeep. Nothing is removed on the player's behalf.
- **Destruction is always player-initiated.** `DropItem` stays the only path that destroys.
- **Banned vocabulary**, enforced by `CompanionVocabularyTest` over a list of source files, comments
  included: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`,
  `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. **`points` is a substring
  trap** — *appoints* and *disappoints* trip it.
- **Any new companion source file must be added to `CompanionVocabularyTest::sourceFiles()` in the
  same task that creates it.**
- **`docs/BLOB.md` is deliberately NOT on that list and must stay off it.**
- **Copy rules:** sentence case, one or two sentences, no exclamation marks, never congratulating,
  `{name}` rather than the literal.
- **Run `vendor/bin/pint --dirty --format agent`** after touching PHP.
- **NEVER run `npm run format`** (rewrites a hand-formatted CSS file; once turned a 94-line change
  into a 3782-line diff) or **`npm run lint`** (descends into `.claude/worktrees/*` and has silently
  rewritten 118 files). Scope every formatter and linter to named files.
- **Tests are SQLite; production is MySQL.** `assertDatabaseMissing` on a column that does not exist
  is a constant-false predicate on SQLite and passes forever.
- **A stated mutation nobody ran is not evidence.** Every task that names a mutation must apply it,
  observe the result, and report what actually happened.

**Test commands:**
- PHP: `php artisan test --compact --filter=<name>`; whole suite `php artisan test --compact` (1385).
- Client: `npx vitest run <path>`; whole suite `npm test` (727).

---

## File Structure

| File | Responsibility | Task |
| --- | --- | --- |
| `config/companion.php` | the `chest` row; the `stash` copy block | 1, 5 |
| `app/Services/Companion/CompanionEconomyException.php` | `alreadyStanding()` factory | 1 |
| `app/Actions/BuildItem.php` | refuse a second structure | 1 |
| `app/Services/Companion/CompanionBag.php` | drop a standing structure from `recipes()`; the `stash` payload in **both** builders | 1, 6 |
| `database/migrations/…_create_companion_stash_items_table.php` | the table | 2 |
| `app/Models/CompanionStashItem.php` | one stack at home | 2 |
| `database/factories/CompanionStashItemFactory.php` | its factory | 2 |
| `app/Models/Companion.php` | `stashItems()` relation | 2 |
| `app/Actions/StashItem.php` | bag → stash | 3 |
| `app/Actions/UnstashItem.php` | stash → bag | 4 |
| `app/Http/Controllers/CompanionStashController.php` | the one route | 5 |
| `routes/web.php` | `companion.stash.store` | 5 |
| `resources/js/patyourself/companion.tsx` | `BagStashData` type | 6 |
| `resources/js/patyourself/companion.fixture.ts` | the fixture's `stash` | 6 |
| `resources/js/patyourself/companion-bag.tsx` | the *at home* section | 7 |
| `resources/js/patyourself/scenes/node-chest.png` | the art | 8 |
| `resources/js/patyourself/scenes.ts` | `CHEST_CELL`, `chestSprite()`, the scene's `chest` coordinate | 9 |
| `resources/js/patyourself/companion-room.tsx` | `ChestLayer` | 9 |
| `resources/js/pages/companion.tsx` | the chest hotspot | 9 |
| `docs/BLOB.md`, `resources/js/patyourself/scenes/README.md` | the record | 10 |

---

## Task 1: The chest is a thing you build, once

**Files:**
- Modify: `config/companion.php` — the `bag` array, after `crate`
- Modify: `app/Services/Companion/CompanionEconomyException.php` — new factory
- Modify: `app/Actions/BuildItem.php`
- Modify: `app/Services/Companion/CompanionBag.php:499-512` — `recipes()`
- Test: `tests/Feature/Companion/BuildItemTest.php`, `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `config('companion.bag.chest')` with `category: 'structure'`, `recipe: ['planks' => 3]`,
  `label: 'chest'`. `CompanionEconomyException::alreadyStanding(string $thing): self`. A standing
  structure is a `companion_items` row named `chest` with `quantity >= 1`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Companion/BuildItemTest.php`:

```php
    /** A structure stands once: the second build is refused, not stacked. */
    public function test_a_structure_cannot_be_built_twice(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 6]);

        app(BuildItem::class)->handle($user, 'chest');

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildItem::class)->handle($user, 'chest');
        } finally {
            $this->assertSame(1, $this->held($companion, 'chest'));
            $this->assertSame(3, $this->held($companion, 'planks'));
        }
    }

    /**
     * The chest is reachable by a bag that has never built a container.
     *
     * 3 planks is the whole reason the price is 3: sawing is net +2, so
     * `wouldFit()` needs `held <= 3` when it runs and a base bag tops out at
     * three planks. A structure adds nothing carried, so `3 - 3 + 0 = 0 <= 5`.
     */
    public function test_a_base_bag_can_afford_the_chest(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 3]);

        $this->assertSame(5, $companion->capacity());

        app(BuildItem::class)->handle($user, 'chest');

        $this->assertSame(1, $this->held($companion, 'chest'));
        $this->assertSame(0, $companion->items()->where('item', 'planks')->count());
    }

    /** A standing chest takes no bag room and raises no capacity. */
    public function test_a_standing_chest_is_neither_carried_nor_capacity(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 3]);

        app(BuildItem::class)->handle($user, 'chest');

        $companion->load('items');

        $this->assertSame(0, $companion->held());
        $this->assertSame(5, $companion->capacity());
    }
```

In `tests/Feature/Companion/CompanionBagTest.php` — find the existing helper that builds a bag
payload for a user and follow it; the assertions are:

```php
    /**
     * The read half of "a structure stands once".
     *
     * `BuildItem` refuses a second chest; this is the surface agreeing with it.
     * A row that stayed listed would offer a build that always refuses, which
     * is the one thing the bag's lists have never done.
     */
    public function test_a_standing_structure_leaves_the_build_list(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->nodes()->create(['node' => 'trunk', 'available' => 0]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 4]);

        $before = app(CompanionBag::class)->forUser($user);

        $this->assertContains('chest', array_column($before['recipes'], 'item'));

        $companion->items()->create(['item' => 'chest', 'quantity' => 1]);

        $after = app(CompanionBag::class)->forUser($user->fresh());

        $this->assertNotContains('chest', array_column($after['recipes'], 'item'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test --compact --filter='a_structure_cannot_be_built_twice|a_base_bag_can_afford_the_chest|a_standing_chest_is_neither|a_standing_structure_leaves'
```

Expected: FAIL. The first three fail because `config('companion.bag.chest')` does not exist, so
`BuildItem` raises `InvalidArgumentException`, not `CompanionEconomyException`. The fourth fails on
`assertContains`.

- [ ] **Step 3: Add the config row**

In `config/companion.php`, inside `'bag' => [...]`, directly after the `crate` entry:

```php
        // A STRUCTURE, not a container. It raises nothing Blob can carry: it
        // stands in the clearing and holds what the bag cannot, which is why
        // there is no `capacity` key here and why it never appears in
        // `capacity.carried`.
        //
        // 3 planks is the ONLY plank price a base bag can reach unaided.
        // Sawing is net +2, so `wouldFit()` needs `held <= 3` at the moment it
        // runs and a base bag tops out at three planks; a structure adds
        // nothing carried, so `3 - 3 + 0 = 0 <= 5` and this builds. At 4 it
        // would need a container first and stop being the first thing made of
        // planks that anyone can put together.
        //
        // A `bag` recipe where the shelter is deliberately not one. The
        // shelter is kept out because a recipe becomes knowable from its
        // ingredients and all three stages would list at once; a chest has no
        // stages, so only-the-next-stage has nothing to do here.
        'chest' => [
            'category' => 'structure',
            'label' => 'chest',
            'recipe' => ['planks' => 3],
        ],
```

- [ ] **Step 4: Add the refusal factory**

In `app/Services/Companion/CompanionEconomyException.php`, after `notOffered()`:

```php
    /**
     * A structure that is already standing.
     *
     * Deliberately not worded as a shortage, because it is not one: nothing
     * that could be gathered would change the answer. A structure stands once,
     * so the screen stops offering a second the moment the first goes up —
     * {@see CompanionBag::recipes()} drops it from the list — and reaching this
     * means the read and the action have drifted apart.
     */
    public static function alreadyStanding(string $thing): self
    {
        return new self("[{$thing}] is already standing; there is only ever one.");
    }
```

Update the class docblock's "Seven factories now" to "Eight factories now".

- [ ] **Step 5: Refuse the second build**

In `app/Actions/BuildItem.php`, beside the existing `$tool` line:

```php
        $tool = (string) ($catalogue[$item]['tool'] ?? '');
        $category = (string) ($catalogue[$item]['category'] ?? '');
```

Add `$category` to the `use (...)` list of the `DB::transaction` closure, and put this check
immediately after `$companion = $user->companion()->firstOrCreate([]);`, **before** the tool check:

```php
            // A structure stands ONCE. Without this the `firstOrCreate` and
            // `increment` at the end of this method would quietly stack a
            // second chest onto the first, and there is no second stash for it
            // to be.
            //
            // Checked before the tool and before the materials because it is
            // not a shortage: nothing Blob could go and gather would change the
            // answer, so sending the reader past it to a price would be the
            // wrong sentence.
            if ($category === 'structure'
                && $companion->items()->where('item', $item)->exists()) {
                throw CompanionEconomyException::alreadyStanding($item);
            }
```

- [ ] **Step 6: Stop offering a standing structure**

In `app/Services/Companion/CompanionBag.php`, inside `recipes()`, immediately after the
`if ($recipe === [] || ! in_array($name, $knowable, true)) { continue; }` block:

```php
            // A structure stands once, so a standing one leaves this list
            // rather than sitting in it offering a build that always refuses.
            // This is the read half of a pair: `BuildItem` raises
            // `alreadyStanding()` for the same state, and the two must never
            // disagree about whether a build is possible — the same property
            // `wouldFit()` exists to give the capacity check.
            if ((string) ($item['category'] ?? '') === 'structure'
                && (int) ($held->get($name)?->quantity ?? 0) > 0) {
                continue;
            }
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test --compact --filter='a_structure_cannot_be_built_twice|a_base_bag_can_afford_the_chest|a_standing_chest_is_neither|a_standing_structure_leaves'
```

Expected: PASS.

- [ ] **Step 8: Run the mutations, and record what happened**

Apply each, run the named test, revert. **Report the observed result — a mutation nobody ran is not
evidence.**

| Mutation | Run | Expected |
| --- | --- | --- |
| Delete the `alreadyStanding` throw in `BuildItem` | `--filter=a_structure_cannot_be_built_twice` | RED |
| Delete the `structure` skip in `recipes()` | `--filter=a_standing_structure_leaves` | RED |
| Change the chest's price to `['planks' => 4]` | `--filter=a_base_bag_can_afford_the_chest` | RED |
| Add `'capacity' => 5` to the chest's config row | `--filter=a_standing_chest_is_neither` | RED |

- [ ] **Step 9: Run the neighbouring suites**

```bash
php artisan test --compact tests/Feature/Companion tests/Unit/Companion
```

Expected: PASS. The new config row changes what `recipes()` lists, so any test asserting an exact
recipe list will need widening — widen it rather than removing the chest.

- [ ] **Step 10: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php app/Services/Companion/CompanionEconomyException.php app/Actions/BuildItem.php app/Services/Companion/CompanionBag.php tests/Feature/Companion/BuildItemTest.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): the chest, built once out of three planks"
```

---

## Task 2: The stash table, and the reason it is its own

**Files:**
- Create: `database/migrations/2026_09_22_000001_create_companion_stash_items_table.php`
- Create: `app/Models/CompanionStashItem.php`
- Create: `database/factories/CompanionStashItemFactory.php`
- Modify: `app/Models/Companion.php` — beside `items()`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` — `sourceFiles()`
- Test: `tests/Feature/Companion/CompanionStorageTest.php`

**Interfaces:**
- Consumes: Task 1's `chest` config row (for the test's realism only).
- Produces: `Companion::stashItems(): HasMany<CompanionStashItem, $this>`; the model
  `App\Models\CompanionStashItem` with fillable `item`, `quantity`; table `companion_stash_items`
  with `unique(companion_id, item)`.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Companion/CompanionStorageTest.php`:

```php
    /**
     * THE REASON THIS IS ITS OWN TABLE.
     *
     * `Companion::held()` and `Companion::capacity()` both sum `$this->items`
     * with no filter. Had the stash been a `location` column on
     * `companion_items`, a stashed crate would raise carry capacity and
     * stashed planks would count against the bag — silently, with every
     * existing test green, because every existing test writes rows that are
     * all in one place.
     *
     * The mutation that turns this red is pointing `stashItems()` at
     * `companion_items`.
     */
    public function test_what_is_at_home_is_neither_carried_nor_capacity(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 9]);
        $companion->stashItems()->create(['item' => 'crate', 'quantity' => 1]);

        $companion->load('items');

        $this->assertSame(2, $companion->held());
        $this->assertSame(5, $companion->capacity());
        $this->assertSame(2, $companion->stashItems()->count());
    }

    /** One stack per item at home, enforced by the database rather than by care. */
    public function test_the_stash_cannot_hold_two_stacks_of_one_item(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 1]);

        $this->expectException(QueryException::class);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 1]);
    }

    /** The stash goes with the companion it belongs to. */
    public function test_the_stash_is_removed_with_its_companion(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 3]);

        $companion->delete();

        $this->assertSame(0, CompanionStashItem::query()->count());
    }
```

Add `use App\Models\CompanionStashItem;` to the imports. `QueryException` is already imported.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter='what_is_at_home_is_neither|the_stash_cannot_hold_two|the_stash_is_removed_with'
```

Expected: FAIL with `Call to undefined method App\Models\Companion::stashItems()`.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_companion_stash_items_table --no-interaction
```

Rename the generated file to `2026_09_22_000001_create_companion_stash_items_table.php` so it sorts
after the shelter's two, and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is at home: one stack per item, standing in the chest.
 *
 * SAME SHAPE AS `companion_items`, AND DELIBERATELY NOT THE SAME TABLE. A
 * `location` column would have been fewer lines and would have been wrong:
 * `Companion::held()` and `Companion::capacity()` both sum `$this->items`
 * unfiltered, so a stashed crate would raise carry capacity and stashed planks
 * would count against the bag — silently, because every test written before
 * this one puts every row in one place.
 *
 * A separate relation cannot do that. `items` keeps meaning what it has always
 * meant, by construction rather than by a filter somebody has to remember.
 *
 * As in the bag, `item` is a name and its CATEGORY IS CONFIG'S TO SAY. There is
 * no capacity column because the stash has no ceiling: the world is uncapped
 * everywhere else in this feature — node stock has none and nothing expires —
 * and a limit here would be the first place the world refuses to hold
 * something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_stash_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('item');
            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->unique(['companion_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_stash_items');
    }
};
```

- [ ] **Step 4: Create the model and its factory**

`app/Models/CompanionStashItem.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanionStashItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stack at home, in the chest.
 *
 * The bag's twin, and its own table for a measured reason the migration
 * records. Its category is config's to say, not this row's.
 *
 * Nothing here is destroyed: a stack moved back into the bag is the same stack
 * in a different place, and a stack drained to nothing has its row removed
 * rather than left at zero — the same rule the bag already follows, because an
 * empty row would render as a line saying Blob has no planks at home.
 */
#[Fillable([
    'item',
    'quantity',
])]
class CompanionStashItem extends Model
{
    /** @use HasFactory<CompanionStashItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
```

`database/factories/CompanionStashItemFactory.php` — copy `CompanionItemFactory.php` verbatim and
change the class name and `$model` to `CompanionStashItem`.

- [ ] **Step 5: Add the relation**

In `app/Models/Companion.php`, immediately after `items()`:

```php
    /**
     * What is at home rather than on Blob.
     *
     * A SECOND RELATION, never a filter on `items`. Everything that reads
     * `items` — `held()`, `capacity()`, `wouldFit()`, `spend()`,
     * `shortfallFor()` — means "what Blob is carrying", and it means that
     * because of this separation rather than because each one remembered to
     * ask. Nothing in this class may ever sum the two together.
     *
     * @return HasMany<CompanionStashItem, $this>
     */
    public function stashItems(): HasMany
    {
        return $this->hasMany(CompanionStashItem::class);
    }
```

Add `use App\Models\CompanionStashItem;`? No — same namespace, no import needed.

- [ ] **Step 6: Add the new files to the vocabulary list**

In `tests/Feature/Companion/CompanionVocabularyTest.php`, inside `sourceFiles()`, beside
`CompanionItem.php`:

```php
            $root.'/app/Models/CompanionStashItem.php',
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test --compact --filter='what_is_at_home_is_neither|the_stash_cannot_hold_two|the_stash_is_removed_with'
php artisan test --compact --filter=CompanionVocabularyTest
```

Expected: PASS.

- [ ] **Step 8: Run the mutation, and record what happened**

Change `stashItems()` to `return $this->hasMany(CompanionItem::class);` and run
`--filter=what_is_at_home_is_neither`.

Expected: **RED** — `held()` becomes 11 and `capacity()` becomes 10. Revert.

This is the mutation the whole task exists for. Report the two numbers you actually saw.

- [ ] **Step 9: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/CompanionStashItem.php app/Models/Companion.php database/factories/CompanionStashItemFactory.php tests/Feature/Companion/CompanionStorageTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): the stash gets its own table, not a column"
```

---

## Task 3: `StashItem` — bag to chest

**Files:**
- Create: `app/Actions/StashItem.php`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`
- Test: `tests/Feature/Companion/StashItemTest.php`

**Interfaces:**
- Consumes: `Companion::stashItems()` from Task 2; `config('companion.capacity.carried')`.
- Produces: `StashItem::handle(User $user, string $item, ?int $wanted = null): int` — returns how
  many units moved. Throws `InvalidArgumentException` for an unauthored item or a non-carried
  category. Returns `0` when there is no such stack in the bag.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Companion/StashItemTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\StashItem;
use App\Models\Companion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Putting something down at home.
 *
 * The stash is UNCAPPED, so this action has no refusal of its own: the world
 * is uncapped everywhere else in this feature and a ceiling here would be the
 * first place it says no. What it does have is the same category rule the bag
 * already enforces — only what Blob CARRIES can be put down, because a tool is
 * on the belt and a container is the room itself.
 */
class StashItemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function carrying(array $items): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        foreach ($items as $item => $quantity) {
            $companion->items()->create(['item' => $item, 'quantity' => $quantity]);
        }

        return [$user, $companion];
    }

    public function test_a_named_amount_moves_and_the_rest_stays_carried(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 3]);

        $moved = app(StashItem::class)->handle($user, 'planks', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(1, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /** No amount means the whole stack: putting something down is not a measured act. */
    public function test_no_amount_moves_the_whole_stack(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        $moved = app(StashItem::class)->handle($user, 'fibre');

        $this->assertSame(4, $moved);
        $this->assertSame(0, $companion->items()->where('item', 'fibre')->count());
        $this->assertSame(4, (int) $companion->stashItems()->where('item', 'fibre')->value('quantity'));
    }

    /** Asking for more than is carried moves what is carried, and says so. */
    public function test_asking_for_more_than_is_held_moves_what_is_held(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 2]);

        $this->assertSame(2, app(StashItem::class)->handle($user, 'timber', 9));
        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
    }

    /** Stacks merge at home rather than sitting beside each other. */
    public function test_a_second_deposit_adds_to_the_stack_already_there(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 2]);

        app(StashItem::class)->handle($user, 'planks', 1);
        app(StashItem::class)->handle($user, 'planks', 1);

        $this->assertSame(1, $companion->stashItems()->where('item', 'planks')->count());
        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /** Nothing there is an ordinary answer, not a refusal. */
    public function test_nothing_carried_moves_nothing_and_says_nothing(): void
    {
        [$user, $companion] = $this->carrying([]);

        $this->assertSame(0, app(StashItem::class)->handle($user, 'planks'));
        $this->assertSame(0, $companion->stashItems()->count());
    }

    /** A tool is on the belt; a container is the room. Neither is put down. */
    public function test_only_carried_categories_may_be_put_down(): void
    {
        [$user] = $this->carrying(['axe' => 1, 'crate' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(StashItem::class)->handle($user, 'axe');
    }

    public function test_an_unauthored_item_is_refused(): void
    {
        [$user] = $this->carrying([]);

        $this->expectException(InvalidArgumentException::class);

        app(StashItem::class)->handle($user, 'moonstone');
    }
```

Close the class with `}`.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Companion/StashItemTest.php
```

Expected: FAIL — `Target class [App\Actions\StashItem] does not exist.`

- [ ] **Step 3: Write the action**

`app/Actions/StashItem.php`:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob puts something down at home, in the chest.
 *
 * NOTHING IS DESTROYED AND NOTHING IS SPENT: this is one stack in two places
 * over time, and it can be fetched back. That is what separates it from
 * {@see DropItem}, which is the only path in this feature that destroys, and it
 * is why this needs no confirmation and no refusal of its own.
 *
 * THE STASH IS UNCAPPED, so there is no room check here. The world is uncapped
 * everywhere else — node stock has no ceiling and nothing expires — and a limit
 * here would be the first place the world refuses to hold something. What the
 * stash does NOT do is raise what Blob can carry: capacity still caps the bag,
 * and `Companion::held()` still counts only `items`.
 *
 * Only CARRIED categories, the same rule {@see DropItem} enforces and for the
 * same reasons: a tool is on the belt and occupies no room, and a container
 * stashed would silently lower capacity — regression wearing a different hat.
 *
 * Returns how many units moved, so the caller can say so in the app's own
 * voice. Zero is an ordinary answer: there was nothing in hand.
 */
final readonly class StashItem
{
    /**
     * @param  ?int  $wanted  How much to put down, or null for the whole stack.
     *                        Putting something down is not a measured act the
     *                        way taking from a node is — but the bag offers the
     *                        amount anyway, because parking two of three planks
     *                        and sawing on is the gesture this whole phase
     *                        exists for.
     * @return int How many units moved into the chest.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  its category is not one Blob carries.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        if (! in_array((string) ($catalogue[$item]['category'] ?? ''), $carried, true)) {
            throw new InvalidArgumentException("[{$item}] is not carried, so there is nothing to put down.");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            // Floored at one rather than clamped to zero, the same as a
            // harvest: a caller asking for nothing has asked a question this
            // action has no answer for, and moving nothing while reporting
            // success would say the bag was empty when it was not.
            $moved = $wanted === null
                ? $stack->quantity
                : min($stack->quantity, max(1, $wanted));

            // Deleted rather than zeroed, the same rule the bag already
            // follows: an empty row would render as a line saying Blob is
            // carrying no planks, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->stashItems()
                ->firstOrCreate(['item' => $item], ['quantity' => 0])
                ->increment('quantity', $moved);

            return $moved;
        });
    }
}
```

- [ ] **Step 4: Add it to the vocabulary list**

In `CompanionVocabularyTest::sourceFiles()`, beside `DropItem.php`:

```php
            $root.'/app/Actions/StashItem.php',
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Companion/StashItemTest.php
php artisan test --compact --filter=CompanionVocabularyTest
```

Expected: PASS.

- [ ] **Step 6: Run the mutations, and record what happened**

| Mutation | Run | Expected |
| --- | --- | --- |
| Drop the `carried` check | `--filter=only_carried_categories_may_be_put_down` | RED |
| `$moved = $stack->quantity` always (ignore `$wanted`) | `--filter=a_named_amount_moves_and_the_rest` | RED |
| Use `create` instead of `firstOrCreate` on the stash | `--filter=a_second_deposit_adds_to_the_stack` | RED |
| Never delete the emptied bag row | `--filter=no_amount_moves_the_whole_stack` | RED |

- [ ] **Step 7: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/StashItem.php tests/Feature/Companion/StashItemTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): put a stack down in the chest"
```

---

## Task 4: `UnstashItem` — chest to bag, bounded by room

**Files:**
- Create: `app/Actions/UnstashItem.php`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`
- Test: `tests/Feature/Companion/UnstashItemTest.php`

**Interfaces:**
- Consumes: `Companion::stashItems()` (Task 2), `Companion::room()`, `StashItem` (tests only).
- Produces: `UnstashItem::handle(User $user, string $item, ?int $wanted = null): int`. Throws
  `CompanionEconomyException::bagIsFull($item)` when `room === 0` and something is at home. Returns
  `0` when nothing is at home.

**This action mirrors `HarvestNode` exactly.** A full bag refuses and nothing moves; otherwise it
moves `min(stashed, room, wanted)` and the remainder stays in the chest. A partial withdrawal is the
normal case, not an edge one.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Companion/UnstashItemTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\UnstashItem;
use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fetching something back out of the chest.
 *
 * BOUNDED BY WHAT BLOB CAN STILL CARRY, AND THE REMAINDER STAYS AT HOME —
 * the same promise {@see \App\Actions\HarvestNode} makes about a node, seen
 * from the other side. A full bag refuses and takes nothing; anything else
 * moves what fits.
 *
 * This is why the chest does not remove the need for containers: the stash can
 * hold the twenty-six planks a cabin's arc costs, and the bag still decides how
 * many of them can be in hand at once.
 */
class UnstashItemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function withStash(array $atHome, array $carried = []): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        foreach ($atHome as $item => $quantity) {
            $companion->stashItems()->create(['item' => $item, 'quantity' => $quantity]);
        }

        foreach ($carried as $item => $quantity) {
            $companion->items()->create(['item' => $item, 'quantity' => $quantity]);
        }

        return [$user, $companion];
    }

    public function test_a_named_amount_comes_back_and_the_rest_stays_at_home(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 9]);

        $moved = app(UnstashItem::class)->handle($user, 'planks', 4);

        $this->assertSame(4, $moved);
        $this->assertSame(4, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(5, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /**
     * The clamp that is the whole reason capacity survives this phase.
     *
     * Twenty-six planks at home, a base bag, and one gesture: five come back
     * and twenty-one are still in the chest.
     */
    public function test_a_withdrawal_never_exceeds_what_the_bag_can_hold(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 26]);

        $moved = app(UnstashItem::class)->handle($user, 'planks', 26);

        $this->assertSame(5, $moved);
        $this->assertSame(5, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(21, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));

        $companion->load('items');

        $this->assertSame(5, $companion->held());
    }

    /** A full bag refuses, and the chest is untouched by the refusal. */
    public function test_a_full_bag_refuses_and_moves_nothing(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 9], ['fibre' => 5]);

        try {
            app(UnstashItem::class)->handle($user, 'planks', 1);
            $this->fail('A full bag should refuse.');
        } catch (CompanionEconomyException) {
            // The refusal is the assertion; what matters is what it did not do.
        }

        $this->assertSame(9, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
        $this->assertSame(0, $companion->items()->where('item', 'planks')->count());
    }

    /** A stack fetched back in full leaves no empty row at home. */
    public function test_a_stack_emptied_leaves_no_row_behind(): void
    {
        [$user, $companion] = $this->withStash(['fibre' => 3]);

        $this->assertSame(3, app(UnstashItem::class)->handle($user, 'fibre'));
        $this->assertSame(0, $companion->stashItems()->where('item', 'fibre')->count());
    }

    /** Nothing at home is an ordinary answer, not a refusal. */
    public function test_nothing_at_home_moves_nothing(): void
    {
        [$user] = $this->withStash([]);

        $this->assertSame(0, app(UnstashItem::class)->handle($user, 'planks'));
    }

    public function test_an_unauthored_item_is_refused(): void
    {
        [$user] = $this->withStash([]);

        $this->expectException(InvalidArgumentException::class);

        app(UnstashItem::class)->handle($user, 'moonstone');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Companion/UnstashItemTest.php
```

Expected: FAIL — `Target class [App\Actions\UnstashItem] does not exist.`

- [ ] **Step 3: Write the action**

`app/Actions/UnstashItem.php`:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionStashItem;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob fetches something back out of the chest.
 *
 * BOUNDED BY WHAT BLOB CAN STILL CARRY, AND THE REMAINDER STAYS AT HOME. This
 * is {@see HarvestNode}'s promise seen from the other side, and it is
 * deliberately the same arithmetic rather than a second answer to one question:
 * a full bag refuses, anything else moves `min(stashed, room, wanted)`.
 *
 * That clamp is why the chest does not make containers pointless. The stash can
 * hold every plank the shelter's arc costs; the bag still decides how many can
 * be in hand at once, and a cabin is paid for out of the bag.
 *
 * The refusal reuses {@see CompanionEconomyException::bagIsFull()} rather than
 * adding a factory, because it is the same sentence about the same thing:
 * there is no room, and what you reached for stays where it was.
 * `noRoomFor()` stays what it is — a BUILD that priced out fine with nowhere to
 * put the result.
 *
 * Returns how many units moved. Zero is an ordinary answer: there was nothing
 * at home.
 */
final readonly class UnstashItem
{
    /**
     * @param  ?int  $wanted  How much to fetch, or null for as much as fits.
     * @return int How many units moved into the bag.
     *
     * @throws InvalidArgumentException when no such item is authored.
     * @throws CompanionEconomyException when the bag is full and something is
     *                                   standing at home.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->stashItems()->where('item', $item)->first();

            // Nothing at home. A fact about the chest rather than a refusal —
            // the same answer an empty node gives.
            if (! $stack instanceof CompanionStashItem) {
                return 0;
            }

            // `items` may be stale on a companion the caller has been holding,
            // and room is computed from it.
            $companion->load('items');

            $room = $companion->room();

            if ($room === 0) {
                throw CompanionEconomyException::bagIsFull($item);
            }

            $moved = min($stack->quantity, $room);

            if ($wanted !== null) {
                $moved = min($moved, max(1, $wanted));
            }

            // Deleted rather than zeroed, the same rule the bag follows: an
            // empty row would render as a line saying Blob has no planks at
            // home, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->items()
                ->firstOrCreate(['item' => $item], ['quantity' => 0])
                ->increment('quantity', $moved);

            return $moved;
        });
    }
}
```

- [ ] **Step 4: Add it to the vocabulary list**

```php
            $root.'/app/Actions/UnstashItem.php',
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Companion/UnstashItemTest.php
php artisan test --compact --filter=CompanionVocabularyTest
```

Expected: PASS.

- [ ] **Step 6: Run the mutations, and record what happened**

| Mutation | Run | Expected |
| --- | --- | --- |
| Drop the `$room` clamp (`$moved = $stack->quantity`) | `--filter=a_withdrawal_never_exceeds_what_the_bag` | RED |
| `return 0` instead of throwing when `$room === 0` | `--filter=a_full_bag_refuses_and_moves_nothing` | RED |
| Always `$stack->delete()` regardless of `$moved` | `--filter=a_named_amount_comes_back_and_the_rest` | RED |
| Move the `load('items')` after `room()` | `--filter=a_full_bag_refuses_and_moves_nothing` | RED or GREEN — **report which**, and if GREEN say so plainly; a stale-read guard that cannot be falsified here is decoration and should be removed rather than left in |

- [ ] **Step 7: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/UnstashItem.php tests/Feature/Companion/UnstashItemTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): fetch a stack back out of the chest"
```

---

## Task 5: The route, the controller and Blob's three lines

**Files:**
- Create: `app/Http/Controllers/CompanionStashController.php`
- Modify: `routes/web.php` — beside `companion.items.destroy`
- Modify: `config/companion.php` — a `stash` copy block after `drop`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`
- Test: `tests/Feature/Companion/CompanionStashScreenTest.php`

**Interfaces:**
- Consumes: `StashItem` (Task 3), `UnstashItem` (Task 4).
- Produces: `POST companion/stash`, named `companion.stash.store`, taking `item` (string, required,
  one of the carried catalogue), `direction` (`in`|`out`), `amount` (nullable integer, min 1).
  Wayfinder emits `@/routes/companion/stash`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Companion/CompanionStashScreenTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Http\Controllers\CompanionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one route the chest adds.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row move from one list to the other.
 *
 * Only carried categories are routable at all. A tool or a container reaching
 * this is a malformed request rather than a state of the world, so it is a 404
 * rather than a line in Blob's voice — the same rule
 * {@see \App\Http\Controllers\CompanionItemController} follows.
 */
class CompanionStashScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_putting_something_down_says_what_blob_did(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'in',
                'amount' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob puts 2 planks in the chest.');

        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    public function test_fetching_something_back_says_what_blob_did(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->stashItems()->create(['item' => 'fibre', 'quantity' => 4]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'fibre',
                'direction' => 'out',
                'amount' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob lifts 4 fibre back out of the chest.');
    }

    /** A full bag says where the thing stays, because nothing is destroyed. */
    public function test_a_full_bag_says_the_planks_stay_in_the_chest(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 4]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 5]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'out',
                'amount' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'There is nowhere to put them. The planks stay in the chest.');
    }

    /** A press that moved nothing says nothing rather than narrating itself. */
    public function test_moving_nothing_says_nothing(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'in',
            ])
            ->assertRedirect()
            ->assertSessionMissing(CompanionController::SAID_KEY);
    }

    /** A tool is never offered here, so a request naming one is malformed. */
    public function test_a_tool_is_a_404_rather_than_a_sentence(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'axe',
                'direction' => 'in',
            ])
            ->assertNotFound();
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'sideways',
            ])
            ->assertSessionHasErrors('direction');
    }
}
```

`CompanionController::SAID_KEY` is `'companion.said'` and `STAY_KEY` is `'companion.stay'`, read off
the class rather than guessed. Use the constants, not the literals, so a rename cannot leave these
tests asserting a key nothing writes.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Companion/CompanionStashScreenTest.php
```

Expected: FAIL — `Route [companion.stash.store] not defined.`

- [ ] **Step 3: Add the copy**

In `config/companion.php`, after the `'drop' => …` line:

```php
    /*
    |--------------------------------------------------------------------------
    | The chest
    |--------------------------------------------------------------------------
    |
    | What Blob says when something moves between the bag and home. Same copy
    | rules as everywhere else, and one that matters here: NOTHING IS LOST IN
    | EITHER DIRECTION. A stack put down can be fetched back, which is exactly
    | what separates this from `drop` above — so these lines must never read as
    | giving something up.
    |
    | `full` is the refusal, and it is worded as a node's own `full` line is: it
    | names where the thing stays, because nothing is ever destroyed. It is said
    | only for a bag with no room at all; a bag with some room takes what fits
    | and the rest stays in the chest without anything being said about it.
    |
    | `{count}` is how many moved. `{label}` is the thing.
    |
    */

    'stash' => [
        'in' => '{name} puts {count} {label} in the chest.',
        'out' => '{name} lifts {count} {label} back out of the chest.',
        'full' => 'There is nowhere to put them. The {label} stays in the chest.',
    ],
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, after the `companion.items.destroy` route:

```php
    // The chest: one route for both directions, because it is one gesture with
    // a sign on it. Two routes would be two names for moving a stack between
    // two lists, and the copy would drift apart.
    Route::post('companion/stash', [CompanionStashController::class, 'store'])
        ->name('companion.stash.store');
```

Add `use App\Http\Controllers\CompanionStashController;` to the imports.

- [ ] **Step 5: Write the controller**

`app/Http/Controllers/CompanionStashController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\StashItem;
use App\Actions\UnstashItem;
use App\Models\Companion;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Moving a stack between the bag and the chest.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row move from one list to the other.
 *
 * ONE ROUTE FOR BOTH DIRECTIONS, because it is one gesture with a sign on it.
 * Two routes would be two names for the same act, and the two copy lines would
 * drift apart the first time one was reworded.
 *
 * Only carried categories are routable at all. A tool is on the belt and a
 * container is the room itself, so neither is ever offered here — a request
 * naming one is malformed rather than a state of the world, and it is a 404
 * rather than a line in Blob's voice.
 */
class CompanionStashController extends Controller
{
    /** Only the entries in `companion.bag` that can move between the two lists. */
    private function movable(): array
    {
        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_keys(array_filter(
            (array) config('companion.bag', []),
            static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), $carried, true),
        ));
    }

    public function store(Request $request, StashItem $stash, UnstashItem $unstash): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = (string) $validated['item'];

        abort_unless(in_array($item, $this->movable(), true), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $label = (string) (config("companion.bag.{$item}.label") ?? $item);
        $amount = $validated['amount'] ?? null;

        try {
            $moved = $validated['direction'] === 'in'
                ? $stash->handle($user, $item, $amount)
                : $unstash->handle($user, $item, $amount);
        } catch (CompanionEconomyException) {
            // The bag is full. Worded as a node's own refusal is: it names
            // where the thing stays, because nothing is destroyed by a press
            // that could not complete.
            return back()
                ->with(CompanionController::SAID_KEY, str_replace(
                    '{label}',
                    $label,
                    (string) config('companion.stash.full'),
                ))
                ->with(CompanionController::STAY_KEY, true);
        }

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($moved === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{count}', '{label}'],
                [Companion::nameFor($user), (string) $moved, $label],
                (string) config("companion.stash.{$validated['direction']}"),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
```

- [ ] **Step 6: Add the controller to the vocabulary list**

```php
            $root.'/app/Http/Controllers/CompanionStashController.php',
```

- [ ] **Step 7: Regenerate Wayfinder and run the tests**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Companion/CompanionStashScreenTest.php
php artisan test --compact --filter=CompanionVocabularyTest
```

Expected: PASS. Confirm `resources/js/routes/companion/stash/index.ts` now exists.

- [ ] **Step 8: Run the mutations, and record what happened**

| Mutation | Run | Expected |
| --- | --- | --- |
| Remove the `abort_unless` | `--filter=a_tool_is_a_404_rather_than_a_sentence` | RED |
| Drop the `catch` and let the exception escape | `--filter=a_full_bag_says_the_planks_stay` | RED |
| Flash a line when `$moved === 0` | `--filter=moving_nothing_says_nothing` | RED |
| Swap the `in`/`out` branches | `--filter=putting_something_down_says_what_blob_did` | RED |

- [ ] **Step 9: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CompanionStashController.php routes/web.php config/companion.php resources/js/routes tests/Feature/Companion/CompanionStashScreenTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): one route moves a stack in or out of the chest"
```

---

## Task 6: The payload, in both builders

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php` — the docblock shape, both returns, a new
  `stash()` helper
- Modify: `resources/js/patyourself/companion.tsx` — `BagStashData`, `CompanionBagData`
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces: `bag.stash` as
  `array{standing: bool, items: list<array{item: string, label: string, quantity: int}>}`, and the
  TypeScript `BagStashData` of the same shape. Task 7 and Task 9 both read it.

**THE TRAP THIS TASK EXISTS AROUND.** `CompanionBag::forUser()` has **two** builders: the main
return, and `worldBeforeAnythingHappened()`'s early return at lines 72–87 for an account with no
companion row. A key added to one and not the other leaves a brand-new account with the key missing,
and **nothing in the type system catches it — both paths satisfy `array`.** F3.6 shipped exactly
this defect.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * THE SECOND BUILDER. A brand-new account takes an early return with its
     * own payload literal, and a key added only to the main return leaves that
     * account without it — with nothing in the type system to notice, because
     * both paths satisfy `array`.
     */
    public function test_an_account_with_no_companion_row_still_gets_a_stash(): void
    {
        $user = User::factory()->create();

        $bag = app(CompanionBag::class)->forUser($user);

        $this->assertArrayHasKey('stash', $bag);
        $this->assertFalse($bag['stash']['standing']);
        $this->assertSame([], $bag['stash']['items']);
    }

    /** `standing` is what the clearing draws on; it is not derivable client-side. */
    public function test_standing_follows_the_chest_and_items_follow_the_stash(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $bag = app(CompanionBag::class)->forUser($user);
        $this->assertFalse($bag['stash']['standing']);

        $companion->items()->create(['item' => 'chest', 'quantity' => 1]);
        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 7]);

        $bag = app(CompanionBag::class)->forUser($user->fresh());

        $this->assertTrue($bag['stash']['standing']);
        $this->assertSame(
            [['item' => 'planks', 'label' => 'planks', 'quantity' => 7]],
            $bag['stash']['items'],
        );
    }

    /** An item config does not know is skipped rather than shown as a blank row. */
    public function test_an_unknown_stashed_item_is_skipped(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->stashItems()->create(['item' => 'moonstone', 'quantity' => 1]);

        $this->assertSame([], app(CompanionBag::class)->forUser($user)['stash']['items']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact --filter='an_account_with_no_companion_row_still_gets_a_stash|standing_follows_the_chest|an_unknown_stashed_item_is_skipped'
```

Expected: FAIL — `Failed asserting that an array has the key 'stash'`.

- [ ] **Step 3: Add the eager load and both payload keys**

In `CompanionBag::forUser()`, widen the eager load:

```php
        $companion = Companion::query()
            ->with(['items', 'nodes', 'skills', 'stashItems'])
            ->where('user_id', $user->id)
            ->first();
```

In the **early return** (the `if (! $companion instanceof Companion)` block), after `'recipes' => []`:

```php
                // The second half of a pair, and the reason this literal is
                // dangerous: a key added only to the return below leaves a
                // brand-new account without it, and nothing in the type system
                // notices because both paths satisfy `array`. Nothing is built
                // and nothing is at home, which is exactly what an untouched
                // clearing is.
                'stash' => ['standing' => false, 'items' => []],
```

In the **main return**, after `'recipes' => …`:

```php
            'stash' => $this->stash($companion),
```

- [ ] **Step 4: Write the helper**

In `CompanionBag`, beside `items()`:

```php
    /**
     * The chest, and what is in it.
     *
     * `standing` cannot be derived on the client. A built chest is a
     * `companion_items` row of category `structure`, and nothing on the drawing
     * side reads the bag's catalogue — so without this flag the clearing would
     * either draw a chest nobody built or leave an invisible hit region where
     * one was not, which is the defect 15e893a fixed for the shelter.
     *
     * `items` carries no `droppable` field, and that is deliberate rather than
     * an omission: what is at home is never tipped out. `DropItem` is the only
     * path in this feature that destroys, it acts on the bag, and a stack has
     * to be fetched back before it can be given up.
     *
     * An item config does not know is skipped rather than shown as a blank row,
     * the same rule {@see items()} already applies.
     *
     * @return array{standing: bool, items: list<array{item: string, label: string, quantity: int}>}
     */
    private function stash(Companion $companion): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        $standing = $companion->items
            ->contains(static fn (CompanionItem $held): bool => $held->item === 'chest');

        return [
            'standing' => $standing,
            'items' => array_values(array_filter(array_map(
                static function (CompanionStashItem $held) use ($catalogue): ?array {
                    $entry = $catalogue[$held->item] ?? null;

                    return $entry === null ? null : [
                        'item' => $held->item,
                        'label' => (string) $entry['label'],
                        'quantity' => $held->quantity,
                    ];
                },
                $companion->stashItems->all(),
            ))),
        ];
    }
```

Add `use App\Models\CompanionStashItem;` to the imports.

**Note on `'chest'` as a literal:** it is the only `structure` in the catalogue and the clearing
draws exactly one thing. If a second structure is ever authored, this becomes a category scan and
`standing` becomes a name. Leave the literal and this sentence rather than building the general case
now.

- [ ] **Step 5: Extend the payload docblock**

In the `@return` shape above `forUser()`, after the `recipes:` line:

```
     *     stash: array{standing: bool, items: list<array{item: string, label: string, quantity: int}>},
```

- [ ] **Step 6: Add the TypeScript type**

In `resources/js/patyourself/companion.tsx`, beside the other `Bag*Data` interfaces:

```tsx
/**
 * The chest, and what is in it.
 *
 * `standing` is the server's answer and cannot be worked out here: a built
 * chest is a bag row of category `structure`, and nothing on this side reads
 * the catalogue. The clearing draws on this flag and hangs its hotspot off it,
 * so an unbuilt chest leaves neither a picture nor an invisible hit region.
 *
 * No `droppable`, by design: what is at home is never tipped out. A stack has
 * to be fetched back into the bag before it can be given up.
 */
export interface BagStashData {
    standing: boolean;
    items: { item: string; label: string; quantity: number }[];
}
```

and add to `CompanionBagData`:

```tsx
    stash: BagStashData;
```

- [ ] **Step 7: Widen the shared fixture**

In `resources/js/patyourself/companion.fixture.ts`, inside `bag()`, after `shelter:`:

```ts
        // Nothing built and nothing at home, which is what an untouched
        // clearing sends. The DEFAULT here must not draw a chest, or every
        // clearing case in the suite silently gains a sixth object — the same
        // reasoning the heap's row above records.
        stash: { standing: false, items: [] },
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php artisan test --compact --filter='an_account_with_no_companion_row_still_gets_a_stash|standing_follows_the_chest|an_unknown_stashed_item_is_skipped'
npx vitest run resources/js/patyourself resources/js/pages/companion.test.tsx
npx tsc --noEmit
```

Expected: PASS. `tsc` is what catches the fixture and the type disagreeing.

- [ ] **Step 9: Run the mutations, and record what happened**

| Mutation | Run | Expected |
| --- | --- | --- |
| Delete `'stash' => …` from the early return only | `--filter=an_account_with_no_companion_row_still_gets_a_stash` | RED — **this is the task's reason for existing** |
| Hardcode `'standing' => true` | `--filter=standing_follows_the_chest` | RED |
| Drop the `$entry === null` skip | `--filter=an_unknown_stashed_item_is_skipped` | RED |
| Remove `'stashItems'` from the eager load | the same three | **report what you saw** — the relation lazy-loads, so this may stay GREEN. If it does, say so: the eager load is an N+1 measure, not a correctness one, and claiming otherwise is the false-mutation trap |

- [ ] **Step 10: Pint, then commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php resources/js/patyourself/companion.tsx resources/js/patyourself/companion.fixture.ts tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): the stash reaches the screen, from both builders"
```

---

## Task 7: The *at home* section in the bag

**Files:**
- Modify: `resources/js/patyourself/companion-bag.tsx`
- Test: `resources/js/patyourself/companion-bag.test.tsx`
- Test: `resources/js/pages/companion.test.tsx` — the shared guard

**Interfaces:**
- Consumes: `BagStashData` and `bag.stash` (Task 6); `@/routes/companion/stash` (Task 5).
- Produces: an `AtHome` section rendered between `Held` and `Clearing`.

**TRAP 9, which has bitten three times on one branch.** `companion.test.tsx`'s
*never shows what has not happened* guard runs against a shared fixture, bag closed and bag open. A
section the fixture leaves empty does not fail the guard — **it silently drops out of what the guard
covers.** Widening the fixture is not proof the section rendered. Assert that it did.

- [ ] **Step 1: Write the failing tests**

In `resources/js/patyourself/companion-bag.test.tsx`, following the file's existing render helper:

```tsx
    it('says nothing about the chest before one is built', () => {
        renderBag(bag({ stash: { standing: false, items: [] } }));

        expect(screen.queryByText('At home')).not.toBeInTheDocument();
    });

    it('shows an at-home section with nothing in it once a chest stands', () => {
        renderBag(bag({ stash: { standing: true, items: [] } }));

        expect(screen.getByText('At home')).toBeInTheDocument();
        // An empty chest says so plainly and names nothing that could go in it.
        expect(screen.queryByRole('button', { name: /take out/i })).not.toBeInTheDocument();
    });

    it('lists what is at home with a control to fetch it back', () => {
        renderBag(
            bag({
                stash: {
                    standing: true,
                    items: [{ item: 'planks', label: 'planks', quantity: 7 }],
                },
            }),
        );

        expect(screen.getByText('planks')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Take planks out of the chest' }),
        ).toBeInTheDocument();
    });

    it('offers a put-down control for a carried stack once a chest stands', () => {
        renderBag(
            bag({
                stash: { standing: true, items: [] },
                items: [
                    {
                        item: 'planks',
                        label: 'planks',
                        category: 'material',
                        quantity: 3,
                        droppable: true,
                    },
                ],
            }),
        );

        expect(
            screen.getByRole('button', { name: 'Put planks in the chest' }),
        ).toBeInTheDocument();
    });

    it('offers no put-down control while no chest stands', () => {
        renderBag(
            bag({
                items: [
                    {
                        item: 'planks',
                        label: 'planks',
                        category: 'material',
                        quantity: 3,
                        droppable: true,
                    },
                ],
            }),
        );

        expect(
            screen.queryByRole('button', { name: 'Put planks in the chest' }),
        ).not.toBeInTheDocument();
    });
```

In `resources/js/pages/companion.test.tsx`, widen the shared guard's fixture — **in the same edit**,
add the section and assert it appeared:

```tsx
                // The at-home section renders only once a chest stands and only
                // with something in it. The bare fixture has neither, which
                // would leave this guard blind to the whole section rather than
                // failing on it — trap 9, which has bitten three times here.
                stash: {
                    standing: true,
                    items: [{ item: 'planks', label: 'planks', quantity: 7 }],
                },
```

and, inside the guard's body, before the regex assertion:

```tsx
        // Proof the section is actually in what follows. Widening the fixture
        // is not proof it rendered; an unnamed section drops silently out of
        // this guard's coverage instead of failing it.
        expect(screen.getByText('At home')).toBeInTheDocument();
```

- [ ] **Step 2: Run them to verify they fail**

```bash
npx vitest run resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx
```

Expected: FAIL — `Unable to find an element with the text: At home`.

- [ ] **Step 3: Add the section**

In `resources/js/patyourself/companion-bag.tsx`, import the route:

```tsx
import { store as stashRoute } from '@/routes/companion/stash';
```

Render it between `Held` and `Clearing`:

```tsx
                    <Held bag={bag} />
                    <AtHome bag={bag} />
                    <Clearing nodes={bag.nodes} />
```

and add the component after `Held`:

```tsx
/**
 * What is in the chest, and how much of it to fetch back.
 *
 * ABSENT UNTIL A CHEST STANDS. Not greyed, not explained, not named as
 * something that could exist — the same silence a stage whose predecessor is
 * missing gets, and the same silence an unmet skill gets. A section describing
 * a chest nobody built would be the app naming the thing to go and get.
 *
 * An empty chest still shows its heading, because at that point the chest is a
 * thing that has happened: it is standing in the clearing and it is empty, and
 * saying so is a fact rather than a placeholder.
 *
 * No drop control here, by rule. What is at home is never tipped out — a stack
 * has to be fetched back into the bag first — so the only destroying gesture in
 * this feature stays in one place, on things Blob is actually carrying.
 */
function AtHome({ bag }: { bag: CompanionBagData }) {
    if (!bag.stash.standing) {
        return null;
    }

    if (bag.stash.items.length === 0) {
        return (
            <>
                <p className="c-baggrp">At home</p>
                <p className="c-bagnone">The chest is empty.</p>
            </>
        );
    }

    return (
        <>
            <p className="c-baggrp">At home</p>
            <ul className="c-bagrows">
                {bag.stash.items.map((item) => (
                    <li key={item.item} className="c-bagrow">
                        <span>{item.label}</span>
                        <Form
                            {...stashRoute.form()}
                            options={{ preserveScroll: true }}
                            className="c-bagbuy"
                        >
                            {({ processing }) => (
                                <>
                                    <b className="c-bagqty">{item.quantity}</b>
                                    <input
                                        type="hidden"
                                        name="item"
                                        value={item.item}
                                    />
                                    <input
                                        type="hidden"
                                        name="direction"
                                        value="out"
                                    />
                                    <input
                                        type="number"
                                        name="amount"
                                        min={1}
                                        max={item.quantity}
                                        defaultValue={1}
                                        className="c-bagtake"
                                        aria-label={`How much ${item.label} to take out`}
                                    />
                                    <button
                                        type="submit"
                                        className="pixel-button"
                                        disabled={processing}
                                        aria-label={`Take ${item.label} out of the chest`}
                                    >
                                        take out
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

- [ ] **Step 4: Add the put-down control to `Held`**

`Held` needs `bag.stash.standing` — it already takes the whole `bag`. Inside the `<span className="c-bagheld">`, before the existing `{item.droppable && …}` block:

```tsx
                            {/* Offered only once a chest stands, and only for
                                what the bag actually carries. Before that there
                                is nowhere to put anything down, and a control
                                that named one would be naming a thing to go and
                                build. */}
                            {bag.stash.standing && item.droppable && (
                                <Form
                                    {...stashRoute.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="item"
                                                value={item.item}
                                            />
                                            <input
                                                type="hidden"
                                                name="direction"
                                                value="in"
                                            />
                                            <button
                                                type="submit"
                                                className="c-bagdrop"
                                                disabled={processing}
                                                aria-label={`Put ${item.label} in the chest`}
                                            >
                                                put away
                                            </button>
                                        </>
                                    )}
                                </Form>
                            )}
```

The put-away control sends no `amount`, so it moves the whole stack — parking three planks to keep
sawing is the gesture, and a second number field on every carried row would crowd it.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx vitest run resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx
npx tsc --noEmit
npx prettier --check resources/js/patyourself/companion-bag.tsx
```

**Only those named files.** Never `npm run format` or `npm run lint`.

- [ ] **Step 6: Run the mutations, and record what happened**

| Mutation | Run | Expected |
| --- | --- | --- |
| `if (!bag.stash.standing)` → `if (false)` | `--filter` on *says nothing about the chest before one is built* | RED |
| Drop `bag.stash.standing &&` from the put-away condition | *offers no put-down control while no chest stands* | RED |
| Delete the `expect(screen.getByText('At home'))` line you added to the shared guard | `companion.test.tsx` | **GREEN** — and that is the point: the guard alone cannot see the section, which is why the explicit assertion is there. Report this result plainly |

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx
git commit -m "feat(companion): the bag grows a section for what is at home"
```

---

## Task 8: The chest's art

**Files:**
- Create: `resources/js/patyourself/scenes/node-chest.png`
- Modify: `resources/js/patyourself/scenes/README.md` — the table row and the job ids

**Interfaces:**
- Consumes: nothing in code.
- Produces: a committed PNG, cropped to its own bounding box, and **its measured cell size in room
  units**, which Task 9 reads.

**This task needs the owner's eyes and cannot be completed by assertion.** It is the one task here
whose acceptance is a judgement.

- [ ] **Step 1: Take the style crop**

The chest stands beside the shelter, so its reference is the ground the shelter stands on. The
shelter's own crop is `PNG col 92, row 14`. `sips --cropOffset` takes **row then column** — the
opposite order from the prose:

```bash
cd resources/js/patyourself/scenes
sips -c 48 48 --cropOffset 14 92 forest-day.png --out /tmp/chest-style.png
```

Getting the two numbers backwards silently crops the wrong patch of forest. This branch's history
already paid once for confusing a room `x` with a PNG column.

- [ ] **Step 2: Quantise the reference**

```python
from PIL import Image
Image.open('/tmp/chest-style.png').quantize(colors=32).save('/tmp/chest-style-q.png')
```

`style_images` is passed as base64 and truncates in transit past roughly 1KB. Quantise the
**composite**, never a transparent cutout — quantising transparency turns it black and teaches the
model to draw a black backing.

- [ ] **Step 3: Generate**

`create_1_direction_object`, `view: sidescroller`, description along the lines of *a small closed
wooden chest with iron bands and a flat lid, sitting on grass*, with the quantised crop as
`style_images`.

Note: `style_images` forces a **square** output and cannot be combined with `size`. 48px sits in the
≤85 tier and returns 16 candidates.

**Budget roughly 8 minutes and do not poll in a tight loop.** Start the job, do something else, come
back. A poll-wait loop with no working sleep primitive cost 107k tokens on an earlier phase.

- [ ] **Step 4: Judge the candidates over the real backdrop**

Composite every candidate over `forest-day.png` **at the position Task 9 will use**, with the real
Blob sprite present, and publish the sheet with `Artifact`.

`SendUserFile` is **not available to a subagent** — a brief naming it fails for that reason alone,
repeatedly, on this branch's history. Use `Artifact`.

Never judge cutouts on transparency: a shape that will never read correctly in place still looks
fine there.

**Stop and get the owner's pick.** Do not choose for them.

- [ ] **Step 5: Promote, tag, crop, commit**

```
select_object_frames   # keep the chosen candidate, promoting it out of review
dismiss_review         # discard the rest; nothing here is automatic
update_object_tags     # f41-chest, so list_objects finds it again
```

Then crop to the art's own bounding box:

- Every generated cell carries **6 to 12 transparent rows below its art**. Composited raw at the
  cell's own bottom edge the object levitates in front of the treeline.
- **The crop is what sets the cell.** The 48px square the generator forces is not the cell.

Record the measured `[width, height]` — Task 9 needs it.

- [ ] **Step 6: Record it in the art log**

Add to `scenes/README.md` a `# The chest` section with the file, the measured cell, the object id,
the review id and the chosen candidate — the same columns the nodes' table carries.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/scenes/node-chest.png resources/js/patyourself/scenes/README.md
git commit -m "feat(companion): the chest, drawn"
```

---

## Task 9: The chest in the clearing

**Files:**
- Modify: `resources/js/patyourself/scenes.ts` — `CHEST_CELL`, `chestSprite()`, `SceneSpec.chest`,
  the forest's coordinate
- Modify: `resources/js/patyourself/companion-room.tsx` — `ChestLayer`
- Modify: `resources/js/pages/companion.tsx` — the hotspot
- Test: `resources/js/patyourself/scenes.test.ts`, `companion-room.test.tsx`,
  `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: Task 8's PNG and its measured cell; `bag.stash.standing` (Task 6);
  `@/routes/companion/stash` is **not** used here — the hotspot opens the bag rather than posting.
- Produces: a chest drawn only when `standing`, with a hit region derived from its own cell.

- [ ] **Step 1: Decide the coordinate by rendering, not by arithmetic**

Constraints, all inherited:

- The chest is the **fifth** object in a room whose four node cells already need 186 units of width
  in 144, where overlap is forced rather than chosen.
- **Array order in `scenes.ts` is paint order**, back to front, and the page's hotspot loop follows
  it — so the nearer control sits above the further one where hit regions overlap.
- Nearer things sit lower and overlap further things.
- The chest exists only once built, so it costs nothing to accounts without one.

**Starting hypothesis, not a decision: beside the shelter at x ≈ 44.** A chest at home reads as at
home, and that column is already the built-things column.

Build the page, serve it — **Herd never serves a worktree** — and look:

```bash
npm run build
php -S 127.0.0.1:8899 -t public
```

Sweep the coordinate, photograph the results, and **get the owner's pick before writing it into
`scenes.ts`.** Four heights were rendered before the shelter's `y=34` was believed, and the measured
ground line it replaced turned out to be the clearing's back wall.

- [ ] **Step 2: Write the failing tests**

In `resources/js/patyourself/scenes.test.ts`:

```ts
    it('sizes the chest from art that exists', () => {
        expect(chestSprite()).toMatch(/node-chest/);
        expect(CHEST_CELL[0]).toBeGreaterThan(0);
        expect(CHEST_CELL[1]).toBeGreaterThan(0);
    });

    it('stands the chest inside the room on both axes', () => {
        // The room's viewBox, written out because this file already writes it
        // out rather than importing `ROOM` from `companion-room`.
        const [left, right, floor] = [-72, 72, 76];

        const at = SCENES.forest.chest;

        expect(at).toBeDefined();

        const [x, y] = at!;

        // `at` is the base centre, so the cell hangs up and left of it.
        expect(x - CHEST_CELL[0] / 2).toBeGreaterThanOrEqual(left);
        expect(x + CHEST_CELL[0] / 2).toBeLessThanOrEqual(right);
        expect(y).toBeLessThanOrEqual(floor);
        expect(y - CHEST_CELL[1]).toBeGreaterThanOrEqual(-38);
    });
```

In `resources/js/patyourself/companion-room.test.tsx`:

```tsx
    it('draws no chest until one is standing', () => {
        renderRoom({ stashStanding: false });

        expect(document.querySelector('.chest-layer')).toBeNull();
    });

    it('draws the chest once one is standing', () => {
        renderRoom({ stashStanding: true });

        expect(document.querySelector('.chest-layer')).not.toBeNull();
    });
```

In `resources/js/pages/companion.test.tsx`:

```tsx
    it('offers no chest control until one is standing', () => {
        renderPage({ bag: bag({ stash: { standing: false, items: [] } }) });

        expect(
            screen.queryByRole('button', { name: /chest/i }),
        ).not.toBeInTheDocument();
    });

    it('opens the bag from the chest once one is standing', async () => {
        renderPage({ bag: bag({ stash: { standing: true, items: [] } }) });

        await userEvent.click(screen.getByRole('button', { name: /chest/i }));

        expect(screen.getByText('At home')).toBeInTheDocument();
    });
```

Follow each file's own existing render helper rather than the invented names above.

- [ ] **Step 3: Run them to verify they fail**

```bash
npx vitest run resources/js/patyourself/scenes.test.ts resources/js/patyourself/companion-room.test.tsx resources/js/pages/companion.test.tsx
```

- [ ] **Step 4: Add the registry entries**

In `scenes.ts`, beside `SHELTER_CELL` and `shelterSprite()`:

```ts
/**
 * The chest's cell, MEASURED off the cropped art rather than chosen.
 *
 * `create_1_direction_object` forces a 48x48 square and the crop to the art's
 * own bounding box is what sets this — the generator's square is not the cell.
 * Five objects cannot stand apart in a 144-unit room, so this number is part of
 * what the placement pass had to fit.
 */
export const CHEST_CELL: readonly [number, number] = [WIDTH, HEIGHT];

/** The chest's one sprite. It has no bands: a chest is a chest, and what is in it is in the bag. */
export function chestSprite(): string {
    return chestPng;
}
```

and on `SceneSpec`:

```ts
    /**
     * Where the chest stands, when one has been built. Absent indoors.
     *
     * Placement only, exactly as `nodes` and `shelter` are: WHETHER one is
     * standing comes from the server's `bag.stash.standing`, never from here.
     *
     * Like `shelter` and unlike `FoliageSpec.at`, this is the BASE CENTRE —
     * where the chest stands, not a cell's top-left. The art's top-left derives
     * as `(x − CHEST_CELL[0] / 2, y − CHEST_CELL[1])`.
     */
    chest?: readonly [number, number];
```

`WIDTH` and `HEIGHT` are the two numbers Task 8 measured off the cropped PNG and reported. **This
task is blocked until it has them** — they are not a choice to make here, and guessing them puts the
chest on a different grid from every other object in the room.

Import the PNG the way the shelter's three are imported at the top of `scenes.ts`:

```ts
import chestPng from './scenes/node-chest.png';
```

Add the chosen coordinate to the forest scene **with a comment recording the render that chose it,
not the arithmetic** — the standard `scenes.ts` has held since F3.5. Place it in the `nodes`-style
paint order position the render settled: array order is back to front, and the chest sits wherever
that render put it relative to the shelter and the reeds.

- [ ] **Step 5: Draw it**

In `companion-room.tsx`, add the prop beside `shelter` and `nodes`:

```tsx
    /**
     * Whether a chest is standing. The server's answer, exactly as `shelter`
     * and `nodes` are — a built chest is a bag row of category `structure`, and
     * nothing on this side reads the catalogue.
     */
    chestStanding?: boolean;
```

defaulting to `chestStanding = false` in the destructure, and the layer after `ShelterLayer`:

```tsx
/**
 * The chest, when one has been built.
 *
 * One sprite and no bands. A node is drawn at the amount standing at it because
 * the amount is the thing you are deciding about; a chest is a chest, and what
 * is in it is in the bag, named exactly rather than banded. Giving it bands
 * would buy a picture the modal already gives precisely.
 *
 * A single frame and no clock, the same as the shelter: the tree and the grass
 * are sheets because this scene exists to carry wind, and a box does not sway.
 */
function ChestLayer({ at }: { at: readonly [number, number] }) {
    return (
        <image
            data-chest="standing"
            href={chestSprite()}
            // `at` is the base centre, so the cell hangs up and left of it —
            // the same contract `SceneSpec.shelter` carries.
            x={at[0] - CHEST_CELL[0] / 2}
            y={at[1] - CHEST_CELL[1]}
            width={CHEST_CELL[0]}
            height={CHEST_CELL[1]}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}
```

and render it inside the existing `!indoors` branch, gated so an unbuilt chest draws nothing:

```tsx
                    {chestStanding && scene.chest !== undefined && (
                        <ChestLayer at={scene.chest} />
                    )}
```

- [ ] **Step 6: Add the hotspot**

In `pages/companion.tsx`, beside the shelter's own control, inside the same `!inside` block:

```tsx
                {/* The chest, once one is standing. Clicking it opens the bag
                    at what is at home — it posts nothing, because deciding how
                    much to move is a bag question and the clearing is where a
                    thing IS. Absent entirely until one is built, so there is
                    never an invisible hit region here. */}
                {!inside &&
                    bag.stash.standing &&
                    chestAt !== undefined && (
                        <ChestSpot
                            at={roomOffset(
                                chestAt[0],
                                chestAt[1] - CHEST_CELL[1] / 2,
                            )}
                            onClick={() => {
                                recoverFocus.current = true;
                                setBagOpen(true);
                            }}
                        />
                    )}
```

with `const chestAt = sceneFor(companion.scene).chest;` beside the existing `shelterAt`, and the
button itself beside `ShelterSpot`:

```tsx
/**
 * The chest's hit region: the art's own cell, in room units, so it scales with
 * the picture instead of holding a fixed pixel size the way the retired labels
 * did.
 */
function ChestSpot({
    at,
    onClick,
}: {
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-chest', 'c-node--art')}
            style={{ ...at, ...roomSize(CHEST_CELL[0], CHEST_CELL[1]) }}
            // The picture says what it is; the name says what pressing does.
            aria-label="Open the chest"
            onClick={onClick}
        />
    );
}
```

**Check what `setBagOpen` is actually called in this file** and use the real name; the bag's open
state is held in `CompanionPage` and the shelter's control already reaches a sibling setter, so
follow whatever that does rather than the name above.

`roomSize` already floors a hit region at 24px (WCAG 2.5.8 AA), so the chest inherits it with
nothing to add. Add a `.c-chest` rule to `resources/css/patyourself.css` only if `.c-node--art`
does not already give the right stacking — **and never run `npm run format` on that file.**

- [ ] **Step 7: Run everything and look at it**

```bash
npx vitest run resources/js/patyourself resources/js/pages
npx tsc --noEmit
npx prettier --check resources/js/patyourself/scenes.ts resources/js/patyourself/companion-room.tsx resources/js/pages/companion.tsx
npm run build
php -S 127.0.0.1:8899 -t public
```

**jsdom has no layout engine and never will.** Everything about how this LOOKS is a render task with
the owner's eyes on it. Build it, serve it, photograph the clearing with a chest standing and
without, and get the owner's sign-off before committing.

Note: Blob's frames freeze under browser automation — the clock stops on `document.hidden`, so
`data-frame` reads 0 forever. Trust `data-animation` and `data-part-of-day`.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/scenes.ts resources/js/patyourself/companion-room.tsx resources/js/pages/companion.tsx resources/js/patyourself/scenes.test.ts resources/js/patyourself/companion-room.test.tsx resources/js/pages/companion.test.tsx
git commit -m "feat(companion): the chest stands where the render put it"
```

---

## Task 10: The record

**Files:**
- Modify: `docs/BLOB.md`
- Modify: `resources/js/patyourself/scenes/README.md`

**Interfaces:** consumes every earlier task's measured results.

- [ ] **Step 1: Update `docs/BLOB.md`**

- **§3** — `companion_stash_items` joins the stored tables, with the one-sentence reason it is not a
  column.
- **§8** — the stash beside the bag: uncapped, spent from never, and the sawing arithmetic it
  unblocks. Record that `DropItem` becomes rarely needed and that this is accepted.
- **§9** — the new files in the map.
- **§10** — two rulings, each with its cost-if-wrong column:
  - *The stash is its own table, never a column on `companion_items`* — because `held()` and
    `capacity()` sum `$this->items` unfiltered.
  - *A recipe spends from the bag alone* — because `wouldFit()` is the one arithmetic the refusing
    action and the predicting read share.
- **§12** — strike "F4 (depth) is what remains" and replace it with the three-way decomposition,
  F4.1 done, F4.2 and F4.3 outstanding, and **"more skills" cut with the two measurements that cut
  it.**

**`docs/BLOB.md` must stay off `CompanionVocabularyTest::sourceFiles()`** — it quotes the banned
words in order to document them.

- [ ] **Step 2: Update `scenes/README.md`**

Complete the `# The chest` section Task 8 started with what the placement pass actually decided, the
coordinates rendered and rejected, and the reason the chosen one won — the render, not the
arithmetic.

- [ ] **Step 3: Run the whole suite**

```bash
php artisan test --compact
npm test
```

Expected: 1385+ and 727+ green. **Report the actual counts**, not "all passing".

- [ ] **Step 4: Commit**

```bash
git add docs/BLOB.md resources/js/patyourself/scenes/README.md
git commit -m "docs(companion): record what F4.1 decided and what it measured"
```

---

## Batches

Stop at each boundary for review.

| Batch | Tasks | Deliverable |
| --- | --- | --- |
| 1 | 1–2 | the chest is buildable once; the stash has a table that cannot corrupt the bag |
| 2 | 3–5 | stacks move both ways, through one route, in Blob's voice |
| 3 | 6–7 | the stash reaches the screen and the bag has a section for it |
| 4 | 8–9 | the chest is drawn and stands where a render put it |
| 5 | 10 | the record |

**Batches 1–3 are entirely server-and-modal work and need no art.** Batch 4 is the only one that
needs the owner's eyes, and Batch 5 depends on its measured results.

## Dispatch rule

**Put every value a decision has overtaken at the TOP of a dispatch, not inside a code block in the
brief.** A superseded number quoted in passing inside a snippet is how three coordinates shipped
wrong on F3.5.
