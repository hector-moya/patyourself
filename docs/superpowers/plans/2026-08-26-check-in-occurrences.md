# Check-in model — occurrences + MCP write surface — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the check-in conversation work end to end over MCP — catch up unlogged occurrences, read failure reasons back, and start a new experiment — by giving each instance of a recurring action its own row.

**Architecture:** An `Occurrence` is one instance of an action (action + scheduled datetime + at most one outcome), materialised lazily on read from a new immutable `actions.series_started_at` anchor. The `Action` row keeps its existing job as the standing prescription and next-due pointer, so `TriggerEngine`, `TodaysActions`, notifications and the web surfaces are untouched. Outcomes attach to occurrences; `action_logs.action_id` stays as the denormalised parent pointer the existing read models join on.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, `laravel/mcp` v0, SQLite in tests.

Spec: `docs/superpowers/specs/2026-08-26-check-in-occurrences-design.md`

## Global Constraints

- **Reasons verbatim.** Never trim, squish, sentence-case, summarise or normalise `reason` anywhere in the path.
- **Strategy versions are append-only.** Supersede, never overwrite. No draft/approval step.
- **No quantities on eating loops.** No calories, portions, weights or numeric targets in any column, field or copy.
- **Failure language is about the strategy, never the user.** No discipline / willpower / motivation framing in any field name, enum value, description or error message.
- **No gamification.** A streak is a statistic. No badges, levels, celebratory states.
- **The notebook never nags.** No overdue counts, no red states, no "you missed N" framing on a backlog.
- **`skipped` = the occasion never happened.** Excluded from the completion-rate denominator; reported as its own count. `failed` includes "didn't think about it" and stays in the denominator.
- Run `vendor/bin/pint --dirty --format agent` before every commit.
- Tests: `php artisan test --compact --filter=<name>`. Never delete an existing test without approval.
- Artisan: always pass `--no-interaction`.

---

### Task 1: The occurrence entity

**Files:**
- Create: `database/migrations/<ts>_create_occurrences_table.php`
- Create: `database/migrations/<ts>_add_occurrence_columns_to_action_logs_table.php`
- Create: `database/migrations/<ts>_add_series_started_at_to_actions_table.php`
- Create: `database/migrations/<ts>_backfill_occurrences_for_existing_logs.php`
- Create: `app/Models/Occurrence.php`
- Create: `database/factories/OccurrenceFactory.php`
- Modify: `app/Models/Action.php` (fillable + cast + `occurrences()` relation)
- Modify: `app/Models/ActionLog.php` (fillable + casts + `occurrence()` relation)
- Test: `tests/Feature/Database/OccurrenceSchemaTest.php`

**Interfaces:**
- Produces: `App\Models\Occurrence` with `action_id: int`, `scheduled_for: CarbonImmutable`, `action(): BelongsTo`, `log(): HasOne<ActionLog>`, `isLogged(): bool`, scope `unlogged()`.
- Produces: `Action::$series_started_at` (`?CarbonImmutable`), `Action::occurrences(): HasMany`.
- Produces: `ActionLog::$occurrence_id` (`?int`), `ActionLog::$context` (`?string`), `ActionLog::$context_fields` (`?array{place?: string, with_others?: bool, preceded_by?: string}`), `ActionLog::occurrence(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Database/OccurrenceSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Database;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OccurrenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_occurrence_belongs_to_an_action_and_starts_unlogged(): void
    {
        $occurrence = Occurrence::factory()->create(['scheduled_for' => now()->subDay()]);

        $this->assertInstanceOf(Action::class, $occurrence->action);
        $this->assertFalse($occurrence->isLogged());
        $this->assertNull($occurrence->log);
    }

    public function test_the_same_slot_cannot_be_materialised_twice(): void
    {
        $occurrence = Occurrence::factory()->create();

        $this->expectException(QueryException::class);

        Occurrence::factory()->create([
            'action_id' => $occurrence->action_id,
            'scheduled_for' => $occurrence->scheduled_for,
        ]);
    }

    public function test_an_occurrence_carries_at_most_one_outcome(): void
    {
        $occurrence = Occurrence::factory()->create();
        ActionLog::factory()->create([
            'action_id' => $occurrence->action_id,
            'occurrence_id' => $occurrence->id,
        ]);

        $this->expectException(QueryException::class);

        ActionLog::factory()->create([
            'action_id' => $occurrence->action_id,
            'occurrence_id' => $occurrence->id,
        ]);
    }

    public function test_a_log_stores_context_and_its_small_structured_field_set(): void
    {
        $log = ActionLog::factory()->create([
            'context' => 'Ate standing up while cooking, plate refilled twice',
            'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
        ]);

        $this->assertSame('kitchen', $log->fresh()->context_fields['place']);
        $this->assertFalse($log->fresh()->context_fields['with_others']);
    }

    public function test_an_action_records_where_its_series_began(): void
    {
        $action = Action::factory()->create(['series_started_at' => now()->subWeek()]);

        $this->assertTrue($action->fresh()->series_started_at->isPast());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=OccurrenceSchemaTest`
Expected: FAIL — `Class "App\Models\Occurrence" not found`.

- [ ] **Step 3: Create the migrations**

```bash
php artisan make:migration create_occurrences_table --no-interaction
php artisan make:migration add_occurrence_columns_to_action_logs_table --no-interaction
php artisan make:migration add_series_started_at_to_actions_table --no-interaction
php artisan make:migration backfill_occurrences_for_existing_logs --no-interaction
```

`create_occurrences_table`:

```php
/**
 * One instance of a recurring action: the durable, dated row an outcome
 * attaches to. Materialised lazily from actions.series_started_at up to now,
 * so an occasion that was never logged still leaves a trace and stays
 * catch-up-able indefinitely.
 */
public function up(): void
{
    Schema::create('occurrences', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('action_id')->constrained()->cascadeOnDelete();

        // The occasion this row stands for. For an ad hoc log (an anchored
        // action with no schedule) it is the user-supplied occurred_at.
        $table->timestamp('scheduled_for');

        $table->timestamps();

        // Materialisation is idempotent and concurrency-safe on this index.
        $table->unique(['action_id', 'scheduled_for']);
        $table->index('scheduled_for');
    });
}

public function down(): void
{
    Schema::dropIfExists('occurrences');
}
```

`add_occurrence_columns_to_action_logs_table`:

```php
/**
 * An outcome attaches to an occurrence, not to an action — that is what makes
 * it dated by the occasion rather than by when it was typed. `action_id`
 * stays as the denormalised parent pointer the existing read models join on.
 *
 * `context` is the free-text mechanics and stays the primary record;
 * `context_fields` is a deliberately tiny structured set beside it.
 */
public function up(): void
{
    Schema::table('action_logs', function (Blueprint $table): void {
        $table->foreignId('occurrence_id')
            ->nullable()
            ->after('action_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->text('context')->nullable()->after('reason');
        $table->json('context_fields')->nullable()->after('context');

        // One outcome per occurrence.
        $table->unique('occurrence_id');
    });
}

public function down(): void
{
    Schema::table('action_logs', function (Blueprint $table): void {
        $table->dropUnique(['occurrence_id']);
        $table->dropConstrainedForeignId('occurrence_id');
        $table->dropColumn(['context', 'context_fields']);
    });
}
```

`add_series_started_at_to_actions_table`:

