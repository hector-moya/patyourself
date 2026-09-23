# Companion F4.2 — Consumption Proper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the economy a sink that never closes — a pile inside the shelter that takes wood one-way, uncapped, and moves Blob's night indoors.

**Architecture:** One `unsignedInteger` column on `companions`, one action that only ever increments it, one route, one payload key, one hand-drawn SVG in the interior, and one `useState` initialiser. No new table, no new node, no new sprite, no Pixel Lab job.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia v3 + React 19, Wayfinder, PHPUnit 12, Vitest.

**Spec:** `docs/superpowers/specs/2026-09-23-companion-f4.2-consumption-design.md`

## Global Constraints

Copied verbatim from the spec and from `docs/BLOB.md`. **Every task's requirements implicitly include this section.**

- **Banned vocabulary**, scanned by `CompanionVocabularyTest` over a list of source files, **comments included**: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. **`points` is a substring trap** — *appoints* and *disappoints* trip it.
- **Every new companion source file must be added to `CompanionVocabularyTest::sourceFiles()` in the same task that creates it.** Two files qualify in this plan: `app/Actions/StackWood.php` and `app/Http/Controllers/CompanionWoodpileController.php`.
- **`docs/BLOB.md` is deliberately NOT on that list and must not be added** — it quotes the banned words in order to document them.
- **Copy rules:** sentence case, one or two sentences, no exclamation marks, never congratulating, `{name}` rather than the literal, describes what Blob did and never how the player is doing, never states a plan.
- **The amount in the pile is NEVER rendered as text.** Only as the picture's size. A `data-` attribute is a test seam and is not text; visible digits are a total and break arc §4.
- **`asleepAt()` and `wakingAt()` must not learn about the pile.** Whether Blob sleeps is the clock alone; only *where* follows from what was built.
- **Never run `npm run format` or `npm run lint`.** `format` rewrites a hand-formatted CSS file (once turned a 94-line change into 3782 lines); `lint` descends into `.claude/worktrees/*` and has silently rewritten 118 files. Scope to named files only. `companion-bag.test.tsx` and `companion.fixture.ts` already fail `prettier --check` on main — pre-existing, leave them.
- **PHP formatting:** run `vendor/bin/pint --dirty --format agent` before each commit that touches PHP.
- **Test commands:** `php artisan test --compact --filter=<name>` and `npm test -- <path>`.
- **A stated mutation nobody ran is not evidence.** Every task that names a mutation must **apply it, run it, observe the result, revert it, and record what was actually seen.** A mutation that stays green is a finding about the test, not a failure — report it.
- **A ceiling has two shapes:** a clamp (limit this call) and a threshold (refuse once N is held). A test starting from an empty state can only ever see the first.
- **Look for the second builder.** `CompanionBag::forUser()` has two returns — the main one and `worldBeforeAnythingHappened()`'s early return for accounts with no companion row. A key added to one and not the other is invisible to the type system; both satisfy `array`.
- **jsdom has no layout engine.** Anything about how it *looks* is a render task, not a unit test.

---

## File Structure

**Created:**
- `database/migrations/2026_09_23_000001_add_the_woodpile_to_companions_table.php` — the column
- `app/Actions/StackWood.php` — the only writer of `companions.woodpile`
- `app/Http/Controllers/CompanionWoodpileController.php` — the one route's one direction
- `tests/Feature/Companion/StackWoodTest.php` — the action
- `tests/Feature/Companion/CompanionWoodpileScreenTest.php` — the route, both 404s, the copy
- `tests/Unit/Companion/CompanionWoodpileContentTest.php` — config's own consistency

**Modified:**
- `config/companion.php` — one `woodpile` block
- `app/Models/Companion.php` — `$fillable`, `$attributes`, `capacity()` (Batch 5)
- `app/Services/Companion/CompanionBag.php` — `forUser()` both builders, `woodpile()`, `items()` (Batch 5)
- `routes/web.php` — one POST
- `resources/js/patyourself/companion.tsx` — `CompanionBagData` type
- `resources/js/patyourself/companion-bag.tsx` — the `stack it` control
- `resources/js/patyourself/companion-room.tsx` — the `Woodpile` drawing
- `resources/js/pages/companion.tsx` — the `inside` initialiser
- `resources/js/patyourself/companion.fixture.ts` — widened for the woodpile
- `resources/js/patyourself/companion.harness.tsx` — a `renderAtHome` sibling
- `tests/Feature/Companion/CompanionVocabularyTest.php` — two new source files
- `app/Actions/HarvestNode.php`, `app/Actions/BuildItem.php` — comment corrections (Batch 5)
- `docs/BLOB.md` — §8, §9, §10, §12 (Batch 6)

---

# Batch 1 — The column, the config, the action

### Task 1: The column

**Files:**
- Create: `database/migrations/2026_09_23_000001_add_the_woodpile_to_companions_table.php`
- Modify: `app/Models/Companion.php` (`$fillable`, `$attributes`)
- Test: `tests/Feature/Companion/StackWoodTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `companions.woodpile` — `unsignedInteger`, default `0`, readable as `$companion->woodpile` (int).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Companion/StackWoodTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackWoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_companion_starts_with_nothing_stacked(): void
    {
        $user = User::factory()->create();

        $companion = $user->companion()->create([]);

        $this->assertSame(0, $companion->woodpile);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=StackWoodTest`
Expected: FAIL — no `woodpile` column, so the attribute is undefined.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_23_000001_add_the_woodpile_to_companions_table.php`:

```php
<?php

use App\Actions\StackWood;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much wood is stacked against the shelter's wall.
 *
 * ONE INTEGER RATHER THAN A TABLE, because nothing ever comes back out. A pile
 * that could be emptied would be a third place to keep things, and the chest is
 * already the second. With no withdrawal there is nothing to remember about
 * what went in, so there is nothing to give rows to.
 *
 * It is a CHOICE rather than a count of the record — the same standing
 * `xp_spent` and `shelter` already have, and the reason "store only what cannot
 * be derived" permits it. Nothing about it reads how much has been logged, and
 * nothing may.
 *
 * It only ever rises. {@see StackWood} is its only writer, and there is no
 * route in the application that lowers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->unsignedInteger('woodpile')->default(0)->after('shelter');
        });
    }

    public function down(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->dropColumn('woodpile');
        });
    }
};
```

- [ ] **Step 4: Add the attribute to the model**

In `app/Models/Companion.php`, add `'woodpile'` to the `$fillable` array (after `'shelter'`), and `'woodpile' => 0` to the `$attributes` array (after `'xp_spent' => 0`). Read the surrounding lines first and match their formatting exactly.

- [ ] **Step 5: Run the test and watch it pass**

Run: `php artisan test --compact --filter=StackWoodTest`
Expected: PASS.

- [ ] **Step 6: Run the storage suite, which knows the table's shape**

Run: `php artisan test --compact --filter=CompanionStorageTest`
Expected: PASS — a new nullable-with-default column breaks nothing.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Companion.php tests/Feature/Companion/StackWoodTest.php
git commit -m "feat(companion): one integer for a pile that never gives anything back"
```

---

### Task 2: What the pile takes, and what Blob says about it

**Files:**
- Modify: `config/companion.php`
- Test: `tests/Unit/Companion/CompanionWoodpileContentTest.php`

**Interfaces:**
- Consumes: `config('companion.bag')`, `config('companion.capacity.carried')`.
- Produces: `config('companion.woodpile.takes')` → `list<string>`; `config('companion.woodpile.stacked')` → `string` template with `{name}`, `{count}`, `{label}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Companion/CompanionWoodpileContentTest.php`:

```php
<?php

namespace Tests\Unit\Companion;

use Tests\TestCase;

class CompanionWoodpileContentTest extends TestCase
{
    public function test_everything_the_pile_takes_is_something_blob_can_carry(): void
    {
        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes');

        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag');

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried');

        $this->assertNotEmpty($takes);

        foreach ($takes as $item) {
            $this->assertArrayHasKey($item, $catalogue, "[{$item}] is not in the bag's catalogue.");
            $this->assertContains(
                $catalogue[$item]['category'],
                $carried,
                "[{$item}] is not a carried category, so it can never be in the bag to stack.",
            );
        }
    }

    public function test_the_line_blob_says_names_what_it_stacked(): void
    {
        $line = (string) config('companion.woodpile.stacked');

        $this->assertStringContainsString('{name}', $line);
        $this->assertStringContainsString('{count}', $line);
        $this->assertStringContainsString('{label}', $line);
        $this->assertStringNotContainsString('!', $line);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=CompanionWoodpileContentTest`
Expected: FAIL — `assertNotEmpty` on a missing config key.

- [ ] **Step 3: Add the config block**

In `config/companion.php`, immediately after the `'drop' => [...]` block, add:

```php
    /*
     * The pile against the shelter's wall.
     *
     * `takes` is NOT a second copy of `capacity.carried`. That list says what
     * the bag may hold; this one says what this one object accepts, which is
     * the kind of statement a recipe has always made about its ingredients.
     * Fibre and rope are not wood.
     *
     * A plank and a deadfall count the same. The pile's job is to absorb
     * whatever there is too much of, and it is a picture rather than a list of
     * what is in it.
     *
     * ONE LINE ONLY, and no refusal line. The pile is uncapped, so there is no
     * sentence in which it says no; and it is never offered without a shelter
     * standing, so there is no sentence for that either.
     */
    'woodpile' => [
        'takes' => ['deadfall', 'timber', 'planks'],
        'stacked' => '{name} stacks {count} {label} against the wall.',
    ],
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `php artisan test --compact --filter=CompanionWoodpileContentTest`
Expected: PASS.

- [ ] **Step 5: Run the vocabulary guard — `config/companion.php` is scanned, comments included**

Run: `php artisan test --compact --filter=CompanionVocabularyTest`
Expected: PASS.

- [ ] **Step 6: Apply the mutation, RUN it, record what you saw**

Change `'takes' => ['deadfall', 'timber', 'planks']` to `'takes' => ['deadfall', 'timber', 'planks', 'axe']` and run `php artisan test --compact --filter=CompanionWoodpileContentTest`.
Expected: RED on `test_everything_the_pile_takes_is_something_blob_can_carry` — `axe` is a `tool`, which is not carried.
**Revert the mutation.** Record the observed result in the commit body.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php tests/Unit/Companion/CompanionWoodpileContentTest.php
git commit -m "feat(companion): the pile takes wood, and says so in one line"
```

---

### Task 3: `StackWood`

**Files:**
- Create: `app/Actions/StackWood.php`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` (add the new file to `sourceFiles()`)
- Test: `tests/Feature/Companion/StackWoodTest.php`

**Interfaces:**
- Consumes: `config('companion.bag')`, `config('companion.woodpile.takes')`, `Companion::items()`, `companions.woodpile`.
- Produces: `StackWood::handle(User $user, string $item, ?int $wanted = null): int` — returns how many units went into the pile. Throws `InvalidArgumentException` for an unauthored item or one the pile does not take.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/StackWoodTest.php` (add `use App\Actions\StackWood;` and `use App\Models\Companion;` to the imports):

```php
    public function test_stacking_moves_the_whole_stack_into_the_pile(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'deadfall', 'quantity' => 4]);

        $moved = app(StackWood::class)->handle($user, 'deadfall');

        $this->assertSame(4, $moved);
        $this->assertSame(4, $companion->fresh()->woodpile);
        $this->assertSame(0, $companion->items()->where('item', 'deadfall')->count());
    }

    public function test_stacking_an_amount_leaves_the_rest_in_the_bag(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 5]);

        $moved = app(StackWood::class)->handle($user, 'planks', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(2, $companion->fresh()->woodpile);
        $this->assertSame(
            3,
            $companion->items()->where('item', 'planks')->value('quantity'),
        );
    }

    /**
     * A CEILING HAS TWO SHAPES: a clamp that limits this call, and a threshold
     * that refuses once N is already held. A test starting from an empty pile
     * can only ever see the first, so this one starts from a pile that already
     * holds a great deal and stacks onto it.
     */
    public function test_the_pile_takes_more_however_much_is_in_it(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create(['woodpile' => 9_000]);
        $companion->items()->create(['item' => 'timber', 'quantity' => 7]);

        $moved = app(StackWood::class)->handle($user, 'timber');

        $this->assertSame(7, $moved);
        $this->assertSame(9_007, $companion->fresh()->woodpile);
    }

    public function test_the_pile_refuses_what_it_does_not_take(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $this->expectException(\InvalidArgumentException::class);

        app(StackWood::class)->handle($user, 'fibre');
    }

    public function test_stacking_what_is_not_held_moves_nothing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);

        $moved = app(StackWood::class)->handle($user, 'deadfall');

        $this->assertSame(0, $moved);
        $this->assertSame(0, $companion->fresh()->woodpile);
    }

    public function test_the_bag_loses_exactly_what_the_pile_gains(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 6]);

        $before = $companion->fresh()->held();

        $moved = app(StackWood::class)->handle($user, 'planks', 4);

        $this->assertSame(4, $moved);
        $this->assertSame($before - 4, $companion->fresh()->held());
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=StackWoodTest`
Expected: FAIL — `App\Actions\StackWood` does not exist.

- [ ] **Step 3: Write the action**

Create `app/Actions/StackWood.php`:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob stacks wood against the wall, and it does not come back.
 *
 * THIS IS THE ONE-WAY MOVE. Material entering the pile is SPENT, exactly as
 * fibre spent on a basket is spent: the player asked, the price was named, and
 * nothing was taken on their behalf. {@see DropItem} remains the only path that
 * DESTROYS — the difference is that this one leaves something standing where
 * the material went.
 *
 * THE PILE IS UNCAPPED, for the same reason the chest is: the world is uncapped
 * everywhere else in this feature, and a limit here would be the first place
 * the world refuses to hold something. There is no room check in either
 * direction, because there is no other direction.
 *
 * Only what `companion.woodpile.takes` names. That is not a second copy of
 * `capacity.carried`: that list says what the bag may hold, this one says what
 * this object accepts, which is what a recipe has always said about its own
 * ingredients.
 *
 * THE ONLY WRITER OF `companions.woodpile`, and it only ever increments.
 *
 * A SHELTER IS NOT CHECKED HERE. The pile stands inside one, so the screen
 * never offers this without one and the controller answers a direct request
 * with a 404 — a state of the request rather than a state of the world. Nothing
 * in this action depends on where the pile is.
 *
 * Nothing here reads the record. The pile is a choice, and whether Blob sleeps
 * stays a function of the clock alone; only WHERE it sleeps follows from what
 * was built.
 */