```php
/**
 * Where this action's series began. `scheduled_for` cannot answer that: it is
 * the next-due pointer and rolls forward on every log. This column is set once
 * at creation and never mutated, and it is what materialisation walks from.
 *
 * Backfilled from the current `scheduled_for`. For an action that has already
 * rolled forward that anchor sits in the future, so materialisation produces
 * nothing until it passes — deliberately, so it cannot collide with the
 * synthesised occurrences the next migration writes for existing logs.
 */
public function up(): void
{
    Schema::table('actions', function (Blueprint $table): void {
        $table->timestamp('series_started_at')->nullable()->after('scheduled_for');
    });

    DB::table('actions')->whereNotNull('scheduled_for')->update([
        'series_started_at' => DB::raw('scheduled_for'),
    ]);
}

public function down(): void
{
    Schema::table('actions', function (Blueprint $table): void {
        $table->dropColumn('series_started_at');
    });
}
```

`backfill_occurrences_for_existing_logs` — pragmatic per the spec: one synthesised occurrence per existing log, dated at its `logged_at`.

```php
public function up(): void
{
    $logs = DB::table('action_logs')
        ->whereNull('occurrence_id')
        ->orderBy('id')
        ->get(['id', 'action_id', 'logged_at']);

    foreach ($logs as $log) {
        $existing = DB::table('occurrences')
            ->where('action_id', $log->action_id)
            ->where('scheduled_for', $log->logged_at)
            ->value('id');

        $occurrenceId = $existing ?? DB::table('occurrences')->insertGetId([
            'action_id' => $log->action_id,
            'scheduled_for' => $log->logged_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('action_logs')->where('id', $log->id)->update(['occurrence_id' => $occurrenceId]);
    }
}

public function down(): void
{
    // Occurrences synthesised here go with the table itself; nothing to undo.
}
```

- [ ] **Step 4: Create the model**

```bash
php artisan make:model Occurrence --factory --no-interaction
```

`app/Models/Occurrence.php` — follow the sibling models' attribute style exactly:

```php
<?php

namespace App\Models;

use Database\Factories\OccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One instance of an action — the occasion an outcome attaches to. An
 * occurrence with no outcome and a scheduled time in the past is the unlogged
 * set a check-in asks about. Nothing expires it: it stays loggable forever.
 */
#[Fillable(['action_id', 'scheduled_for'])]
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime'];
    }

    /** Whether this occasion already carries its outcome. */
    public function isLogged(): bool
    {
        return $this->log()->exists();
    }

    /**
     * Occasions still awaiting an outcome — the catch-up set.
     *
     * @param  Builder<Occurrence>  $query
     */
    #[Scope]
    protected function unlogged(Builder $query): void
    {
        $query->whereDoesntHave('log');
    }

    /** @return BelongsTo<Action, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    /**
     * The one outcome recorded for this occasion, if it has been logged.
     *
     * @return HasOne<ActionLog, $this>
     */
    public function log(): HasOne
    {
        return $this->hasOne(ActionLog::class);
    }
}
```

`database/factories/OccurrenceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Occurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    protected $model = Occurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_id' => Action::factory(),
            'scheduled_for' => fake()->dateTimeBetween('-2 weeks', 'now'),
        ];
    }

    /** An occasion in the past that nobody has logged yet. */
    public function unlogged(): static
    {
        return $this->state(['scheduled_for' => now()->subDay()]);
    }
}
```

- [ ] **Step 5: Wire the relations onto the existing models**

In `app/Models/Action.php`: add `'series_started_at'` to the `#[Fillable]` list, add `'series_started_at' => 'immutable_datetime'` to `casts()`, and add:

```php
    /**
     * Every materialised instance of this action, oldest first. The action row
     * is the standing prescription; these are the occasions it produced.
     *
     * @return HasMany<Occurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }
```

In `app/Models/ActionLog.php`: add `'occurrence_id'`, `'context'`, `'context_fields'` to `#[Fillable]`, add `'context_fields' => 'array'` to `casts()`, and add:

```php
    /** @return BelongsTo<Occurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --compact --filter=OccurrenceSchemaTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Prove nothing else broke**

Run: `php artisan test --compact`
Expected: PASS. If anything fails, it is a real regression from the schema change — fix it before committing.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): give each instance of an action its own row"
```

---

### Task 2: Set the series anchor wherever an action is created

**Files:**
- Modify: `app/Actions/StartExperiment.php` (`authorActionFor`)
- Modify: `app/Actions/PersistAuthoredIntention.php`
- Modify: `app/Actions/RescheduleAction.php` (leave the anchor alone — assert it)
- Modify: `database/factories/ActionFactory.php`
- Test: `tests/Feature/Actions/SeriesAnchorTest.php`

**Interfaces:**
- Consumes: `Action::$series_started_at` from Task 1.
- Produces: the invariant every later task depends on — **every action with a `scheduled_for` at creation has `series_started_at` set to that same value.**

**Amendment made during implementation.** The anchor marks where the action's *current* cadence began, so `RescheduleAction` re-anchors it rather than leaving it frozen: it sets `series_started_at` to the newly computed `scheduled_for` (null when the edit turns the action cue-anchored and clears the schedule). Leaving it frozen would materialise every future occasion at the *old* time of day, and — worse — would keep producing a phantom slot for an action whose schedule had been cleared entirely. Occurrences already materialised are never touched, and `pending-outcomes` materialises on every read, so nothing already in the past is lost by re-anchoring.

- [ ] **Step 1: Read the creation sites**

Read `app/Actions/PersistAuthoredIntention.php` and `app/Actions/RescheduleAction.php` in full before editing. There are exactly three places an `Action` row is written: `PersistAuthoredIntention` (loop creation), `StartExperiment::authorActionFor()` (each new experiment), and `RescheduleAction` (which must **only** move `scheduled_for`).

- [ ] **Step 2: Write the failing test**

`tests/Feature/Actions/SeriesAnchorTest.php`:

```php
<?php

namespace Tests\Feature\Actions;

use App\Actions\RescheduleAction;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_loop_created_through_the_authoring_path_anchors_its_action(): void
    {
        // Build the loop the same way CreateLoopToolTest does, then assert:
        // every created action with a scheduled_for has series_started_at equal to it.
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->createLoopForUser($user);

        $action = $loop->actions()->whereNotNull('scheduled_for')->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->equalTo($action->scheduled_for));
    }

    public function test_rescheduling_moves_the_next_due_pointer_but_never_the_anchor(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeek()->startOfHour();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['scheduled_for' => $anchor, 'series_started_at' => $anchor, 'recurrence' => 'daily']);

        app(RescheduleAction::class)->handle($user, $action, ['time' => '19:30']);

        $fresh = $action->fresh();
        $this->assertTrue($fresh->series_started_at->equalTo($anchor));
        $this->assertFalse($fresh->scheduled_for->equalTo($anchor));
    }
}
```

Fill `createLoopForUser()` by copying the setup already used in `tests/Feature/Mcp/CreateLoopToolTest.php`; and match `RescheduleAction::handle()`'s real signature — read it first and adjust the second test's call to whatever it actually takes.

- [ ] **Step 3: Run it and watch it fail**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Expected: FAIL — `series_started_at` is null on the authored action.

- [ ] **Step 4: Set the anchor at both creation sites**

In `StartExperiment::authorActionFor()`, the `$strategy->actions()->create([...])` call gains one line beside `'scheduled_for' => $scheduledFor`:

```php
            'series_started_at' => $scheduledFor,
```

Do the same in `PersistAuthoredIntention` wherever it creates an action, using that method's own scheduled-for variable. Add to each a one-line docblock note: *the anchor is the initial scheduled_for and is never mutated afterwards.*

In `ActionFactory::definition()`, keep the factory honest so every test fixture holds the invariant:

```php
    public function definition(): array
    {
        $scheduledFor = fake()->dateTimeBetween('-3 days', '+4 days');

        return [
            // ... unchanged keys ...
            'scheduled_for' => $scheduledFor,
            'series_started_at' => $scheduledFor,
            // ... unchanged keys ...
        ];
    }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Expected: PASS.

Run: `php artisan test --compact --filter=StartExperimentTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): anchor every action's series at creation"
```

---

### Task 3: Lazy materialisation

**Files:**
- Create: `app/Services/Scheduling/MaterialiseOccurrences.php`
- Test: `tests/Feature/Scheduling/MaterialiseOccurrencesTest.php`

**Interfaces:**
- Consumes: `Action::$series_started_at`, `Occurrence`, `Schedule::advance()`, `Recurrence::tryFromToken()`.
- Produces:
  - `MaterialiseOccurrences::forUser(User $user): int` — materialises every eligible action on the user's **active** loops, returns rows created.
  - `MaterialiseOccurrences::forLoop(Intention $loop): int` — same, one loop.
  - `MaterialiseOccurrences::MAX_SLOTS_PER_ACTION = 1000`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Scheduling/MaterialiseOccurrencesTest.php`:

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialiseOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    private function dailyAction(User $user, string $loopStatus = Intention::STATUS_ACTIVE): Action
    {
        $anchor = now()->subDays(4)->setTime(19, 0);

        return Action::factory()
            ->for(Intention::factory()->for($user)->state(['status' => $loopStatus]))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);
    }

    public function test_it_materialises_every_past_slot_up_to_now(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->dailyAction($user);

        $created = app(MaterialiseOccurrences::class)->forUser($user);

        // Anchor + 4 elapsed days = 5 slots at or before now, none in the future.
        $this->assertSame(5, $created);
        $this->assertSame(5, $action->occurrences()->count());
        $this->assertSame(0, $action->occurrences()->where('scheduled_for', '>', now())->count());
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user);

        $service = app(MaterialiseOccurrences::class);
        $service->forUser($user);
        $second = $service->forUser($user);

        $this->assertSame(0, $second);
        $this->assertSame(5, Occurrence::count());
    }

    public function test_a_paused_loop_does_not_materialise(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user, Intention::STATUS_PAUSED);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
        $this->assertSame(0, Occurrence::count());
    }

    public function test_an_archived_action_does_not_materialise(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user)->update(['status' => Action::STATUS_ARCHIVED]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_a_one_off_action_materialises_exactly_one_slot(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subDays(3);
        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => null,
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(1, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_an_anchored_action_with_no_schedule_materialises_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => null,
                'scheduled_for' => null,
                'series_started_at' => null,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_a_future_anchor_materialises_nothing_yet(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->addDays(2);
        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_it_does_not_touch_another_users_loops(): void
    {
        $mine = User::factory()->create(['timezone' => 'UTC']);
        $theirs = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($theirs);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($mine));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=MaterialiseOccurrencesTest`
Expected: FAIL — `Class "App\Services\Scheduling\MaterialiseOccurrences" not found`.

- [ ] **Step 3: Implement the service**

```bash
php artisan make:class Services/Scheduling/MaterialiseOccurrences --no-interaction
```

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Turns an action's standing schedule into the concrete occasions it has
 * produced so far. Lazy: called on read, never from a write path, so nothing
 * silently materialises as a side effect of logging.
 *
 * Idempotent and safe under concurrent reads — rows go in through an upsert
 * against the unique (action_id, scheduled_for) index, so an overlapping run
 * writes no duplicates and needs no lock.
 *
 * Walks forward from the action's immutable `series_started_at` with
 * Schedule::advance(), which preserves wall-clock time in the user's timezone
 * and so stays DST-correct and keeps weekly's weekday.
 */
final readonly class MaterialiseOccurrences
{
    /** A very old anchor must not be able to run away; one pass stops here. */
    public const MAX_SLOTS_PER_ACTION = 1000;

    public function __construct(private Schedule $schedule) {}

    /** Materialise across every active loop this user owns. Returns rows created. */
    public function forUser(User $user): int
    {
        return $this->run(
            Action::query()->whereHas(
                'intention',
                fn (Builder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('status', Intention::STATUS_ACTIVE),
            ),
            $user->timezone ?? (string) config('app.timezone'),
        );
    }

    /** Materialise one loop. A loop that is not active materialises nothing. */
    public function forLoop(Intention $loop): int
    {
        if (! $loop->isActive()) {
            return 0;
        }

        return $this->run(
            $loop->actions()->getQuery(),
            $loop->user?->timezone ?? (string) config('app.timezone'),
        );
    }

    /**
     * @param  Builder<Action>  $actions
     */
    private function run(Builder $actions, string $timezone): int
    {
        $eligible = $actions
            ->whereNotNull('series_started_at')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->get();

        $created = 0;

        foreach ($eligible as $action) {
            $created += $this->materialise($action, $timezone);
        }

        return $created;
    }

    private function materialise(Action $action, string $timezone): int
    {
        $now = CarbonImmutable::now();
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $slots = [];
        $slot = $action->series_started_at->toImmutable();

        while ($slot->lessThanOrEqualTo($now) && count($slots) < self::MAX_SLOTS_PER_ACTION) {
            $slots[] = [
                'action_id' => $action->id,
                'scheduled_for' => $slot->utc()->toDateTimeString(),
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ];

            $next = $this->schedule->advance($slot, $recurrence, $timezone);

            // A one-off has no next slot: it produces exactly the anchor.
            if ($next === null) {
                break;
            }

            $slot = $next;
        }

        if ($slots === []) {
            return 0;
        }

        $before = $action->occurrences()->count();

        // Update nothing on conflict: an already-materialised slot is left
        // exactly as it is, outcome and all.
        Occurrence::query()->upsert($slots, ['action_id', 'scheduled_for'], []);

        return $action->occurrences()->count() - $before;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=MaterialiseOccurrencesTest`
Expected: PASS (8 tests). If the count in `test_it_materialises_every_past_slot_up_to_now` is off by one, check whether the anchor time-of-day falls before or after `now()`'s time-of-day rather than changing the assertion blindly — the arithmetic is `anchor + N days <= now`.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): materialise occurrences lazily from the series anchor"
```

---

### Task 4: `LogAction` writes against an occurrence

**Files:**
- Modify: `app/Actions/LogAction.php`
- Test: `tests/Feature/Actions/LogActionTest.php` (create if absent; otherwise extend)

**Interfaces:**
- Consumes: `Occurrence`, `Action::$series_started_at`.
- Produces: `LogAction::handle(User $user, Action $action, array $data, ?Occurrence $occurrence = null): ActionLog`
  - `$data` accepts the existing `outcome`, `reason`, `metadata` plus new `context` (`?string`) and `context_fields` (`?array`).
  - When `$occurrence` is null the live slot is resolved or an ad hoc occurrence is created — so **every** log ends up with an `occurrence_id` and existing callers (web `ActionLogController`, `Api\ActionLogController`) keep working unchanged.
  - Roll-forward happens **only** when the logged occurrence is at or after the action's current `scheduled_for`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Actions/LogActionTest.php`:

```php
<?php

namespace Tests\Feature\Actions;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogActionTest extends TestCase
{
    use RefreshDatabase;

    private function recurringAction(User $user): Action
    {
        $anchor = now()->subDays(5)->setTime(19, 0);

        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => now()->setTime(19, 0),
                'series_started_at' => $anchor,
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    public function test_logging_an_occurrence_attaches_the_outcome_to_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Ate standing up, second plate before I noticed',
        ], $occurrence);

        $this->assertSame($occurrence->id, $log->occurrence_id);
        $this->assertSame($action->id, $log->action_id);
        $this->assertSame('Ate standing up, second plate before I noticed', $log->reason);
    }

    public function test_a_catch_up_log_does_not_move_the_next_due_pointer(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $nextDue = $action->scheduled_for;
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        $this->assertTrue($action->fresh()->scheduled_for->equalTo($nextDue));
    }

    public function test_logging_the_live_slot_still_rolls_the_action_forward(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => $action->scheduled_for,
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
    }

    public function test_the_series_anchor_never_moves(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $anchor = $action->series_started_at;

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertTrue($action->fresh()->series_started_at->equalTo($anchor));
    }

    public function test_a_caller_that_passes_no_occurrence_still_gets_one(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        $log = app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertNotNull($log->occurrence_id);
        $this->assertTrue($log->occurrence->scheduled_for->equalTo($action->scheduled_for));
    }

    public function test_a_second_log_on_an_already_logged_slot_records_as_its_own_occasion(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        // A failure leaves the action open on the same slot, so the next log
        // resolves to the same live slot — it must not collide.
        $first = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Did not think about it at all',
        ]);
        $second = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Second plate again',
        ]);

        $this->assertNotSame($first->occurrence_id, $second->occurrence_id);
        $this->assertSame(2, ActionLog::count());
    }

    public function test_it_stores_context_and_context_fields(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Kept going past full',
            'context' => 'Standing at the bench, plate refilled straight away',
            'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
        ]);

        $this->assertSame('Standing at the bench, plate refilled straight away', $log->context);
        $this->assertSame('kitchen', $log->context_fields['place']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: FAIL — `handle()` takes three arguments.

- [ ] **Step 3: Rewrite `LogAction::handle` and its helpers**

Signature and body — everything else in the class (`markCueAnswered`, `actionStatusFor`, `ActionLogged::dispatch`) stays as it is:

```php
    /**
     * @param  array<string, mixed>  $data  Validated outcome / reason / context / metadata.
     * @param  Occurrence|null  $occurrence  The occasion being logged. Null means
     *                                       "the live slot" — the pointer the action
     *                                       currently sits on — which is what the web
     *                                       and JSON API surfaces mean when they log a card.
     */
    public function handle(User $user, Action $action, array $data, ?Occurrence $occurrence = null): ActionLog
    {
        return DB::transaction(function () use ($user, $action, $data, $occurrence): ActionLog {
            $occurrence ??= $this->liveSlotFor($action);

            $log = $action->logs()->create([
                'user_id' => $user->id,
                'occurrence_id' => $occurrence->id,
                'outcome' => $data['outcome'],
                // Verbatim. Never trimmed, squished or sentence-cased: this is
                // the raw material the next strategy version is written from.
                'reason' => $data['reason'] ?? null,
                'context' => $data['context'] ?? null,
                'context_fields' => $data['context_fields'] ?? null,
                'logged_at' => Date::now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $status = $this->actionStatusFor($data['outcome']);

            if ($status !== null && $this->isLiveSlot($action, $occurrence)) {
                $this->closeOrRearm($user, $action, $status);
            }

            $this->markCueAnswered($user, $action);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
        });
    }

    /**
     * The occasion a caller means when it names no occurrence: the slot the
     * action's next-due pointer currently sits on, or — for an anchored action
     * with no schedule, and for a slot that already carries an outcome — a
     * fresh occasion stamped now.
     */
    private function liveSlotFor(Action $action): Occurrence
    {
        if ($action->scheduled_for !== null) {
            $slot = Occurrence::query()->firstOrCreate([
                'action_id' => $action->id,
                'scheduled_for' => $action->scheduled_for,
            ]);

            if (! $slot->isLogged()) {
                return $slot;
            }
        }

        return Occurrence::query()->create([
            'action_id' => $action->id,
            'scheduled_for' => Date::now(),
        ]);
    }

    /**
     * Whether this occurrence is the one the action is currently pointing at.
     * Catching up an older occasion must never move the next-due pointer —
     * that pointer is what the trigger engine and the action cards read.
     */
    private function isLiveSlot(Action $action, Occurrence $occurrence): bool
    {
        return $action->scheduled_for !== null
            && $occurrence->scheduled_for->greaterThanOrEqualTo($action->scheduled_for);
    }
```

Update the class docblock: an outcome now attaches to an occurrence; the action row stays the standing prescription and next-due pointer; roll-forward only fires on the live slot.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: PASS (7 tests).

Run: `php artisan test --compact --filter=ActionLogWebTest`
Run: `php artisan test --compact --filter=LogActionOutcomeToolTest`
Expected: PASS — the default-null occurrence keeps both surfaces working.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): attach every outcome to the occasion it describes"
```

---

### Task 5: `log-outcome` replaces `log-action-outcome`

**Files:**
- Create: `app/Mcp/Tools/LogOutcomeTool.php`
- Delete: `app/Mcp/Tools/LogActionOutcomeTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (registration + `#[Instructions]`)
- Create: `tests/Feature/Mcp/LogOutcomeToolTest.php`
- Delete: `tests/Feature/Mcp/LogActionOutcomeToolTest.php` (its cases move across — see step 1)
- Modify: `tests/Feature/Mcp/McpEndpointTest.php` (tool-name list)

**Interfaces:**
- Consumes: `LogAction::handle(..., ?Occurrence)`, `Occurrence`.
- Produces: MCP tool `log-outcome` with schema
  `occurrence_id?: int`, `action_id?: int`, `occurred_at?: string`, `outcome: enum`, `reason?: string`, `context?: string`, `context_fields?: object{place?: string, with_others?: bool, preceded_by?: string}`.
  Response: `{log_id, occurrence_id, occurred_at, outcome, reason, context, context_fields, loop_id, loop_title, action_title}`.

- [ ] **Step 1: Port the existing test file**

Copy `tests/Feature/Mcp/LogActionOutcomeToolTest.php` to `tests/Feature/Mcp/LogOutcomeToolTest.php`, rename the class, and rewrite each case against `LogOutcomeTool` — keeping every behaviour it already guards (unknown id, ownership, reason-required-on-failure, the 2000-character boundary pair, unknown outcome, recurring roll-forward). The old file is deleted only because its subject is; no coverage is lost.

Then add the new cases:

```php
    public function test_it_logs_a_specific_past_occurrence_without_moving_the_next_due_pointer(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $nextDue = $action->scheduled_for;
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Second plate before I noticed',
        ]);

        $response->assertOk();
        $this->assertSame($occurrence->id, $this->payload($response)['occurrence_id']);
        $this->assertTrue($action->fresh()->scheduled_for->equalTo($nextDue));
    }

    public function test_it_stores_the_reason_verbatim(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = Occurrence::factory()->create(['action_id' => $this->recurringAction($user)->id]);
        $reason = '  didn\'t Think about it AT ALL.  ';

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ])->assertOk();

        $this->assertSame($reason, ActionLog::firstOrFail()->reason);
    }

    public function test_it_refuses_to_log_an_occurrence_twice(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = Occurrence::factory()->create(['action_id' => $this->recurringAction($user)->id]);

        $args = [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ];

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, $args)->assertOk();
        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, $args)
            ->assertHasErrors(['That occurrence already has an outcome.']);

        $this->assertSame(1, ActionLog::count());
    }

    public function test_it_logs_an_anchored_action_ad_hoc_against_a_supplied_datetime(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['recurrence' => null, 'scheduled_for' => null, 'series_started_at' => null]);

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'action_id' => $action->id,
            'occurred_at' => '2026-08-24T19:30:00+00:00',
            'outcome' => ActionLog::OUTCOME_SKIPPED,
        ])->assertOk();

        $this->assertSame(
            '2026-08-24 19:30:00',
            Occurrence::firstOrFail()->scheduled_for->utc()->toDateTimeString(),
        );
    }

    public function test_it_rejects_a_call_that_names_neither_an_occurrence_nor_an_action(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, ['outcome' => ActionLog::OUTCOME_COMPLETED])
            ->assertHasErrors();
    }

    public function test_it_rejects_a_call_that_names_both(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $occurrence = Occurrence::factory()->create(['action_id' => $action->id]);

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'occurrence_id' => $occurrence->id,
            'action_id' => $action->id,
            'occurred_at' => now()->toIso8601String(),
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ])->assertHasErrors();
    }

    public function test_it_rejects_an_unknown_context_field(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = Occurrence::factory()->create(['action_id' => $this->recurringAction($user)->id]);

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'context_fields' => ['calories' => 900],
        ])->assertHasErrors();
    }

    public function test_it_cannot_log_another_users_occurrence(): void
    {
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->recurringAction(User::factory()->create(['timezone' => 'UTC']))->id,
        ]);

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, ActionLog::count());
    }
```

Note the last-but-one test: `calories` is rejected both because the field set is closed **and** because no quantity belongs in an eating loop's data model.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=LogOutcomeToolTest`
Expected: FAIL — `App\Mcp\Tools\LogOutcomeTool` not found.

- [ ] **Step 3: Write the tool**

```php
<?php

namespace App\Mcp\Tools;

use App\Actions\LogAction;
use App\Models\ActionLog;
use App\Models\Occurrence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('log-outcome')]
#[Description(<<<'TEXT'
Record what happened on one occasion: completed, failed, or skipped. Name an
occurrence_id from pending-outcomes to log it — including one from days ago,
which is how a catch-up works. For an unscheduled, cue-anchored action pass
action_id plus occurred_at instead.

skipped means the occasion never happened at all (no meal, travelling, ill).
If the occasion happened and the strategy did not hold — including simply not
thinking about it — that is failed, and it MUST carry the user's stated reason.
Ask them why and pass their own words through unchanged.
TEXT)]
class LogOutcomeTool extends Tool
{
    /** The structured context set, deliberately small so the free text stays primary. */
    private const CONTEXT_FIELDS = ['place', 'with_others', 'preceded_by'];

    public function handle(Request $request, LogAction $log): Response
    {
        $validated = $request->validate([
            'occurrence_id' => ['nullable', 'integer', 'required_without:action_id'],
            'action_id' => ['nullable', 'integer', 'required_without:occurrence_id'],
            'occurred_at' => ['nullable', 'date', 'required_with:action_id'],
            'outcome' => ['required', 'string', Rule::in(ActionLog::OUTCOMES)],
            'reason' => [
                Rule::requiredIf(fn (): bool => $request->get('outcome') === ActionLog::OUTCOME_FAILED),
                'nullable',
                'string',
                'max:2000',
            ],
            'context' => ['nullable', 'string', 'max:2000'],
            'context_fields' => ['nullable', 'array'],
            'context_fields.place' => ['nullable', 'string', 'max:120'],
            'context_fields.with_others' => ['nullable', 'boolean'],
            'context_fields.preceded_by' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($validated['occurrence_id'], $validated['action_id'])) {
            return Response::error('Pass either occurrence_id or action_id, not both.');
        }

        $unknown = array_diff(array_keys($validated['context_fields'] ?? []), self::CONTEXT_FIELDS);

        if ($unknown !== []) {
            return Response::error('Unknown context field(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::CONTEXT_FIELDS).'.');
        }

        $occurrence = isset($validated['occurrence_id'])
            ? $this->ownedOccurrence($request, (int) $validated['occurrence_id'])
            : $this->adHocOccurrence($request, (int) $validated['action_id'], (string) $validated['occurred_at']);

        if (! $occurrence instanceof Occurrence) {
            return Response::error('Not found.');
        }

        if ($occurrence->isLogged()) {
            return Response::error('That occurrence already has an outcome.');
        }

        $entry = $log->handle(
            $request->user(),
            $occurrence->action,
            Arr::only($validated, ['outcome', 'reason', 'context', 'context_fields']),
            $occurrence,
        );

        $action = $occurrence->action;

        return Response::json([
            'log_id' => $entry->id,
            'occurrence_id' => $occurrence->id,
            'occurred_at' => $occurrence->scheduled_for->toIso8601String(),
            'outcome' => $entry->outcome,
            'reason' => $entry->reason,
            'context' => $entry->context,
            'context_fields' => $entry->context_fields,
            'loop_id' => $action->intention_id,
            'loop_title' => $action->intention->title,
            'action_title' => $action->title,
        ]);
    }

    private function ownedOccurrence(Request $request, int $id): ?Occurrence
    {
        return Occurrence::query()
            ->with('action.intention')
            ->whereKey($id)
            ->whereHas('action.intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();
    }

    /**
     * An anchored action has no scheduled time, so it can never go unlogged —
     * it is logged ad hoc against the datetime the user gives, and the occasion
     * is created at that moment.
     */
    private function adHocOccurrence(Request $request, int $actionId, string $occurredAt): ?Occurrence
    {
        $action = \App\Models\Action::query()
            ->with('intention')
            ->whereKey($actionId)
            ->whereHas('intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();

        if ($action === null) {
            return null;
        }

        return Occurrence::query()->firstOrCreate([
            'action_id' => $action->id,
            'scheduled_for' => \Illuminate\Support\Facades\Date::parse($occurredAt),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'occurrence_id' => $schema->integer()
                ->description('The occasion being logged, as returned by pending-outcomes. Use this for any scheduled action, including a catch-up from days ago.'),
            'action_id' => $schema->integer()
                ->description('Only for an unscheduled, cue-anchored action. Pass occurred_at with it.'),
            'occurred_at' => $schema->string()
                ->description('ISO-8601 datetime the occasion happened. Required with action_id.'),
            'outcome' => $schema->string()
                ->enum(ActionLog::OUTCOMES)
                ->description('completed, failed, or skipped. skipped means the occasion never happened.')
                ->required(),
            'reason' => $schema->string()
                ->description('The user\'s own words, passed through unchanged. Required when the outcome is failed.'),
            'context' => $schema->string()
                ->description('Free text: the mechanics of what happened. The primary record.'),
            'context_fields' => $schema->object()
                ->description('Optional structured context. Only place (string), with_others (boolean) and preceded_by (string) are accepted.'),
        ];
    }
}
```

Import `App\Models\Action` and `Illuminate\Support\Facades\Date` properly at the top rather than inline-qualifying them; the inline form above is only to keep this snippet self-contained.

- [ ] **Step 4: Register it and rewrite the server instructions**

In `app/Mcp/Servers/PatYourSelfServer.php` swap `LogActionOutcomeTool::class` for `LogOutcomeTool::class` and replace the whole `#[Instructions]` body. The rewrite is owed from phase 4 — the current text still describes the deleted coach-driven model. Every advertised tool name must appear in it (`McpEndpointTest` asserts exactly that):

```php
#[Instructions(<<<'TEXT'
PatYourSelf is the user's lab notebook for changing a habit. You are the coach;
the app is the record. It stores the evidence and the statistics — it does no
reasoning of its own.

A "loop" models one behaviour as a cue -> craving -> response -> reward chain.
A strategy version is one experiment on that loop: a hypothesis, one point in
the chain it intervenes on, and a planned length. Versions are append-only —
a new one supersedes the old, and history is never rewritten.

An "occurrence" is one occasion an action was meant to happen. Outcomes attach
to occurrences, so an occasion from three days ago can still be logged today.
Nothing ever expires.

A check-in usually goes: pending-outcomes to see what went unlogged, log-outcome
for each occasion in the user's own words, loop-outcomes to read the reasons
back, then start-experiment when the current intervention point is not holding.

Use list-loops and get-loop to see what the user is working on, today-actions
for what is due now, and loop-progress for how the current experiment is going
against the loop's lifetime record.

Three rules that matter:

- A failed outcome MUST carry the user's stated reason. Ask before logging, and
  pass their words through unchanged — those reasons are what the next
  experiment is written from.
- skipped means the occasion never happened (no meal, travelling, ill). If it
  happened and the strategy did not hold — including not thinking about it —
  that is failed.
- A failure is about the strategy, never about the user. Do not frame it as
  discipline, willpower or motivation, and never propose a numeric target.

Use create-loop when the user wants to start a new habit. Ask them for their
real cue, craving, response and reward and get their agreement on the wording —
do not invent the chain for them. New loops are created paused; tell the user to
open the app to review and activate.
TEXT)]
```

Then update `McpEndpointTest::test_advertises_all_six_tools_under_their_documented_names` — the count changes as later tasks add tools, so for now assert:

```php
        $this->assertSame(
            ['list-loops', 'get-loop', 'today-actions', 'log-outcome', 'loop-progress', 'create-loop'],
            array_column($response->json('result.tools'), 'name'),
        );
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=LogOutcomeToolTest`
Run: `php artisan test --compact --filter=McpEndpointTest`
Expected: PASS both.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): log-outcome replaces log-action-outcome"
```

---

### Task 6: `pending-outcomes`

**Files:**
- Create: `app/Mcp/Tools/PendingOutcomesTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`
- Create: `tests/Feature/Mcp/PendingOutcomesToolTest.php`
- Modify: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `MaterialiseOccurrences::forUser()`, `Occurrence::unlogged()`.
- Produces: MCP tool `pending-outcomes`, schema `since?: string` (ISO-8601 date/datetime, default 14 days ago).
  Response: `{since, count, truncated, occurrences: [{occurrence_id, loop_id, loop_title, action_id, action_title, scheduled_for}]}`, newest first.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Mcp/PendingOutcomesToolTest.php` — cases:

```php
    public function test_it_lists_unlogged_past_occasions_newest_first(): void
    public function test_it_materialises_before_reading_so_a_never_logged_action_still_appears(): void
    public function test_it_omits_an_occasion_that_already_has_an_outcome(): void
    public function test_it_omits_future_occasions(): void
    public function test_it_defaults_to_the_last_fourteen_days(): void
    public function test_an_older_since_reaches_further_back(): void
    public function test_it_omits_paused_and_archived_loops(): void
    public function test_it_omits_another_users_occasions(): void
```

Write each body in the style of `LogOutcomeToolTest` (same `payload()` reflection helper, `PatYourSelfServer::actingAs`). For the default-window case, create one occurrence 3 days old and one 30 days old and assert only the recent id comes back; then pass `since` 60 days back and assert both do. For the materialise case, create a daily action anchored 4 days ago with **no** occurrences and assert the response is non-empty.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=PendingOutcomesToolTest`
Expected: FAIL — tool class not found.

- [ ] **Step 3: Write the tool**

```php
#[Name('pending-outcomes')]
#[Description(<<<'TEXT'
Occasions that have already passed and carry no outcome yet — what to ask the
user about when a check-in opens. Newest first, defaulting to the last 14 days
so a long gap does not turn the conversation into an audit.

Nothing here is overdue and nothing expires: an occasion stays loggable
forever. Pass an older `since` when the user wants to go further back. Do not
present this list as debt.
TEXT)]
class PendingOutcomesTool extends Tool
{
    private const DEFAULT_WINDOW_DAYS = 14;

    private const LIMIT = 100;

    public function handle(Request $request, MaterialiseOccurrences $materialise): Response
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $since = isset($validated['since'])
            ? Date::parse($validated['since'])
            : Date::now()->subDays(self::DEFAULT_WINDOW_DAYS);

        // Lazy materialisation: the read path is the only thing that creates
        // occurrences, so this is where an unlogged occasion first appears.
        $materialise->forUser($user);

        $occurrences = Occurrence::query()
            ->unlogged()
            ->with('action.intention:id,title')
            ->where('scheduled_for', '<=', Date::now())
            ->where('scheduled_for', '>=', $since)
            ->whereHas('action.intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->orderByDesc('scheduled_for')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $occurrences->count() > self::LIMIT;

        return Response::json([
            'since' => $since->toIso8601String(),
            'count' => min($occurrences->count(), self::LIMIT),
            'truncated' => $truncated,
            'occurrences' => $occurrences->take(self::LIMIT)->map(fn (Occurrence $occurrence): array => [
                'occurrence_id' => $occurrence->id,
                'loop_id' => $occurrence->action->intention_id,
                'loop_title' => $occurrence->action->intention->title,
                'action_id' => $occurrence->action_id,
                'action_title' => $occurrence->action->title,
                'scheduled_for' => $occurrence->scheduled_for->timezone($timezone)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()
                ->description('ISO-8601 date or datetime. Defaults to 14 days ago. Older occasions are never discarded — pass an earlier date to reach them.'),
        ];
    }
}
```

Register it in `PatYourSelfServer::$tools` after `TodayActionsTool::class` and add `pending-outcomes` to the `McpEndpointTest` name list in the same position.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=PendingOutcomesToolTest`
Run: `php artisan test --compact --filter=McpEndpointTest`
Expected: PASS both.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): pending-outcomes opens the check-in"
```

---

### Task 7: `loop-outcomes` — the reasons, readable at last

**Files:**
- Create: `app/Mcp/Tools/LoopOutcomesTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`
- Create: `tests/Feature/Mcp/LoopOutcomesToolTest.php`
- Modify: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `ActionLog::occurrence()`, `Action::strategy()`.
- Produces: MCP tool `loop-outcomes`, schema `intention_id: int` (required), `since?: string`.
  Response: `{loop_id, title, since, count, truncated, outcomes: [{log_id, occurrence_id, occurred_at, logged_at, action_id, action_title, outcome, reason, context, context_fields, strategy_version, intervention_point}]}`, newest occasion first.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Mcp/LoopOutcomesToolTest.php` — cases:

```php
    public function test_it_returns_the_reason_verbatim(): void
    public function test_it_dates_an_outcome_by_the_occasion_not_by_when_it_was_typed(): void
    public function test_it_names_the_strategy_version_that_was_running_at_the_time(): void
    public function test_it_returns_context_and_context_fields(): void
    public function test_since_filters_by_the_occasion_datetime(): void
    public function test_it_rejects_another_users_loop(): void
```

For `test_it_dates_an_outcome_by_the_occasion_not_by_when_it_was_typed`: create an occurrence scheduled 5 days ago, log it now, assert `occurred_at` is the 5-days-ago datetime and `logged_at` is today. That single assertion is the whole point of the occurrence entity — do not weaken it.

For the strategy-version case: create two strategy versions on one loop with actions bound to each (`Action::factory()->for($strategyV1)`), log an outcome under each, and assert each entry carries the right `strategy_version` and `intervention_point`.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=LoopOutcomesToolTest`
Expected: FAIL — tool class not found.

- [ ] **Step 3: Write the tool**

Query shape — join through the action so a log always reports the experiment that was running when it was made:

```php
        $logs = ActionLog::query()
            ->with(['occurrence', 'action.strategy'])
            ->whereHas('action', fn (Builder $query) => $query->where('intention_id', $loop->id))
            ->when($since !== null, fn (Builder $query) => $query
                ->whereHas('occurrence', fn (Builder $inner) => $inner->where('scheduled_for', '>=', $since)))
            ->get()
            ->sortByDesc(fn (ActionLog $log): string => (string) ($log->occurrence?->scheduled_for ?? $log->logged_at))
            ->take(self::LIMIT);
```

Each entry:

```php
            'log_id' => $log->id,
            'occurrence_id' => $log->occurrence_id,
            'occurred_at' => ($log->occurrence?->scheduled_for ?? $log->logged_at)->timezone($timezone)->toIso8601String(),
            'logged_at' => $log->logged_at->timezone($timezone)->toIso8601String(),
            'action_id' => $log->action_id,
            'action_title' => $log->action->title,
            'outcome' => $log->outcome,
            // Verbatim, exactly as the user said it.
            'reason' => $log->reason,
            'context' => $log->context,
            'context_fields' => $log->context_fields,
            'strategy_version' => $log->action->strategy?->version,
            'intervention_point' => $log->action->strategy?->intervention_point,
```

`#[Description]`:

```
Every outcome recorded on one loop, newest occasion first — with the user's
stated reason exactly as they said it, the context around it, and which
strategy version was running at the time. This is the raw material for the next
experiment: read it before proposing one. Aggregates live in loop-progress.
```

`LIMIT = 200`. Ownership via `$request->user()->intentions()->find(...)`, returning `Response::error('Not found.')` — the same shape `GetLoopTool` uses. Register after `PendingOutcomesTool::class`; update the `McpEndpointTest` list.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=LoopOutcomesToolTest`
Run: `php artisan test --compact --filter=McpEndpointTest`
Expected: PASS both.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): loop-outcomes exposes the failure reasons"
```

---

### Task 8: `start-experiment`

**Files:**
- Create: `app/Mcp/Tools/StartExperimentTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`
- Create: `tests/Feature/Mcp/StartExperimentToolTest.php`
- Modify: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `StartExperiment::handle(Strategy $current, AuthoredStrategy $next, string $changeReason, ?string $supersededReason, ?int $reviewAfterDays, ?AuthoredAction $revisedAction)`, `Strategy::INTERVENTION_POINTS`, `Strategy::CHANGE_REASONS`.
- Produces: MCP tool `start-experiment`, schema `intention_id: int` (required), `intervention_point: enum` (required), `approach: string` (required), `rationale?: string`, `supersedes_reason?: string`, `review_after_days?: int`, `change_reason?: enum`.
  Response: `{loop_id, version, status, intervention_point, approach, rationale, review_at, planned_days, superseded: {version, superseded_reason}}`.

- [ ] **Step 1: Read `AuthoredStrategy` first**

Read `app/Services/Authoring/AuthoredStrategy.php` and construct it exactly as its constructor requires (`interventionPoint`, `approach`, `rationale`, `promptVersion`). Do not guess the property names.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Mcp/StartExperimentToolTest.php` — cases:

```php
    public function test_it_supersedes_the_active_version_and_activates_the_next(): void
    public function test_it_records_why_the_previous_version_stopped_being_right(): void
    public function test_a_review_after_days_sets_the_planned_length(): void
    public function test_omitting_review_after_days_leaves_the_experiment_open_ended(): void
    public function test_it_rejects_an_intervention_point_outside_the_chain(): void
    public function test_it_rejects_a_blank_approach(): void
    public function test_it_rejects_a_negative_review_after_days(): void
    public function test_it_errors_when_the_loop_has_no_active_version(): void
    public function test_it_never_edits_the_previous_version_in_place(): void
    public function test_it_rejects_another_users_loop(): void
```

`test_it_never_edits_the_previous_version_in_place` must assert the v1 row's `approach`, `intervention_point` and `rationale` are byte-identical after the call, and that a v2 row now exists — append-only is the core invariant.

`test_it_rejects_an_intervention_point_outside_the_chain` is the carry-forward landmine: `AuthoredStrategy` has no guard of its own and `ReviseStrategy::revise()` — which used to do this check — was deleted. Assert both `assertHasErrors()` **and** `assertDatabaseCount('strategies', 1)`.

- [ ] **Step 3: Run it and watch it fail**

Run: `php artisan test --compact --filter=StartExperimentToolTest`
Expected: FAIL — tool class not found.

- [ ] **Step 4: Write the tool**

```php
#[Name('start-experiment')]
#[Description(<<<'TEXT'
Start the next experiment on a loop: supersede the active strategy version and
activate a new one. Versions are append-only — nothing is ever edited, so a
version you get wrong is fixed by writing the next one, not by correcting it.

Read loop-outcomes first. supersedes_reason is about the strategy, not the
user: say why this intervention point stopped being the right one. Pass
review_after_days only if a planned length is genuinely useful; leaving it out
is an open-ended experiment, which is a perfectly good state.
TEXT)]
class StartExperimentTool extends Tool
{
    public function handle(Request $request, StartExperiment $start): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            // The validation the deleted ReviseStrategy used to hold. AuthoredStrategy
            // has no guard of its own, so this boundary is the only thing standing
            // between a malformed version and the database.
            'intervention_point' => ['required', 'string', Rule::in(Strategy::INTERVENTION_POINTS)],
            'approach' => ['required', 'string', 'min:1', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'supersedes_reason' => ['nullable', 'string', 'max:2000'],
            'review_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'change_reason' => ['nullable', 'string', Rule::in(Strategy::CHANGE_REASONS)],
        ]);

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $current = $loop->activeStrategy;

        if (! $current instanceof Strategy) {
            return Response::error('That loop has no active strategy version to supersede.');
        }

        $next = $start->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: $validated['intervention_point'],
                approach: $validated['approach'],
                rationale: $validated['rationale'] ?? null,
            ),
            $validated['change_reason'] ?? Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            $validated['supersedes_reason'] ?? null,
            $validated['review_after_days'] ?? null,
        );

        return Response::json([
            'loop_id' => $loop->id,
            'version' => $next->version,
            'status' => $next->status,
            'intervention_point' => $next->intervention_point,
            'approach' => $next->approach,
            'rationale' => $next->rationale,
            'review_at' => $next->review_at?->toIso8601String(),
            'planned_days' => $next->plannedDays(),
            'superseded' => [
                'version' => $current->version,
                'superseded_reason' => $current->fresh()->superseded_reason,
            ],
        ]);
    }