final readonly class StackWood
{
    /**
     * @param  ?int  $wanted  How much to stack, or null for the whole stack.
     * @return int How many units went into the pile.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  the pile does not take it.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes', []);

        if (! in_array($item, $takes, true)) {
            throw new InvalidArgumentException("The pile does not take [{$item}].");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            // Floored at one rather than clamped to zero, the same as a harvest
            // and the same as putting something in the chest: a caller asking
            // for nothing has asked a question this action has no answer for.
            $moved = $wanted === null
                ? $stack->quantity
                : min($stack->quantity, max(1, $wanted));

            // Deleted rather than zeroed, the rule the bag already follows: an
            // empty row would render as a line saying Blob is carrying no
            // planks, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->increment('woodpile', $moved);

            return $moved;
        });
    }
}
```

- [ ] **Step 4: Add the file to the vocabulary list**

In `tests/Feature/Companion/CompanionVocabularyTest.php`, inside `sourceFiles()`, add after the `UnstashItem.php` line:

```php
            $root.'/app/Actions/StackWood.php',
```

- [ ] **Step 5: Run both suites and watch them pass**

Run: `php artisan test --compact --filter=StackWoodTest`
Expected: PASS, 7 tests.

Run: `php artisan test --compact --filter=CompanionVocabularyTest`
Expected: PASS.

- [ ] **Step 6: Apply three mutations, RUN each, record what you saw**

Apply one at a time, run, observe, **revert before the next**:

| Mutation | Expected red |
| --- | --- |
| Replace `$companion->increment('woodpile', $moved)` with `$companion->increment('woodpile', min($moved, 5))` | `test_the_pile_takes_more_however_much_is_in_it` and `test_stacking_moves_the_whole_stack_into_the_pile` |
| Add `if ($companion->woodpile >= 100) { return 0; }` above the increment | `test_the_pile_takes_more_however_much_is_in_it` ONLY — this is the threshold shape, and it is why that test starts from 9,000 |
| Delete the `in_array($item, $takes, true)` guard | `test_the_pile_refuses_what_it_does_not_take` |

Record each observed result in the commit body. If any stays green, say so — that is a finding about the test.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/StackWood.php tests/Feature/Companion/StackWoodTest.php tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(companion): wood goes onto the pile and does not come back"
```

---

# Batch 2 — The route

**STOP at the end of this batch for review.**

### Task 4: `CompanionWoodpileController` and its one POST

**Files:**
- Create: `app/Http/Controllers/CompanionWoodpileController.php`
- Modify: `routes/web.php`, `tests/Feature/Companion/CompanionVocabularyTest.php`
- Test: `tests/Feature/Companion/CompanionWoodpileScreenTest.php`

**Interfaces:**
- Consumes: `StackWood::handle()`, `CompanionController::SAID_KEY`, `CompanionController::STAY_KEY`, `Companion::nameFor()`.
- Produces: route `companion.woodpile.store` → `POST companion/woodpile`, accepting `item` (required string) and `amount` (nullable integer, min 1). Wayfinder emits `@/routes/companion/woodpile`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Companion/CompanionWoodpileScreenTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompanionWoodpileScreenTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAShelter(): User
    {
        $user = User::factory()->create();
        $user->companion()->create(['shelter' => 'lean-to']);

        return $user;
    }

    public function test_stacking_wood_raises_the_pile_and_says_so(): void
    {
        $user = $this->userWithAShelter();
        $user->companion->items()->create(['item' => 'deadfall', 'quantity' => 3]);

        $response = $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall']);

        $response->assertRedirect();
        $response->assertSessionHas('companion_said');

        $this->assertSame(3, $user->companion->fresh()->woodpile);
        $this->assertStringContainsString(
            'against the wall',
            (string) session('companion_said'),
        );
    }

    public function test_what_the_pile_does_not_take_is_not_a_route(): void
    {
        $user = $this->userWithAShelter();
        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'fibre'])
            ->assertNotFound();
    }

    public function test_there_is_no_pile_without_a_shelter_to_stand_it_in(): void
    {
        $user = User::factory()->create();
        $user->companion()->create([]);
        $user->companion->items()->create(['item' => 'deadfall', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall'])
            ->assertNotFound();

        $this->assertSame(0, $user->companion->fresh()->woodpile);
    }

    public function test_a_press_that_moved_nothing_says_nothing(): void
    {
        $user = $this->userWithAShelter();

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall'])
            ->assertRedirect()
            ->assertSessionMissing('companion_said');
    }

    /**
     * NOTHING COMES BACK OUT. The guard is the absence of a route: a later
     * phase adding an "unstack" would make the pile a third place to keep
     * things, and §16 of the spec names that as the first thing that would make
     * this design wrong.
     */
    public function test_no_route_lowers_the_pile(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(static fn (string $name): bool => str_starts_with($name, 'companion.woodpile.'))
            ->values()
            ->all();

        $this->assertSame(['companion.woodpile.store'], $names);
    }
}
```

**Note on `'companion_said'`:** confirm the literal value of `CompanionController::SAID_KEY` before running — read the constant and use whatever it actually is in both `assertSessionHas` and `session()`. Do not guess.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=CompanionWoodpileScreenTest`
Expected: FAIL — route `companion.woodpile.store` is not defined.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/CompanionWoodpileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\StackWood;
use App\Models\Companion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Stacking wood against the wall.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row leave the list.
 *
 * ONE DIRECTION, because there is only one. Nothing comes back out of the pile,
 * which is what separates it from the chest and is the whole of what this phase
 * adds to the economy.
 *
 * TWO 404s, and neither is a line in Blob's voice. A request naming something
 * the pile does not take is malformed rather than a state of the world; and a
 * request from an account with nothing built is naming a place that does not
 * exist, because the pile stands inside the shelter. The bag offers neither,
 * and there is no sentence for a gesture the screen never makes.
 */
class CompanionWoodpileController extends Controller
{
    public function store(Request $request, StackWood $stack): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = (string) $validated['item'];

        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes', []);

        abort_unless(in_array($item, $takes, true), Status::HTTP_NOT_FOUND);

        $user = $request->user();

        abort_if($user->companion()->value('shelter') === null, Status::HTTP_NOT_FOUND);