```

Adjust the `AuthoredStrategy` constructor call to its real signature (step 1). Schema descriptions: `intervention_point` — *"Which point of the cue → craving → response → reward chain this experiment intervenes on."*; `review_after_days` — *"Planned run length in days. Omit for an open-ended experiment."*.

Register **after** `CreateLoopTool::class`; update the `McpEndpointTest` list to the final nine names.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=StartExperimentToolTest`
Run: `php artisan test --compact --filter=StartExperimentTest`
Run: `php artisan test --compact --filter=McpEndpointTest`
Expected: PASS all three.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): start-experiment writes the next strategy version"
```

---

### Task 9: `loop-progress` reports both scopes

**Files:**
- Modify: `app/Services/Progress/LoopProgress.php`
- Modify: `app/Mcp/Tools/LoopProgressTool.php`
- Modify: `app/Mcp/Tools/GetLoopTool.php`
- Test: `tests/Feature/Progress/LoopProgressTest.php` (extend), `tests/Feature/Mcp/LoopProgressToolTest.php`, `tests/Feature/Mcp/GetLoopToolTest.php`

**Interfaces:**
- Consumes: `LoopProgress::forLoop()` (unchanged, still the lifetime block — `ProgressController` keeps calling it), `OutcomeStreak::forStrategy()`.
- Produces:
  - `LoopProgress::forCurrentVersion(Intention $loop): ?array` — `{version, started_at, day_of_experiment, planned_days, is_under_review, verdict, streak: {outcome, length}, completion_rate: ?int, totals: {completed, failed, skipped}, last_logged_at: ?string}`, or null when the loop has no active version.
  - `loop-progress` response: `{loop_id, title, current_version, lifetime}`.
  - `get-loop` strategy entries gain `outcomes_recorded: int`.

- [ ] **Step 1: Write the failing tests**

Extend `tests/Feature/Progress/LoopProgressTest.php`:

```php
    public function test_the_current_version_scope_counts_only_that_versions_outcomes(): void
    public function test_the_current_version_scope_is_null_when_no_version_is_active(): void
    public function test_skipped_outcomes_stay_out_of_both_denominators(): void
```

The third is the one that matters most: build 2 completed, 1 failed, 3 skipped and assert `completion_rate === 67` in both blocks while `totals.skipped === 3` — a low denominator has to stay visible rather than hidden.

In `tests/Feature/Mcp/LoopProgressToolTest.php` (rewrite the existing shape assertions):

```php
    public function test_it_returns_both_the_current_version_and_the_lifetime_blocks(): void
```

In `tests/Feature/Mcp/GetLoopToolTest.php`:

```php
    public function test_each_version_reports_how_many_outcomes_were_recorded_under_it(): void
```

with one version carrying 3 logs and a newer one carrying 0 — that is exactly the "failed vs never tested" distinction the tool exists to make.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=LoopProgressTest`
Expected: FAIL — `forCurrentVersion()` undefined.

- [ ] **Step 3: Add `forCurrentVersion` to `LoopProgress`**

```php
    /**
     * The active experiment's own record: streak, rate and totals since that
     * version began. This is the block that tells a strategy which is failing
     * from one which is working — `forLoop()` spans every version, so a fresh
     * intervention would otherwise drag the old evidence forward with it.
     *
     * `skipped` is excluded from the denominator (the occasion never happened)
     * and reported separately, so a thin sample is visible rather than hidden.
     *
     * @return array{...}|null  Null when the loop has no active version.
     */
    public function forCurrentVersion(Intention $loop): ?array
    {
        $strategy = $loop->activeStrategy;

        if ($strategy === null) {
            return null;
        }

        $logs = ActionLog::query()
            ->whereHas('action', fn ($query) => $query->where('strategy_id', $strategy->id))
            ->get(['id', 'outcome', 'logged_at']);

        $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();
        $failed = $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count();
        $skipped = $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count();
        $decided = $completed + $failed;

        [$outcome, $length] = $this->streak->forStrategy($strategy);

        return [
            'version' => $strategy->version,
            'started_at' => $strategy->created_at->toIso8601String(),
            'day_of_experiment' => $strategy->dayOfExperiment(),
            'planned_days' => $strategy->plannedDays(),
            'is_under_review' => $strategy->isUnderReview(),
            'verdict' => $strategy->verdict,
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $decided === 0 ? null : (int) round($completed / $decided * 100),
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            'last_logged_at' => $logs->max('logged_at')?->toIso8601String(),
        ];
    }
```