        $moved = $stack->handle($user, $item, $validated['amount'] ?? null);

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($moved === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{count}', '{label}'],
                [
                    Companion::nameFor($user),
                    (string) $moved,
                    (string) (config("companion.bag.{$item}.label") ?? $item),
                ],
                (string) config('companion.woodpile.stacked'),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import alongside the other companion controllers, and add this immediately after the `companion/stash` route:

```php
    // ONE DIRECTION, because there is only one: nothing comes back out of the
    // pile. That is what makes it a place material is spent rather than a third
    // place it is kept, and it is the whole of what this phase adds.
    Route::post('companion/woodpile', [CompanionWoodpileController::class, 'store'])
        ->name('companion.woodpile.store');
```

- [ ] **Step 5: Add the controller to the vocabulary list**

In `tests/Feature/Companion/CompanionVocabularyTest.php`, add after the `CompanionStashController.php` line:

```php
            $root.'/app/Http/Controllers/CompanionWoodpileController.php',
```

- [ ] **Step 6: Regenerate Wayfinder**

Run: `php artisan wayfinder:generate --with-form`
Expected: `resources/js/routes/companion/woodpile/index.ts` appears.

- [ ] **Step 7: Run the tests and watch them pass**

Run: `php artisan test --compact --filter=CompanionWoodpileScreenTest`
Expected: PASS, 5 tests.

Run: `php artisan test --compact --filter=CompanionVocabularyTest`
Expected: PASS.

- [ ] **Step 8: Apply two mutations, RUN each, record what you saw**

| Mutation | Expected red |
| --- | --- |
| Delete the `abort_if(... 'shelter' ... )` line | `test_there_is_no_pile_without_a_shelter_to_stand_it_in` |
| Delete the `abort_unless(in_array($item, $takes, true), ...)` line | `test_what_the_pile_does_not_take_is_not_a_route` — **and check whether it goes red for the right reason.** `StackWood` throws its own `InvalidArgumentException` for the same input, which would surface as a 500 rather than a 404. If the test passes on a 500 it is asserting nothing about the controller. Fix the assertion if so, and say that you did. |

**Revert both.** Record the observed results in the commit body.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CompanionWoodpileController.php routes/web.php resources/js/routes tests/Feature/Companion/
git commit -m "feat(companion): one route, one direction, two 404s"
```

**STOP. Report to the reviewer before Batch 3.**

---

# Batch 3 — The payload and the control

### Task 5: `CompanionBag` carries the pile — in both builders

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: `companions.woodpile`, `config('companion.woodpile.takes')`.
- Produces: payload key `woodpile` → `array{amount: int, takes: list<string>}`, present on **both** return paths.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * THE SECOND BUILDER. `CompanionBag::forUser()` has an early return for an
     * account with no companion row, which builds its own payload literal. A
     * key added only to the main return leaves a brand-new account without it,
     * and nothing in the type system notices, because both paths satisfy
     * `array`. F3.6 shipped exactly this defect; F4.1 had to guard it again.
     */
    public function test_an_account_with_no_companion_row_still_has_a_woodpile_key(): void
    {
        $user = User::factory()->create();

        $bag = app(CompanionBag::class)->forUser($user);

        $this->assertArrayHasKey('woodpile', $bag);
        $this->assertSame(0, $bag['woodpile']['amount']);
        $this->assertContains('deadfall', $bag['woodpile']['takes']);
    }

    public function test_the_payload_reports_what_is_in_the_pile(): void
    {
        $user = User::factory()->create();
        $user->companion()->create(['woodpile' => 17]);

        $bag = app(CompanionBag::class)->forUser($user);

        $this->assertSame(17, $bag['woodpile']['amount']);
        $this->assertSame(
            config('companion.woodpile.takes'),
            $bag['woodpile']['takes'],
        );
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=CompanionBagTest`
Expected: FAIL on both — no `woodpile` key.

- [ ] **Step 3: Add the key to the early return**

In `app/Services/Companion/CompanionBag.php`, inside the `if (! $companion instanceof Companion)` block, after the `'stash' => ...` line:

```php
                // The other half of the pair the `stash` comment above warns
                // about. Nothing is built, so there is nowhere to stack; but
                // the KEY is present and empty rather than absent, because a
                // screen should never have to ask whether one exists.
                'woodpile' => [
                    'amount' => 0,
                    'takes' => array_values((array) config('companion.woodpile.takes', [])),
                ],
```

- [ ] **Step 4: Add the key to the main return and the method behind it**

In the main `return [...]`, after `'stash' => $this->stash($companion),`:

```php
            'woodpile' => $this->woodpile($companion),
```

And add the method, beside `stash()`:

```php
    /**
     * The pile against the wall, and what may go into it.
     *
     * `amount` cannot be derived on the client: it is a column, and the drawing
     * side reads no database. It is sent so the interior can be drawn at the
     * right size, and for NOTHING ELSE — it is never rendered as text, because
     * a figure of what is in a pile is a total and this screen shows no totals.
     *
     * `takes` is sent rather than re-authored on the client so config stays the
     * one author of what the pile accepts — the same reasoning `droppable`
     * follows in {@see items()}.
     *
     * Whether the control is offered at all ALSO needs `shelter.built`, which
     * this payload already carries. There is no second flag here, because a
     * pile with no shelter to stand in is not a state the world has.
     *
     * @return array{amount: int, takes: list<string>}
     */
    private function woodpile(Companion $companion): array
    {
        return [
            'amount' => $companion->woodpile,
            'takes' => array_values((array) config('companion.woodpile.takes', [])),
        ];
    }
```

- [ ] **Step 5: Update the `forUser()` return docblock**

Add to the array shape, after the `stash:` line:

```
     *     woodpile: array{amount: int, takes: list<string>},
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php artisan test --compact --filter=CompanionBagTest`
Expected: PASS.

- [ ] **Step 7: Apply the second-builder mutation, RUN it, record what you saw**

Delete the whole `'woodpile' => [...]` block from the early return only (leave the main one). Run `php artisan test --compact --filter=CompanionBagTest`.
Expected: RED on `test_an_account_with_no_companion_row_still_has_a_woodpile_key`.
**Revert.** Record the observed result.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): the payload carries the pile, in both builders"
```

---

### Task 6: The `stack it` control

**Files:**
- Modify: `resources/js/patyourself/companion.tsx` (the `CompanionBagData` type), `resources/js/patyourself/companion-bag.tsx`, `resources/js/patyourself/companion.fixture.ts`
- Test: `resources/js/patyourself/companion-bag.test.tsx`

**Interfaces:**
- Consumes: `bag.woodpile.takes`, `bag.woodpile.amount`, `bag.shelter.built`.
- Produces: a submit button labelled `stack it`, `aria-label={`Stack the ${item.label} against the wall`}`, posting `companion.woodpile.store` with a hidden `item` input and **no amount input**.

- [ ] **Step 1: Widen the shared fixture FIRST — trap 9 has bitten four times**

In `resources/js/patyourself/companion.fixture.ts`, inside the object `bag()` returns, after the `stash:` entry:

```ts
        // Nothing stacked. Present and empty rather than absent, exactly as the
        // server sends it — and `takes` carries config's own list so a test
        // does not have to re-author what the pile accepts.
        woodpile: { amount: 0, takes: ['deadfall', 'timber', 'planks'] },
```

Do **not** reformat the rest of the file. It already fails `prettier --check` on main; leave that alone.

- [ ] **Step 2: Add the type**

In `resources/js/patyourself/companion.tsx`, in the `CompanionBagData` interface, after the `stash` field:

```ts
    /**
     * The pile against the wall. `amount` is for the DRAWING ONLY — it sizes
     * the picture and is never rendered as text, because a figure of what is in
     * a pile is a total and this screen shows none.
     */
    woodpile: { amount: number; takes: string[] };
```

- [ ] **Step 3: Write the failing tests**

Append to `resources/js/patyourself/companion-bag.test.tsx` (follow the file's existing render helper and import style — read the top of the file first):

```tsx
    it('offers to stack wood once a shelter is standing', () => {
        renderBag({
            items: [
                {
                    item: 'deadfall',
                    label: 'deadfall',
                    category: 'material',
                    quantity: 3,
                    droppable: true,
                },
            ],
            shelter: { built: 'lean-to', label: 'lean-to', offer: null },
        });

        expect(
            screen.getByRole('button', {
                name: 'Stack the deadfall against the wall',
            }),
        ).toBeInTheDocument();
    });

    it('does not offer to stack what the pile does not take', () => {
        renderBag({
            items: [
                {
                    item: 'fibre',
                    label: 'fibre',
                    category: 'material',
                    quantity: 3,
                    droppable: true,
                },
            ],
            shelter: { built: 'lean-to', label: 'lean-to', offer: null },
        });

        expect(
            screen.queryByRole('button', {
                name: 'Stack the fibre against the wall',
            }),
        ).not.toBeInTheDocument();
    });

    it('offers no pile before there is a shelter to stand one in', () => {
        renderBag({
            items: [
                {
                    item: 'deadfall',
                    label: 'deadfall',
                    category: 'material',
                    quantity: 3,
                    droppable: true,
                },
            ],
        });

        expect(
            screen.queryByRole('button', {
                name: 'Stack the deadfall against the wall',
            }),
        ).not.toBeInTheDocument();
    });
```

- [ ] **Step 4: Run them and watch them fail**

Run: `npm test -- resources/js/patyourself/companion-bag.test.tsx`
Expected: FAIL — no such button.

- [ ] **Step 5: Add the control**

In `resources/js/patyourself/companion-bag.tsx`, add the import beside the others:

```ts
import { store as woodpileRoute } from '@/routes/companion/woodpile';
```

In `Held`'s row, immediately after the closing `)}` of the `bag.stash.standing && item.droppable && (...)` block:

```tsx
                            {/* Offered only once something is built to stack
                                against, and only for what the pile takes.
                                One-way, and no confirmation: an "are you sure"
                                is the app having an opinion about a choice that
                                belongs to the player, which is the same rule
                                `drop` follows next door.

                                NO AMOUNT INPUT, matching `put away` above. The
                                action accepts one; the screen does not offer
                                one. */}
                            {bag.shelter.built !== null &&
                                bag.woodpile.takes.includes(item.item) && (
                                    <Form
                                        {...woodpileRoute.form()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="item"
                                                    value={item.item}
                                                />
                                                <button
                                                    type="submit"
                                                    className="c-bagdrop"
                                                    disabled={processing}
                                                    aria-label={`Stack the ${item.label} against the wall`}
                                                >
                                                    stack it
                                                </button>
                                            </>
                                        )}
                                    </Form>
                                )}
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `npm test -- resources/js/patyourself/companion-bag.test.tsx`
Expected: PASS.

- [ ] **Step 7: Run the acceptance guard, bag closed and bag open**

Run: `npm test -- resources/js/pages/companion.test.tsx resources/js/patyourself/companion.test.tsx`
Expected: PASS — including `never shows what has not happened`. The control shows a word, never a figure.

- [ ] **Step 8: Apply two mutations, RUN each, record what you saw**

| Mutation | Expected red |
| --- | --- |
| Change the condition to `item.droppable &&` (dropping both the shelter and the `takes` check) | `does not offer to stack what the pile does not take` **and** `offers no pile before there is a shelter` |
| Render `{bag.woodpile.amount}` beside the button | `never shows what has not happened` — **and if it does NOT go red, say so.** The guard's regex is `/locked\|next up\|to unlock\|remaining\|streak\|\d+ of \d+/`, and a bare integer does not match it. That would be a real finding about the guard's reach, not a pass. |

**Revert both.** Record both observed results.

- [ ] **Step 9: Check formatting on named files only, then commit**

```bash
npx prettier --check resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.tsx
git add resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.tsx resources/js/patyourself/companion.fixture.ts resources/js/patyourself/companion-bag.test.tsx
git commit -m "feat(companion): a stack-it control beside put-away"
```

**STOP. Report to the reviewer before Batch 4.**

---

# Batch 4 — The picture and the behaviour