- [ ] **Step 4: Return both blocks from the tool**

`LoopProgressTool::handle` returns:

```php
        return Response::json([
            'loop_id' => $loop->id,
            'title' => $loop->title,
            'current_version' => $progress->forCurrentVersion($loop),
            'lifetime' => $progress->forLoop($loop),
        ]);
```

and its `#[Description]` becomes:

```
Two scopes for one loop: `current_version` is how the active experiment is
going on its own evidence, `lifetime` is the whole record across every version.
Read current_version to judge whether a strategy is working. skipped occasions
are excluded from both completion rates and reported as their own count.
```

- [ ] **Step 5: Add the per-version count to `get-loop`**

In `GetLoopTool::handle`, before mapping, count logs per version in one query:

```php
        $outcomeCounts = ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->where('actions.intention_id', $loop->id)
            ->groupBy('actions.strategy_id')
            ->selectRaw('actions.strategy_id, count(*) as total')
            ->pluck('total', 'strategy_id');
```

and add to each strategy entry:

```php
                // Tells a version that failed from one that was never tested.
                'outcomes_recorded' => (int) ($outcomeCounts[$strategy->id] ?? 0),
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=LoopProgressTest`
Run: `php artisan test --compact --filter=LoopProgressToolTest`
Run: `php artisan test --compact --filter=GetLoopToolTest`
Expected: PASS all three.

- [ ] **Step 7: Full suite**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): loop-progress reports the current experiment and the lifetime record"
```

---

## Self-review notes

- **Spec coverage.** Occurrence entity → T1; anchor invariant → T2; lazy materialisation → T3; outcomes repointed + existing-data migration → T1 (migration) and T4 (write path); `log-outcome` → T5; `pending-outcomes` → T6; `loop-outcomes` → T7; `start-experiment` + boundary validation → T8; both stat scopes and `get-loop` counts → T9. Server instructions rewrite → T5.
- **Not covered, deliberately** (spec "Out of scope"): screens, action CRUD, `update-loop`, `log-note`, `conclude-experiment`, MCP prompts.
- **Type consistency.** `series_started_at` is an immutable datetime everywhere; `Occurrence::$scheduled_for` likewise; `LogAction::handle`'s fourth parameter is `?Occurrence` in T4, T5 and nowhere else; `forCurrentVersion` is the only new `LoopProgress` method and `forLoop` keeps its existing signature for `ProgressController`.
- **Deployment note.** Four migrations run in order; the backfill one reads `action_logs` written before this branch. Nothing here is destructive and `down()` is defined for each.