### Task 7: The pile, drawn

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx`
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `woodpile: number` — a new prop on `CompanionRoom`, defaulting to `0`.
- Produces: an element carrying `data-woodpile={amount}`, drawn **only** when `indoors` and `amount > 0`.

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/patyourself/companion-room.test.tsx` (match the file's existing render helper):

```tsx
    it('draws nothing where the pile is until something is in it', () => {
        const { container } = renderRoom({ inside: true, woodpile: 0 });

        expect(container.querySelector('[data-woodpile]')).toBeNull();
    });

    it('draws the pile once there is something in it', () => {
        const { container } = renderRoom({ inside: true, woodpile: 4 });

        expect(container.querySelector('[data-woodpile]')).not.toBeNull();
    });

    it('never draws the pile in the clearing, at any amount', () => {
        const { container } = renderRoom({ inside: false, woodpile: 400 });

        expect(container.querySelector('[data-woodpile]')).toBeNull();
    });

    /**
     * The picture is ASYMPTOTIC by necessity: the room is 114 units tall and
     * the pile is uncapped. Monotonic non-decreasing, and never reaching the
     * ceiling it approaches.
     */
    it('grows with the pile and never reaches its ceiling', () => {
        const heightAt = (amount: number): number => {
            const { container, unmount } = renderRoom({
                inside: true,
                woodpile: amount,
            });
            const value = Number(
                container
                    .querySelector('[data-woodpile]')
                    ?.getAttribute('data-woodpile-height'),
            );
            unmount();

            return value;
        };

        const amounts = [1, 3, 10, 50, 500, 100_000];
        const heights = amounts.map(heightAt);

        heights.forEach((h, i) => {
            if (i > 0) {
                expect(h).toBeGreaterThan(heights[i - 1]);
            }
            expect(h).toBeLessThan(WOODPILE_MAX_H);
        });
    });
```

Import `WOODPILE_MAX_H` from `@/patyourself/companion-room`.

- [ ] **Step 2: Run them and watch them fail**

Run: `npm test -- resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — `woodpile` is not a prop and `WOODPILE_MAX_H` is not exported.

- [ ] **Step 3: Add the drawing**

In `resources/js/patyourself/companion-room.tsx`, above `CompanionRoom`:

```tsx
/**
 * How tall the pile is allowed to get, in room units. The floor sits at
 * `FLOOR` and the bookshelf's own top is at y=10, so this leaves the pile short
 * of anything else in the room at every amount.
 */
export const WOODPILE_MAX_H = 26;

/**
 * How quickly it approaches that. Chosen by rendering, not derived: at 12 the
 * pile is half its ceiling by about a dozen units, which is roughly two
 * bagfuls, and still visibly growing at a hundred.
 */
const WOODPILE_K = 12;

/**
 * The drawn height of a pile holding `amount`.
 *
 * ASYMPTOTIC BY NECESSITY. The room is 114 units tall and the pile is uncapped,
 * so the picture cannot be linear in the amount — but it also must not plateau,
 * because a size it stops growing from reads as full, and a pile that reads as
 * full has rebuilt the end state this whole phase exists to remove.
 *
 * `MAX_H * n / (n + K)` is monotonic, is strictly below `MAX_H` for every
 * finite n, and has no flat region. It is never shown as a figure anywhere.
 */
export function woodpileHeight(amount: number): number {
    return (WOODPILE_MAX_H * amount) / (amount + WOODPILE_K);
}

/**
 * Wood stacked against the wall, in the measured gap at x[18,38].
 *
 * NOT a `ROOM_OBJECT`. Those arrive from the ladder as gifts and are keyed by
 * name; this one is bought, and its size is a function of what was put into it
 * rather than of anything the record says.
 *
 * `data-woodpile` carries the amount as a TEST SEAM, the same standing
 * `data-animation` and `data-part-of-day` have. It is an attribute inside a
 * `role="img"`, not text: nothing on this screen renders what is in the pile as
 * a figure, because a figure of a holding is a total.
 */
function Woodpile({ amount }: { amount: number }) {
    const h = woodpileHeight(amount);
    const rows = Math.max(1, Math.round(h / 4));

    return (
        <g
            className="room-object room-object--woodpile"
            data-woodpile={amount}
            data-woodpile-height={h}
        >
            <rect x={18} y={FLOOR - h} width={20} height={h} fill="#7A5B3A" />
            {Array.from({ length: rows }, (_, i) => (
                <rect
                    key={i}
                    x={19}
                    y={FLOOR - h + (i * h) / rows + 0.75}
                    width={18}
                    height={0.9}
                    fill="#5E442A"
                />
            ))}
        </g>
    );
}
```

Add `woodpile = 0` to `CompanionRoom`'s props and its type (`woodpile?: number`), and render it inside the `indoors` branch, immediately after the `objects.map(...)` block:

```tsx
                    {woodpile > 0 && <Woodpile amount={woodpile} />}
```

- [ ] **Step 4: Pass it from the page**

In `resources/js/pages/companion.tsx`, add to the `<CompanionRoom ... />` call, after `chestStanding={bag.stash.standing}`:

```tsx
                    woodpile={bag.woodpile.amount}
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm test -- resources/js/patyourself/companion-room.test.tsx`
Expected: PASS.

- [ ] **Step 6: Apply two mutations, RUN each, record what you saw**

| Mutation | Expected red |
| --- | --- |
| Change `{woodpile > 0 && <Woodpile .../>}` to `{<Woodpile amount={woodpile} />}` | `draws nothing where the pile is until something is in it` |
| Change `woodpileHeight` to `Math.min(WOODPILE_MAX_H, amount)` | `grows with the pile and never reaches its ceiling` — **on the `toBeLessThan` assertion, and check that it is that one.** If it reddens on monotonicity instead, the test is not pinning what it claims. |

**Revert both.** Record the observed results.

- [ ] **Step 7: Check formatting on named files only, then commit**

```bash
npx prettier --check resources/js/patyourself/companion-room.tsx resources/js/pages/companion.tsx
git add resources/js/patyourself/companion-room.tsx resources/js/pages/companion.tsx resources/js/patyourself/companion-room.test.tsx
git commit -m "feat(companion): a pile that approaches its ceiling and never arrives"
```

---

### Task 8: Blob's night moves indoors

**Files:**
- Modify: `resources/js/pages/companion.tsx`, `resources/js/patyourself/companion.harness.tsx`
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: `bag.woodpile.amount`, `asleepAt(hour, companion.room)`.
- Produces: `renderAtHome(opts)` in the harness — the mirror of `renderClearing`, asserting `data-interior` IS set.

- [ ] **Step 1: Add the harness sibling**

`renderClearing` **throws** when `data-interior` is set, so it cannot be used for this behaviour. Add to `resources/js/patyourself/companion.harness.tsx`:

```tsx
/**
 * The mirror of `renderClearing`, and it exists for the same reason: a test
 * that means to assert the INTERIOR must fail loudly when it gets the clearing,
 * rather than quietly asserting nothing.
 *
 * Used by the one behaviour that puts Blob indoors without the player asking —
 * a pile standing at an hour Blob sleeps.
 */
export function renderAtHome(
    opts: {
        companion?: Partial<CompanionData>;
        bag?: Partial<CompanionBagData>;
    } = {},
): { companion: CompanionData; bag: CompanionBagData } {
    const payload = {
        companion: companion({ scene: 'forest', ...opts.companion }),
        bag: bag(opts.bag),
    };

    render(<CompanionPage {...payload} />);

    const scene = screen.getByRole('img');

    if (scene.dataset.interior === undefined) {
        throw new Error(
            'the interior did not render: got scene=' +
                `${scene.dataset.scene} interior=${scene.dataset.interior}.`,
        );
    }

    return payload;
}
```

- [ ] **Step 2: Write the failing tests**

Append to `resources/js/pages/companion.test.tsx`. The page reads `new Date().getHours()`, so freeze the clock with vitest's fake timers — read how the file already handles the hour before writing this, and match it.

```tsx
    it('opens indoors at night once a pile stands', () => {
        vi.setSystemTime(new Date('2026-09-23T22:00:00'));

        renderAtHome({
            bag: {
                woodpile: { amount: 6, takes: ['deadfall', 'timber', 'planks'] },
                shelter: { built: 'cabin', label: 'cabin', offer: null },
            },
        });
    });

    it('stays outside at night when nothing is stacked', () => {
        vi.setSystemTime(new Date('2026-09-23T22:00:00'));

        renderClearing({
            bag: {
                shelter: { built: 'cabin', label: 'cabin', offer: null },
            },
        });
    });

    it('stays outside in daylight however much is stacked', () => {
        vi.setSystemTime(new Date('2026-09-23T12:00:00'));

        renderClearing({
            bag: {
                woodpile: { amount: 400, takes: ['deadfall', 'timber', 'planks'] },
                shelter: { built: 'cabin', label: 'cabin', offer: null },
            },
        });
    });
```

Both harness functions throw on the wrong room, so **the render itself is the assertion.** Add an explicit `expect(screen.getByRole('img')).toBeInTheDocument()` at the end of each so the case is not mistaken for an empty test.

- [ ] **Step 3: Run them and watch the first fail**

Run: `npm test -- resources/js/pages/companion.test.tsx`
Expected: FAIL on `opens indoors at night once a pile stands` — `renderAtHome` throws, because `inside` still starts `false`.

- [ ] **Step 4: Change the initialiser**

In `resources/js/pages/companion.tsx`, add `asleepAt` to the `part-of-day` import, and replace `const [inside, setInside] = useState(false);` with:

```tsx
    // inside/outside is still a view rather than a thing you own — but once a
    // pile stands, Blob's night is spent at home, and the page opens where Blob
    // actually is.
    //
    // WHETHER BLOB SLEEPS IS STILL THE CLOCK ALONE. `asleepAt` knows nothing
    // about the pile and must not: the day this starts reading what has been
    // recorded, a state of being alive has quietly become a reward. WHERE it
    // sleeps is what you built, which is the first time in this feature that a
    // purchase changes where Blob is.
    //
    // A useState initialiser runs ONCE, at mount. Three behaviours fall out of
    // that and none of them needs its own rule: the page opens indoors at night
    // when a pile stands, an explicit toggle afterwards is never overridden,
    // and the view never flips under the player's hand as the hour ticks.
    //
    // Accepted, and recorded rather than discovered later: a page loaded in
    // daylight stays outside when the hour crosses into night. That is the
    // pre-pile behaviour, so nothing is worse than it was.
    const [inside, setInside] = useState(
        () =>
            bag.woodpile.amount > 0 &&
            asleepAt(new Date().getHours(), companion.room),
    );
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm test -- resources/js/pages/companion.test.tsx`
Expected: PASS, all three.

- [ ] **Step 6: Run the whole client suite — this changes a default four screens share**

Run: `npm test`
Expected: PASS at 748 or more. **Any pre-existing test that renders the page at a night hour with a widened fixture may now get the interior.** If one breaks, that is this change working; fix the test by naming the hour or the pile explicitly, and say which you changed.

- [ ] **Step 7: Apply three mutations, RUN each, record what you saw**

| Mutation | Expected red |
| --- | --- |
| Revert the initialiser to `useState(false)` | `opens indoors at night once a pile stands` |
| Drop `&& asleepAt(...)`, leaving only `bag.woodpile.amount > 0` | `stays outside in daylight however much is stacked` |
| Make `asleepAt` in `part-of-day.ts` return `true` whenever a pile stands | **nothing should compile** — `asleepAt` takes no bag. That is the point: the ruling is enforced by the signature, not by a test. Record that you tried it and what stopped you. |

**Revert all.** Record the observed results.

- [ ] **Step 8: Check formatting on named files only, then commit**

```bash
npx prettier --check resources/js/pages/companion.tsx resources/js/patyourself/companion.harness.tsx
git add resources/js/pages/companion.tsx resources/js/patyourself/companion.harness.tsx resources/js/pages/companion.test.tsx
git commit -m "feat(companion): Blob's night moves indoors once a pile stands"
```

---

### Task 9: Render it and look — the asymptote is not a unit test

**Files:** none changed unless the render says so.

**jsdom has no layout engine.** Everything in Task 7 asserted numbers. This task asserts the picture.

- [ ] **Step 1: Build the app**

Run: `npm run build`
Expected: succeeds.

- [ ] **Step 2: Serve the worktree — BOTH parts are required**

Herd serves the main checkout, never a worktree. `php -S ... -t public public/index.php` returns **every** asset as `text/html`, so the browser silently refuses the module script and **the page renders blank with no console error at all.** A registered service worker is a plausible-looking red herring and is not the cause.

Create `$CLAUDE_JOB_DIR/tmp/router.php`:

```php
<?php

// `return false` resolves the path against the docroot `-t` sets, not against
// wherever this file lives — which is why BOTH the router and `-t public` are
// required.
if (php_sapi_name() === 'cli-server' && is_file(__DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__.'/../public/index.php';
```

Adjust the relative paths to the worktree's real `public/`. Then:

```bash
php -S 127.0.0.1:8899 -t public <router>.php
```

- [ ] **Step 3: Look at the pile at six amounts**

With a shelter built and a pile seeded to each of **1, 4, 12, 40, 200, 5000**, view `/companion` indoors and screenshot each.

Judge, by eye, three things:
1. **Does it ever look finished?** If two consecutive amounts are indistinguishable at a normal stage size, `WOODPILE_K` is too small — raise it.
2. **Does it collide?** It must clear the `plant` (from x 39) and stay below anything on the wall.
3. **Does it sit on the floor?** The base must be flush with `FLOOR`, not floating and not sunk.

- [ ] **Step 4: Tune and re-render, or record that it held**

If any judgement fails, change `WOODPILE_MAX_H` / `WOODPILE_K` and look again. **Record the amounts rendered and what was seen** in the commit body — the numbers, not "it looked fine". The shelter's `y=34` and the trunk's bands each cost a round by being believed instead of checked.

- [ ] **Step 5: Commit any tuning**

```bash
npx prettier --check resources/js/patyourself/companion-room.tsx
git add resources/js/patyourself/companion-room.tsx
git commit -m "fix(companion): the pile's curve, chosen by rendering it"
```

Skip this commit if nothing changed, and say so.

**STOP. Report to the reviewer before Batch 5.**

---

# Batch 5 — Three open items from BLOB.md §12

Cheap, and all touching surfaces this phase already opened. **If any of them grows, cut it rather than carry it** — say which and why.

### Task 10: `capacity()` counts containers, and only containers

**Files:**
- Modify: `app/Models/Companion.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

- [ ] **Step 1: Write the failing test**

```php
    /**
     * `capacity()` summed the `capacity` field across EVERY held item
     * regardless of category. Harmless while only containers carried one — but
     * any future structure or tool that gained one would silently enlarge the
     * bag forever, which is the opposite of the rule that a tool never taxes
     * what Blob can carry.
     *
     * MEASURED on F4.1: adding `'capacity' => 5` to the chest's config row
     * turned this assertion from 5 to 10.
     */
    public function test_only_a_container_enlarges_the_bag(): void
    {
        config()->set('companion.bag.chest.capacity', 5);

        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'chest', 'quantity' => 1]);

        $this->assertSame(5, $companion->fresh()->capacity());
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=test_only_a_container_enlarges_the_bag`
Expected: FAIL — got 10, expected 5.

- [ ] **Step 3: Filter to `container`**

In `app/Models/Companion.php`'s `capacity()`, replace the `$added` closure body so the item's category must be `container`:

```php
        $added = $this->items->sum(
            static function (CompanionItem $held) use ($catalogue): int {
                // A CONTAINER, and nothing else. This summed every held item's
                // `capacity` field regardless of category, so any future
                // structure or tool that gained one would have enlarged the bag
                // forever — a permanent thing quietly changing what Blob can
                // carry, which is the mirror image of the rule that a tool
                // never occupies a slot.
                if (($catalogue[$held->item]['category'] ?? '') !== 'container') {
                    return 0;
                }

                return (int) ($catalogue[$held->item]['capacity'] ?? 0) * $held->quantity;
            },
        );
```

- [ ] **Step 4: Run it, then the whole companion suite**

Run: `php artisan test --compact --filter=CompanionBagTest`
Expected: PASS.

Run: `php artisan test --compact tests/Feature/Companion tests/Unit/Companion`
Expected: PASS — no existing container-capacity assertion changes, because baskets, barrows and crates are all `container` already.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Companion.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "fix(companion): only a container enlarges the bag"
```

---

### Task 11: The bag stops listing a chest as carried, and two comments stop lying

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php`, `app/Actions/HarvestNode.php`, `app/Actions/BuildItem.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

- [ ] **Step 1: Write the failing test**

```php
    /**
     * A `structure` lives in `companion_items`, so the bag listed a standing
     * chest under Held. Accurate and odd: Blob is not carrying a chest. The
     * shelter avoids this only by being a column rather than a row.
     */
    public function test_a_standing_structure_is_not_listed_as_carried(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'chest', 'quantity' => 1]);

        $bag = app(CompanionBag::class)->forUser($user);

        $this->assertSame(
            [],
            array_column($bag['items'], 'item'),
        );
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=test_a_standing_structure_is_not_listed_as_carried`
Expected: FAIL — got `['chest']`.

- [ ] **Step 3: Filter `structure` out of `items()`**

In `CompanionBag::items()`, return `null` for a `structure` entry so it is filtered with the unknown ones, and add a comment saying that a structure stands in the world rather than in the bag, and that it stays in `companion_items` because that is where `BuildItem` writes.

- [ ] **Step 4: Correct the two dead comments**

`app/Actions/HarvestNode.php:86` and `app/Actions/BuildItem.php:109` both carry `$companion->load('items')` with a comment stating a premise that does not hold: each action resolves the companion fresh inside its own transaction via `$user->companion()->firstOrCreate([])`, so `items` is never pre-loaded, cannot be stale, and lazy-loads on first read regardless. **Evidence already recorded: a mutation moving the call after `room()` stayed GREEN.**

Correct both comments the way F4.1 corrected `UnstashItem:69` — read that one first and match it. **Do not delete the calls**; this task changes comments, not behaviour.

- [ ] **Step 5: Run the companion suite**

Run: `php artisan test --compact tests/Feature/Companion tests/Unit/Companion`
Expected: PASS.

- [ ] **Step 6: Run the client suite — `items` feeds the bag modal**

Run: `npm test -- resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx`
Expected: PASS.

- [ ] **Step 7: Apply the mutation, RUN it, record what you saw**

Remove the `structure` filter from `items()`. Expected RED on `test_a_standing_structure_is_not_listed_as_carried`. **Revert.**

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php app/Actions/HarvestNode.php app/Actions/BuildItem.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "fix(companion): a chest stands, it is not carried"
```

**STOP. Report to the reviewer before Batch 6.**

---

# Batch 6 — The record

### Task 12: `docs/BLOB.md`

**Files:**
- Modify: `docs/BLOB.md`

`docs/BLOB.md` is the current-state reference and **claims precedence over every spec.** It must not be added to `CompanionVocabularyTest::sourceFiles()`.

- [ ] **Step 1: §8, the economy**

Add a subsection after "The stash: a second place the world keeps the overflow", covering: the pile is one-way and uncapped; it is `companions.woodpile`, a single integer, because nothing comes back out; what it takes and why `takes` is not a second `carried` list; and the measured reason it exists — 60 XP is the whole amount any account will ever spend, and past the cabin the only new thing a material can become is another container.

- [ ] **Step 2: §9, where everything lives**

Add: the migration, `app/Actions/StackWood.php`, `app/Http/Controllers/CompanionWoodpileController.php`, the `woodpile` column on the `Companion.php` line, and the `Woodpile` drawing on the `companion-room.tsx` line.

- [ ] **Step 3: §10, two new rulings**

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **Whether Blob sleeps is the clock alone; where it sleeps is what you built** | The rule §2 states names `logCount`, `insightCount` and the unlocks — things the record says. A pile is a purchase, like the cabin Blob already stands next to. `asleepAt()` takes no bag, and that signature is the guard. | A state of being alive becomes a reward, in the one place it could hide. |
| **Nothing comes out of the pile** | A pile that could be emptied is a third place to keep things, and the chest is already the second. One-way is the entire difference between a sink and a stash, and it is why one integer suffices. | The sink becomes storage, the end state comes back, and the column has to become a table to answer a question it never had to answer. |

- [ ] **Step 4: §11, sharpen trap 9 and trap 1**

Trap 9 has now bitten a fourth time's worth of prevention — record the woodpile fixture widening as the fourth surface it covers. Under trap 1, record **every mutation this branch actually ran and what was observed**, including any that stayed green.

- [ ] **Step 5: §12, what is not done**

- Mark F4.2 done in the three-sub-projects bullet; **F4.3 is the last.**
- Delete the three bullets closed in Batch 5 (`capacity()` across every item, the `Held` chest, the two dead `load('items')` comments) — **delete rather than strike through**, matching how the file already retires a closed item where the reasoning is superseded rather than preserved.
- Keep the `UnstashItem` guard bullet: still open, still unreachable, deliberately not fixed.
- Add: **`consumable` is a category with no behaviour** — it occurs twice in non-test code, both as data (`config/companion.php:658` and `:833`), and nothing branches on it. F4.3's subject, measured here so the next phase inherits it.
- Add: **the pile's curve is `MAX_H * n / (n + K)`, chosen by rendering** at the amounts Task 9 recorded — so a later "simplification" to a linear or clamped form has the render evidence to read first.

- [ ] **Step 6: Run the full suites**

Run: `php artisan test --compact`
Expected: PASS at 1419 or more.

Run: `npm test`
Expected: PASS at 748 or more.

- [ ] **Step 7: Commit**

```bash
git add docs/BLOB.md
git commit -m "docs(companion): record what F4.2 decided and what it measured"
```

---

## Self-Review

**Spec coverage:** §1 → Tasks 1–3. §2 → recorded in Task 12 Step 5. §3 → the constraint driving Tasks 7–8. §4 → Task 2. §5 → Task 1. §6 → Task 4's two 404s. §7 → Task 8. §8 → Tasks 7 and 9. §9 → Task 6. §10 → Task 5. §11 → Tasks 3–4. §12 → the mutation steps in every task. §13 → Tasks 10–11. §14/§15/§16 → Task 12.

**Known gap, stated rather than papered over:** the spec's §12 row *"The amount is never text"* names the `never shows what has not happened` guard as its enforcement. Task 6 Step 8 makes the plan **run** that mutation, because the guard's regex (`/locked|next up|to unlock|remaining|streak|\d+ of \d+/`) does not match a bare integer and the row may be wrong. If it is, the finding is the deliverable and the second assertion in that step is what actually holds the rule.

**Type consistency:** `woodpile` is `array{amount: int, takes: list<string>}` in PHP (Task 5) and `{ amount: number; takes: string[] }` in TS (Task 6), passed to `CompanionRoom` as `woodpile: number` (Task 7) and read as `bag.woodpile.amount` in the page (Tasks 7–8). `StackWood::handle(User, string, ?int): int` is called from the controller (Task 4) with `$validated['amount'] ?? null`. `WOODPILE_MAX_H` and `woodpileHeight` are exported from `companion-room.tsx` and imported by its test.
