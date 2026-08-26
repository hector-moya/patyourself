# Retire roll-forward — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Occurrence` the single source of truth for what is due, and delete
`actions.scheduled_for` along with the per-occasion action statuses that roll-forward depended on.

**Architecture:** Occurrences already exist as the full grid of occasions, materialised lazily from
`actions.series_started_at`. This branch moves fire-state onto them (`occurrences.fired_at`), moves
the materialisation horizon to the end of the user's local day so "later today" has real rows, and
introduces `TodaysOccasions` as the one definition of what is due — unlogged occurrences inside the
user's local day, plus cue-anchored actions unioned in from the action row. The cursor
(`actions.scheduled_for`) and the statuses `pending` / `active` / `completed` / `skipped` are then
dropped.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, Inertia v3 + React 19, Pint, MySQL 8 in production
and SQLite locally.

**Spec:** `docs/superpowers/specs/2026-08-26-retire-roll-forward-design.md`

## Global Constraints

Every task's requirements implicitly include this section.

- **The notebook never nags.** No overdue counts, no red backlog states, no number rendered over the
  unlogged set. `planned_days: null` means open-ended and must never render as a countdown.
- **Reasons and notes are verbatim.** Never trimmed, squished or sentence-cased, in any path, UI
  included.
- **`skipped` = the occasion never happened.** Excluded from completion-rate denominators, counted
  separately.
- **Failure language is about the strategy, never the user.** Field names, enums, copy, empty states.
- **No gamification.** A streak is a statistic. No badges, levels, celebratory states.
- **Strategy versions are append-only.** Supersede, never overwrite.
- **No quantities on eating loops.** No calories, portions, weights, numeric targets — anywhere.
- **TDD.** Failing test first, then the implementation. Every change programmatically tested.
- **`vendor/bin/pint --dirty --format agent`** before each commit.
- **Run the PHP suite against MySQL 8 too** before finishing anything with a migration. SQLite has
  hidden two real bugs on this project already.
- Herd serves the app at `https://patyourself.test`. Never `php artisan serve`.

## Worktree setup (already done, stated for a fresh session)

`composer install`, a copy of `.env`, `php artisan passport:keys`, `public/build` (or
`npm run build`), and `php artisan wayfinder:generate --with-form`. **The `--with-form` flag is
essential** — `vite.config.ts` sets `formVariants: true` but the Artisan command does not read it,
and without it eight component tests fail on `update.form is not a function`.

## File Structure

**Created**
- `database/migrations/2026_08_26_120000_add_fired_at_to_occurrences_table.php` — the fire guard.
- `database/migrations/2026_08_26_120001_retire_action_scheduled_for.php` — drops the cursor and
  normalises `actions.status`. Runs last, after every writer has stopped writing the column.
- `app/Events/OccurrenceFired.php` — replaces `ActionFired`.
- `app/Services/Scheduling/TodaysOccasions.php` — replaces `TodaysActions`.
- `app/Services/Scheduling/TodaysOccasion.php` — the DTO one entry of that list.
- `tests/Feature/Scheduling/TodaysOccasionsTest.php`

**Deleted**
- `app/Events/ActionFired.php`, `app/Services/Scheduling/TodaysActions.php`,
  `tests/Feature/Scheduling/TodaysActionsTest.php`.

**Modified** — `MaterialiseOccurrences`, `TriggerEngine`, `RescheduleAction`, `UpdateIntention`,
`LogAction`, `CreateAction`, `PersistAuthoredIntention`, `StartExperiment`, `Occurrence`, `Action`,
`Intention`, `SendDueNotification`, `ActionDueNotification`, `DailyDigestNotification`,
`DigestDispatcher`, `FireDueActions`, `TodayActionsTool`, `DescribesActionShape`,
`IntentionResource`, `Api\ActionController`, `ActionFactory`, `OccurrenceFactory`,
`HabitDataSeeder`, `resources/js/patyourself/types.ts`, and their tests.

## Task ordering, and why it is this order

The read side lands **before** the write side. `TodaysOccasions` derives `due_now` / `upcoming`
from `scheduled_for` against now — **not** from fire-state — which is what breaks the otherwise
circular dependency between "the today list needs firing to have happened" and "firing needs the
today list's window". Fire-state is only ever the cue-delivery guard.

Every task leaves the suite green. The column is dropped only in Task 10, after Task 9 has stopped
every writer.

---

### Task 1: `occurrences.fired_at`

Purely additive. Nothing reads it yet.

**Files:**
- Create: `database/migrations/2026_08_26_120000_add_fired_at_to_occurrences_table.php`
- Modify: `app/Models/Occurrence.php`, `database/factories/OccurrenceFactory.php`
- Test: `tests/Feature/Database/OccurrenceSchemaTest.php`

**Interfaces:**
- Produces: `occurrences.fired_at` (nullable timestamp, indexed); `Occurrence::$fired_at` cast to
  `immutable_datetime`; the `unfired` query scope; `OccurrenceFactory::fired()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Database/OccurrenceSchemaTest.php`:

```php
    public function test_an_occurrence_starts_unfired(): void
    {
        $occurrence = Occurrence::factory()->create();

        $this->assertNull($occurrence->fired_at);
    }

    public function test_the_unfired_scope_excludes_fired_occasions(): void
    {
        $unfired = Occurrence::factory()->create();
        Occurrence::factory()->fired()->create();

        $this->assertSame([$unfired->id], Occurrence::query()->unfired()->pluck('id')->all());
    }

    public function test_fired_at_is_an_immutable_datetime(): void
    {
        $occurrence = Occurrence::factory()->fired()->create();

        $this->assertInstanceOf(CarbonImmutable::class, $occurrence->fresh()->fired_at);
    }
```

Add `use Carbon\CarbonImmutable;` to that file's imports.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=OccurrenceSchemaTest`
Expected: FAIL — no `fired_at` column.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fire guard moves from the action row onto the occasion.
 *
 * `actions.status` could only ever hold one live slot, so firing a series meant
 * flipping the same row pending -> active -> pending forever. An occasion fires
 * once, and `fired_at` records when — which is both the idempotency guard and
 * the honest answer to "was the cue delivered for this occasion?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->timestamp('fired_at')->nullable()->after('scheduled_for');
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->dropIndex(['fired_at']);
            $table->dropColumn('fired_at');
        });
    }
};
```

- [ ] **Step 4: Add the cast, the scope and the factory state**

In `app/Models/Occurrence.php`, add `'fired_at'` to the `#[Fillable]` list, extend `casts()`:

```php
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'fired_at' => 'immutable_datetime',
        ];
    }
```

and add the scope beside `unlogged`:

```php
    /**
     * Occasions whose cue has not been delivered. `fired_at` is the trigger
     * engine's idempotency guard: a null here is the only thing that lets an
     * occasion fire, and stamping it is what makes a repeated or overlapping
     * run a no-op.
     *
     * @param  Builder<Occurrence>  $query
     */
    #[Scope]
    protected function unfired(Builder $query): void
    {
        $query->whereNull('fired_at');
    }
```

In `database/factories/OccurrenceFactory.php`:

```php
    /** An occasion whose cue has already been delivered. */
    public function fired(): static
    {
        return $this->state(['fired_at' => now()]);
    }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=OccurrenceSchemaTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): fired_at is the cue-delivery guard"
```

---

### Task 2: The materialisation horizon reaches the end of the local day

**Files:**
- Modify: `app/Services/Scheduling/MaterialiseOccurrences.php`
- Test: `tests/Feature/Scheduling/MaterialiseOccurrencesTest.php`

**Interfaces:**
- Consumes: `Schedule::advance()`, `Recurrence::tryFromToken()` (both unchanged).
- Produces: `MaterialiseOccurrences::forUser(User): int` and `::forLoop(Intention): int` keep their
  signatures. Their horizon becomes the end of the user's local day instead of `now`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Scheduling/MaterialiseOccurrencesTest.php`:

```php
    public function test_it_materialises_the_rest_of_the_local_day(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => 'daily',
        ]);

        $created = app(MaterialiseOccurrences::class)->forUser($user);

        // 20:00 is still ahead of noon, but it is today, so it must have a row —
        // otherwise nothing can render as "upcoming".
        $this->assertSame(1, $created);
        $this->assertDatabaseHas('occurrences', ['scheduled_for' => '2026-08-24 20:00:00']);
    }

    public function test_it_stops_at_the_end_of_the_local_day(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        app(MaterialiseOccurrences::class)->forUser($user);

        $this->assertDatabaseHas('occurrences', ['scheduled_for' => '2026-08-24 09:00:00']);
        $this->assertDatabaseMissing('occurrences', ['scheduled_for' => '2026-08-25 09:00:00']);
    }

    public function test_the_horizon_follows_the_users_timezone_not_utc(): void
    {
        // 23:00 UTC on the 24th is 09:00 on the 25th in Sydney, so the Sydney
        // user's day still has slots to come that a UTC horizon would cut off.
        Carbon::setTestNow('2026-08-24 23:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Sydney']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 23:30:00'),
            'recurrence' => 'daily',
        ]);

        app(MaterialiseOccurrences::class)->forUser($user);

        $this->assertDatabaseHas('occurrences', ['scheduled_for' => '2026-08-24 23:30:00']);
    }

    public function test_a_second_pass_writes_nothing_and_reports_zero(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-20 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $service = app(MaterialiseOccurrences::class);
        $first = $service->forUser($user);

        $this->assertSame(0, $service->forUser($user));
        $this->assertSame($first, Occurrence::query()->count());
    }
```

Make sure the file imports `App\Models\Occurrence`, `App\Models\User`, `Illuminate\Support\Carbon`
and has a `tearDown` calling `Carbon::setTestNow()`, following `TodaysActionsTest`'s idiom.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=MaterialiseOccurrencesTest`
Expected: FAIL — the horizon is still `now`, so the 20:00 slot is not created.

- [ ] **Step 3: Rewrite `materialise()`**

Replace the method in `app/Services/Scheduling/MaterialiseOccurrences.php`:

```php
    /**
     * The grid this action has produced through the end of the user's local
     * day. The horizon is end-of-day rather than `now` because the today list
     * splits into due_now and upcoming, and "upcoming" needs real rows to
     * select — a horizon at `now` makes that half of the list permanently
     * empty.
     *
     * The walk always restarts from the anchor rather than resuming from the
     * last materialised slot: `RescheduleAction` re-anchors, and the old grid
     * and the new one do not share a phase, so resuming would continue the
     * abandoned cadence.
     *
     * Only slots that do not already exist are written. This runs every minute
     * from `actions:fire`, and re-upserting up to MAX_SLOTS_PER_ACTION rows per
     * action per minute is pure waste; in the steady state the diff is empty
     * and the method returns before touching the database at all.
     */
    private function materialise(Action $action, string $timezone): int
    {
        $horizon = CarbonImmutable::now($timezone)->endOfDay()->utc();
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $slots = [];
        $slot = $action->series_started_at->toImmutable();

        while ($slot->lessThanOrEqualTo($horizon) && count($slots) < self::MAX_SLOTS_PER_ACTION) {
            $slots[] = $slot->utc()->toDateTimeString();

            $next = $this->schedule->advance($slot, $recurrence, $timezone);

            // A one-off has no next slot: it produces exactly its anchor.
            if ($next === null) {
                break;
            }

            $slot = $next;
        }

        if ($slots === []) {
            return 0;
        }

        $existing = $action->occurrences()
            ->whereIn('scheduled_for', $slots)
            ->pluck('scheduled_for')
            ->map(fn (CarbonImmutable $stamp): string => $stamp->utc()->toDateTimeString())
            ->all();

        $missing = array_values(array_diff($slots, $existing));

        if ($missing === []) {
            return 0;
        }

        $before = $action->occurrences()->count();

        // Still an upsert, not an insert: the diff above narrows the write, but
        // two overlapping runs can both see the same slot missing. The unique
        // (action_id, scheduled_for) index and "update nothing on conflict" are
        // what make that a no-op rather than a duplicate or an error.
        Occurrence::query()->upsert(
            array_map(fn (string $stamp): array => [
                'action_id' => $action->id,
                'scheduled_for' => $stamp,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ], $missing),
            ['action_id', 'scheduled_for'],
            [],
        );

        return $action->occurrences()->count() - $before;
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=MaterialiseOccurrencesTest`
Run: `php artisan test --compact --filter=CatchUpScreenTest`
Run: `php artisan test --compact --filter=PendingOutcomesToolTest`
Expected: PASS all three. The last two read occurrences and must not have regressed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): materialise through the end of the local day, incrementally"
```

---

### Task 3: Re-anchoring purges unlogged future occasions

With a future horizon, an abandoned cadence leaves phantom slots. Logged occasions are never
touched — the record is not rewritten.

**Files:**
- Modify: `app/Actions/RescheduleAction.php`, `app/Actions/UpdateIntention.php`
- Test: `tests/Feature/Actions/SeriesAnchorTest.php`, `tests/Feature/UpdateIntentionTest.php`

**Interfaces:**
- Produces: `RescheduleAction::handle()` keeps its signature and additionally deletes that action's
  unlogged occurrences with `scheduled_for > now()` before re-anchoring.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Actions/SeriesAnchorTest.php`:

```php
    public function test_rescheduling_purges_unlogged_future_occasions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $future = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'clock', '07:00', 'daily', null, 'UTC');

        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_rescheduling_leaves_past_occasions_alone(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-20 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $past = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-22 09:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'clock', '07:00', 'daily', null, 'UTC');

        $this->assertDatabaseHas('occurrences', ['id' => $past->id]);
    }

    public function test_rescheduling_never_deletes_a_logged_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $logged = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);
        ActionLog::factory()->for($action)->for($logged)->create();

        app(RescheduleAction::class)->handle($action, 'clock', '07:00', 'daily', null, 'UTC');

        // The record is append-only. A future slot that already carries an
        // outcome is evidence, not a phantom.
        $this->assertDatabaseHas('occurrences', ['id' => $logged->id]);
    }
```

Import `App\Models\ActionLog`, `App\Models\Occurrence`, `App\Actions\RescheduleAction` and
`Illuminate\Support\Carbon` as needed, and add a `tearDown` calling `Carbon::setTestNow()`.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Expected: FAIL — the future occurrence still exists.

- [ ] **Step 3: Purge in `RescheduleAction`**

In `app/Actions/RescheduleAction.php`, before `$action->update([...])`:

```php
        // The anchor moves, so the grid ahead of it is the abandoned cadence.
        // Left in place it would render as due on a schedule the user has just
        // replaced. Only unlogged future slots go: anything already logged is
        // evidence and the record is append-only.
        $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>', CarbonImmutable::now())
            ->delete();
```

- [ ] **Step 4: Do the same where `UpdateIntention` re-anchors**

`UpdateIntention::reanchorPendingActions()` rolls `scheduled_for` for pending actions when a paused
loop is reactivated. Rename it `reanchorStaleActions` (it no longer has "pending" to select on),
update the call site at line 39, and replace the body:

```php
    /**
     * A loop can sit paused for days before the user activates it, leaving any
     * clock action anchored in the past — it would materialise a run of
     * occasions the user never had the chance to act on the moment the loop
     * went live. Push each one to its next real occurrence. Only genuinely
     * stale actions are touched; a future-dated one is left as the user
     * scheduled it. Anchored actions carry no clock time and are left alone.
     */
    private function reanchorStaleActions(Intention $intention): void
    {
        $timezone = $intention->user->timezone ?? (string) config('app.timezone');
        $schedule = new Schedule;
        $now = CarbonImmutable::now();

        $intention->actions()
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->whereNotNull('series_started_at')
            ->where('series_started_at', '<=', $now)
            ->get()
            ->each(function (Action $action) use ($schedule, $now, $timezone): void {
                $seriesStartedAt = $action->series_started_at->toImmutable();
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot, so
                // it preserves the weekday (and stays DST-correct) instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() then handles.
                $next = $schedule->nextAfter($seriesStartedAt, $now, $recurrence, $timezone)
                    ?? $schedule->firstOccurrence(
                        $now,
                        $seriesStartedAt->setTimezone($timezone)->format('H:i'),
                        $recurrence,
                        $timezone,
                    );

                if ($next === null) {
                    return;
                }

                // Same reasoning as RescheduleAction: the cadence restarts here,
                // so anything unlogged ahead of now belongs to the cadence being
                // left behind.
                $action->occurrences()
                    ->unlogged()
                    ->where('scheduled_for', '>', $now)
                    ->delete();

                $action->update(['series_started_at' => $next]);
            });
    }
```

This already drops `UpdateIntention`'s `scheduled_for` write, so Task 9 has nothing left to do
there. `tests/Feature/UpdateIntentionTest.php` currently asserts on `scheduled_for` after
reactivation — repoint those assertions to `series_started_at` in this task.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Run: `php artisan test --compact --filter=UpdateIntentionTest`
Run: `php artisan test --compact --filter=RescheduleActionWebTest`
Run: `php artisan test --compact --filter=ActionCrudToolsTest`
Expected: PASS all four.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(occurrences): re-anchoring purges the abandoned cadence"
```

---

### Task 4: `TodaysOccasions` — the one definition of what is due

New service alongside `TodaysActions`. Nothing consumes it yet; Task 5 switches the callers.

**Files:**
- Create: `app/Services/Scheduling/TodaysOccasion.php`, `app/Services/Scheduling/TodaysOccasions.php`
- Test: `tests/Feature/Scheduling/TodaysOccasionsTest.php`

**Interfaces:**
- Consumes: `MaterialiseOccurrences::forUser(User): int`, `Occurrence::unlogged()`.
- Produces: `TodaysOccasions::for(User $user): Collection<int, TodaysOccasion>`, ordered by
  `scheduled_for` with anchored entries last. `TodaysOccasion` is a readonly DTO with public
  properties `action: Action`, `occurrence: ?Occurrence`, `scheduledFor: ?CarbonImmutable`,
  `due: string` — one of `'due_now'`, `'upcoming'`, `'anchored'`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Scheduling/TodaysOccasionsTest.php`:

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodaysOccasionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function activeLoopFor(User $user): Intention
    {
        return Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
    }

    public function test_a_slot_whose_time_has_passed_is_due_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertSame('due_now', $occasions->first()->due);
    }

    public function test_a_slot_later_today_is_upcoming(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => null,
        ]);

        $this->assertSame('upcoming', app(TodaysOccasions::class)->for($user)->first()->due);
    }

    public function test_yesterdays_unlogged_slot_is_not_due_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-23 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        // Yesterday's slot exists and stays loggable forever — but it belongs to
        // /catch-up, not to today. A missed occasion must never accumulate into
        // a backlog the notebook shows back to the user.
        $this->assertCount(1, $occasions);
        $this->assertSame(
            '2026-08-24 09:00:00',
            $occasions->first()->scheduledFor->utc()->toDateTimeString(),
        );
        $this->assertDatabaseHas('occurrences', ['scheduled_for' => '2026-08-23 09:00:00']);
    }

    public function test_a_logged_slot_is_not_due(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        app(TodaysOccasions::class)->for($user);
        $occurrence = Occurrence::query()->where('action_id', $action->id)->sole();
        ActionLog::factory()->for($action)->for($occurrence)->create();

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_a_cue_anchored_action_unions_in(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchored = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertSame('anchored', $occasions->first()->due);
        $this->assertNull($occasions->first()->occurrence);
        $this->assertNull($occasions->first()->scheduledFor);
        $this->assertTrue($occasions->first()->action->is($anchored));
    }

    public function test_the_local_day_window_follows_the_users_timezone(): void
    {
        // 23:00 UTC on the 24th is already 09:00 on the 25th in Sydney. A slot
        // at 23:30 UTC is therefore later *today* for that user, not tomorrow.
        Carbon::setTestNow('2026-08-24 23:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Sydney']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 23:30:00'),
            'recurrence' => null,
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertSame('upcoming', $occasions->first()->due);
    }

    public function test_it_excludes_paused_loops(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $paused = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);
        Action::factory()->for($paused)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
        ]);
        Action::factory()->for($paused)->create(['series_started_at' => null]);

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_it_excludes_archived_actions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'status' => Action::STATUS_ARCHIVED,
        ]);

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_it_never_returns_another_users_occasions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $stranger = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($stranger))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $this->assertCount(0, app(TodaysOccasions::class)->for(User::factory()->create()));
    }

    public function test_entries_are_ordered_by_time_with_anchored_last(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->activeLoopFor($user);

        Action::factory()->for($loop)->create(['series_started_at' => null, 'recurrence' => null]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => null,
        ]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        $this->assertSame(
            ['due_now', 'upcoming', 'anchored'],
            app(TodaysOccasions::class)->for($user)->map(
                fn (TodaysOccasion $occasion): string => $occasion->due,
            )->all(),
        );
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=TodaysOccasionsTest`
Expected: FAIL — `TodaysOccasions` not found.

- [ ] **Step 3: Write the DTO**

Create `app/Services/Scheduling/TodaysOccasion.php`:

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Occurrence;
use Carbon\CarbonImmutable;

/**
 * One entry in today's list. A scheduled entry carries the occasion it is
 * about; a cue-anchored one has none, because an action with no schedule has
 * produced no occasion yet — logging it is what creates one.
 */
final readonly class TodaysOccasion
{
    public const DUE_NOW = 'due_now';

    public const UPCOMING = 'upcoming';

    public const ANCHORED = 'anchored';

    public function __construct(
        public Action $action,
        public ?Occurrence $occurrence,
        public ?CarbonImmutable $scheduledFor,
        public string $due,
    ) {}
}
```

- [ ] **Step 4: Write the service**

Create `app/Services/Scheduling/TodaysOccasions.php`:

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The one definition of "what is due today" for a user: unlogged occasions
 * inside the user's local day, plus cue-anchored actions, which have no
 * schedule and so no occasion to be inside it.
 *
 * The window is the whole point. Occasions never expire — a missed one stays
 * loggable forever — so selecting every unlogged past occasion would build a
 * backlog and turn the digest into a nag. Yesterday's misses are reachable
 * only from /catch-up, which the user goes looking for.
 *
 * `due` is derived from the clock, not from whether a cue was delivered:
 * `fired_at` is the trigger engine's idempotency guard and nothing else.
 *
 * Shared so the daily digest, the today-actions tool and the action cards can
 * never disagree about what the user owes today.
 */
class TodaysOccasions
{
    public function __construct(private readonly MaterialiseOccurrences $materialise) {}

    /**
     * @return Collection<int, TodaysOccasion>
     */
    public function for(User $user): Collection
    {
        // Lazy as ever: today's grid is built on the read that needs it. This
        // is not a write side-effect of logging — nothing here can conjure an
        // occasion the check-in then asks about that the schedule did not
        // already imply.
        $this->materialise->forUser($user);

        $timezone = $user->timezone ?? (string) config('app.timezone');
        $now = Date::now();
        $localNow = Date::now($timezone);

        $scheduled = Occurrence::query()
            ->unlogged()
            ->whereBetween('scheduled_for', [
                $localNow->copy()->startOfDay()->utc(),
                $localNow->copy()->endOfDay()->utc(),
            ])
            ->whereHas('action', fn (Builder $query) => $query
                ->where('status', '!=', Action::STATUS_ARCHIVED)
                ->whereHas('intention', fn (Builder $loop) => $loop
                    ->where('user_id', $user->id)
                    ->where('status', Intention::STATUS_ACTIVE)))
            ->with('action.intention:id,title')
            ->orderBy('scheduled_for')
            ->get()
            ->map(fn (Occurrence $occurrence): TodaysOccasion => new TodaysOccasion(
                action: $occurrence->action,
                occurrence: $occurrence,
                scheduledFor: $occurrence->scheduled_for,
                due: $occurrence->scheduled_for->lessThanOrEqualTo($now)
                    ? TodaysOccasion::DUE_NOW
                    : TodaysOccasion::UPCOMING,
            ));

        $anchored = Action::query()
            ->whereNull('series_started_at')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->whereHas('intention', fn (Builder $loop) => $loop
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->with('intention:id,title')
            ->orderBy('id')
            ->get()
            ->map(fn (Action $action): TodaysOccasion => new TodaysOccasion(
                action: $action,
                occurrence: null,
                scheduledFor: null,
                due: TodaysOccasion::ANCHORED,
            ));

        return $scheduled->concat($anchored)->values();
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=TodaysOccasionsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(scheduling): TodaysOccasions defines what is due from occurrences"
```

---

### Task 5: The digest and `today-actions` read occasions

Deletes `TodaysActions`.

**Files:**
- Modify: `app/Services/Reminders/DigestDispatcher.php`,
  `app/Notifications/DailyDigestNotification.php`, `app/Mcp/Tools/TodayActionsTool.php`
- Delete: `app/Services/Scheduling/TodaysActions.php`,
  `tests/Feature/Scheduling/TodaysActionsTest.php`
- Test: `tests/Feature/Reminders/DigestDispatcherTest.php`,
  `tests/Feature/Mcp/TodayActionsToolTest.php`

**Interfaces:**
- Consumes: `TodaysOccasions::for(User): Collection<int, TodaysOccasion>` from Task 4.
- Produces: `DailyDigestNotification::__construct(Collection $occasions)` — now a collection of
  `TodaysOccasion`, not of `Action`. `today-actions` gains `'anchored'` as a third value of `due`
  and replaces `scheduled_for` with the occasion's time.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Mcp/TodayActionsToolTest.php`, replace the status-driven fixtures. Every action
that used `'status' => Action::STATUS_ACTIVE` to mean "due now" becomes an action with a
`series_started_at` in the past, and every anchored one sets `'series_started_at' => null`. Add:

```php
    public function test_it_labels_a_cue_anchored_action_as_anchored(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();
        $payload = $this->payload($response);
        $this->assertSame('anchored', $payload[0]['due']);
        $this->assertNull($payload[0]['scheduled_for']);
    }

    public function test_it_does_not_list_yesterdays_missed_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-23 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();
        $payload = $this->payload($response);
        $this->assertCount(1, $payload);
        $this->assertStringStartsWith('2026-08-24', $payload[0]['scheduled_for']);
    }
```

`payload()` is the file's existing private helper (it reflects into `TestResponse::content()` and
JSON-decodes the first entry). `PatYourSelfServer::actingAs($user)->tool(...)` is how every test in
`tests/Feature/Mcp/` invokes a tool. Both already exist — do not reinvent them. Add
`use Illuminate\Support\Carbon;` and a `tearDown` calling `Carbon::setTestNow()`, which this file
does not yet have.

In `tests/Feature/Reminders/DigestDispatcherTest.php`, update fixtures the same way and add:

```php
    public function test_the_digest_lists_a_cue_anchored_action_without_a_time(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ]);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
            'title' => 'Put the snacks out of sight tonight',
        ]);

        Notification::fake();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=TodayActionsToolTest`
Run: `php artisan test --compact --filter=DigestDispatcherTest`
Expected: FAIL — `due` is still derived from `Action::STATUS_ACTIVE`, and anchored actions do not
appear.

- [ ] **Step 3: Switch `DigestDispatcher`**

In `app/Services/Reminders/DigestDispatcher.php` swap the dependency and the variable:

```php
use App\Services\Scheduling\TodaysOccasions;

    public function __construct(private readonly TodaysOccasions $todaysOccasions) {}
```

and inside the closure:

```php
                $occasions = $this->todaysOccasions->for($user);

                if ($occasions->isEmpty()) {
                    return;
                }

                $user->notify(new DailyDigestNotification($occasions));
```

- [ ] **Step 4: Switch `DailyDigestNotification`**

```php
use App\Services\Scheduling\TodaysOccasion;

    /**
     * @param  Collection<int, TodaysOccasion>  $occasions
     */
    public function __construct(private readonly Collection $occasions) {}
```

and in `toMail()`:

```php
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $count = $this->occasions->count();

        $mail = (new MailMessage)
            ->subject($count === 1 ? '1 thing today' : "{$count} things today")
            ->line('Here is what you are working on today.');

        foreach ($this->occasions as $occasion) {
            $when = $occasion->scheduledFor
                ? $occasion->scheduledFor->timezone($timezone)->format('g:ia')
                : 'when the cue happens';

            $mail->line("• {$occasion->action->title} — {$occasion->action->intention->title} ({$when})");
        }
```

Drop the now-unused `use App\Models\Action;`.

- [ ] **Step 5: Switch `TodayActionsTool`**

```php
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;

#[Name('today-actions')]
#[Description(<<<'TEXT'
What the user is working on today: occasions whose time has passed ("due_now"),
occasions later today ("upcoming"), and cue-anchored actions that have no clock
time at all ("anchored"). Only actions on active loops.

This is today's list, not a backlog. An occasion missed on an earlier day is
never listed here — it stays loggable forever and is reachable through
pending-outcomes, which is where a catch-up belongs.
TEXT)]
class TodayActionsTool extends Tool
{
    public function handle(Request $request, TodaysOccasions $todaysOccasions): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');

        $occasions = $todaysOccasions->for($user);

        return Response::json($occasions->map(fn (TodaysOccasion $occasion): array => [
            'id' => $occasion->action->id,
            'occurrence_id' => $occasion->occurrence?->id,
            'loop_id' => $occasion->action->intention_id,
            'loop_title' => $occasion->action->intention->title,
            'title' => $occasion->action->title,
            'description' => $occasion->action->description,
            'due' => $occasion->due,
            'scheduled_for' => $occasion->scheduledFor?->timezone($timezone)->toIso8601String(),
            'recurrence' => $occasion->action->recurrence,
        ])->values()->all());
    }
```

`status` leaves the payload: it described a state that is being retired, and `due` is the honest
replacement. `occurrence_id` is added so a caller can log the exact occasion with `log-outcome`
rather than guessing at the live slot.

- [ ] **Step 6: Delete `TodaysActions` and its test**

```bash
git rm app/Services/Scheduling/TodaysActions.php tests/Feature/Scheduling/TodaysActionsTest.php
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact --filter=TodayActionsToolTest`
Run: `php artisan test --compact --filter=DigestDispatcherTest`
Run: `php artisan test --compact --filter=McpEndpointTest`
Expected: PASS all three.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(scheduling): the digest and today-actions read occasions"
```

---

### Task 6: The trigger engine fires occasions

**Files:**
- Create: `app/Events/OccurrenceFired.php`
- Delete: `app/Events/ActionFired.php`
- Modify: `app/Services/Scheduling/TriggerEngine.php`, `app/Listeners/SendDueNotification.php`,
  `app/Notifications/ActionDueNotification.php`, `app/Console/Commands/FireDueActions.php`
- Test: `tests/Feature/Scheduling/TriggerEngineTest.php` (rewrite),
  `tests/Feature/Notifications/SendDueNotificationTest.php`,
  `tests/Feature/Notifications/ActionDueNotificationTest.php`,
  `tests/Feature/Console/FireDueActionsCommandTest.php`

**Interfaces:**
- Consumes: `Occurrence::unlogged()` and `::unfired()` from Task 1.
- Produces: `TriggerEngine::fireDueOccurrences(): int`; `OccurrenceFired` with a public
  `Occurrence $occurrence`; `ActionDueNotification::__construct(Occurrence $occurrence)` and a
  payload of `{occurrence_id, action_id, intention_id, title, fired_at}`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/Scheduling/TriggerEngineTest.php` wholesale:

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Events\OccurrenceFired;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TriggerEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An occasion due three hours ago today, on an active loop owned by a UTC
     * user, unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function dueOccurrence(
        array $overrides = [],
        string $intentionStatus = Intention::STATUS_ACTIVE,
        string $actionStatus = Action::STATUS_ACTIVE,
    ): Occurrence {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => $intentionStatus]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => $actionStatus,
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        return Occurrence::factory()->for($action)->create(array_merge([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ], $overrides));
    }

    public function test_it_fires_a_due_unfired_occasion(): void
    {
        $occurrence = $this->dueOccurrence();

        $this->assertSame(1, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNotNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_fire_a_slot_later_today(): void
    {
        $occurrence = $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_fire_an_occasion_from_an_earlier_day(): void
    {
        $occurrence = $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-21 09:00:00')]);

        // The cue for a three-day-old occasion is not worth delivering now. An
        // outage must not produce a burst of stale cues on recovery; the
        // occasion stays loggable on /catch-up, silently.
        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_refire_an_already_fired_occasion(): void
    {
        $this->dueOccurrence(['fired_at' => Carbon::parse('2026-08-24 09:01:00')]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_an_occasion_that_already_carries_an_outcome(): void
    {
        $occurrence = $this->dueOccurrence();
        ActionLog::factory()->for($occurrence->action)->for($occurrence)->create();

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_when_the_loop_is_not_active(): void
    {
        $this->dueOccurrence([], Intention::STATUS_PAUSED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_an_archived_action(): void
    {
        $this->dueOccurrence([], Intention::STATUS_ACTIVE, Action::STATUS_ARCHIVED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_is_idempotent_across_runs(): void
    {
        $this->dueOccurrence();
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueOccurrences());
        $this->assertSame(0, $engine->fireDueOccurrences());
    }

    public function test_the_window_follows_the_users_timezone(): void
    {
        Carbon::setTestNow('2026-08-24 23:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Sydney']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($intention)->create([
            'series_started_at' => Carbon::parse('2026-08-24 22:00:00'),
            'recurrence' => 'daily',
        ]);
        // 22:00 UTC is 08:00 on the 25th in Sydney — inside that user's today,
        // even though it is "yesterday" in UTC.
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 22:00:00'),
        ]);

        $this->assertSame(1, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNotNull($occurrence->fresh()->fired_at);
    }

    public function test_firing_dispatches_occurrence_fired_once(): void
    {
        Event::fake([OccurrenceFired::class]);
        $occurrence = $this->dueOccurrence();

        app(TriggerEngine::class)->fireDueOccurrences();

        Event::assertDispatchedTimes(OccurrenceFired::class, 1);
        Event::assertDispatched(
            OccurrenceFired::class,
            fn (OccurrenceFired $event): bool => $event->occurrence->is($occurrence),
        );
    }

    public function test_no_fire_dispatches_no_event(): void
    {
        Event::fake([OccurrenceFired::class]);
        $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        app(TriggerEngine::class)->fireDueOccurrences();

        Event::assertNotDispatched(OccurrenceFired::class);
    }

    public function test_it_returns_the_count_fired(): void
    {
        $this->dueOccurrence();
        $this->dueOccurrence();
        $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertSame(2, app(TriggerEngine::class)->fireDueOccurrences());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=TriggerEngineTest`
Expected: FAIL — `OccurrenceFired` not found, `fireDueOccurrences()` undefined.

- [ ] **Step 3: Write the event**

Create `app/Events/OccurrenceFired.php`, modelled on the deleted `ActionFired`:

```php
<?php

namespace App\Events;

use App\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An occasion's moment has arrived and the trigger engine has claimed it. The
 * event fires exactly once per occasion — the claim is a guarded update on
 * `fired_at`, so a repeated or overlapping run raises nothing.
 *
 * The action is reachable through the occasion. It is deliberately not the
 * subject: a standing prescription does not fire, its occasions do.
 */
class OccurrenceFired
{
    use Dispatchable;

    public function __construct(public readonly Occurrence $occurrence) {}
}
```

Import only `Illuminate\Foundation\Events\Dispatchable` — this mirrors `ActionFired`, which uses
that one trait and no `SerializesModels`. Then `git rm app/Events/ActionFired.php`.

- [ ] **Step 4: Rewrite `TriggerEngine`**

```php
<?php

namespace App\Services\Scheduling;

use App\Events\OccurrenceFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * The trigger engine: delivers the cue for each occasion whose moment has
 * arrived. Firing is idempotent — each occasion is claimed with a guarded
 * conditional update on `fired_at`, so an overlapping or repeated run fires
 * every occasion at most once. The actions:fire command runs this every minute.
 *
 * Bounded to the user's local day on purpose. Occasions never expire, so
 * without the window an outage would come back and deliver every missed cue at
 * once. A missed occasion is not a cue worth ringing later; it stays loggable,
 * quietly, on /catch-up.
 *
 * The window is per user, so this iterates users rather than running one global
 * query — midnight is not the same instant for two people.
 */
final class TriggerEngine
{
    /**
     * Fire every due, unfired, unlogged occasion inside each user's local day.
     * Returns the number actually fired (won by this run's guarded update).
     */
    public function fireDueOccurrences(): int
    {
        $fired = 0;

        User::query()
            ->whereHas('intentions', fn (Builder $query) => $query->where('status', Intention::STATUS_ACTIVE))
            ->cursor()
            ->each(function (User $user) use (&$fired): void {
                $localNow = Date::now($user->timezone ?? (string) config('app.timezone'));

                $due = Occurrence::query()
                    ->unlogged()
                    ->unfired()
                    ->where('scheduled_for', '<=', Date::now())
                    ->whereBetween('scheduled_for', [
                        $localNow->copy()->startOfDay()->utc(),
                        $localNow->copy()->endOfDay()->utc(),
                    ])
                    ->whereHas('action', fn (Builder $query) => $query
                        ->where('status', '!=', Action::STATUS_ARCHIVED)
                        ->whereHas('intention', fn (Builder $loop) => $loop
                            ->where('user_id', $user->id)
                            ->where('status', Intention::STATUS_ACTIVE)))
                    ->with('action.intention.user')
                    ->get();

                foreach ($due as $occurrence) {
                    if ($this->fire($occurrence)) {
                        $fired++;
                    }
                }
            });

        return $fired;
    }

    /**
     * Atomically claim one occasion. Returns true only for the run whose
     * guarded update actually changed the row (the fire owner); a concurrent or
     * repeated run sees 0 affected rows and returns false.
     */
    private function fire(Occurrence $occurrence): bool
    {
        $affected = Occurrence::query()
            ->whereKey($occurrence->getKey())
            ->whereNull('fired_at')
            ->update(['fired_at' => Date::now()]);

        if ($affected === 1) {
            $occurrence->refresh();
            OccurrenceFired::dispatch($occurrence);

            return true;
        }

        return false;
    }
}
```

- [ ] **Step 5: Repoint the listener and the notification**

`app/Listeners/SendDueNotification.php`:

```php
use App\Events\OccurrenceFired;

    public function handle(OccurrenceFired $event): void
    {
        $event->occurrence->action->intention->user->notify(
            new ActionDueNotification($event->occurrence),
        );
    }
```

`app/Notifications/ActionDueNotification.php` — take the occasion, keep everything else:

```php
use App\Models\Occurrence;

    public function __construct(private readonly Occurrence $occurrence)
    {
        $this->occurrence->loadMissing('action.intention');
    }
```

`toMail()` reads `$action = $this->occurrence->action;` then `$loop = $action->intention;` and is
otherwise unchanged. `toArray()` becomes:

```php
    /**
     * @return array{occurrence_id: int, action_id: int, intention_id: int, title: string, fired_at: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            // The occasion is what the cue is about, and what answering it
            // marks read. action_id stays so an inbox entry can still be
            // grouped by the prescription it came from.
            'occurrence_id' => $this->occurrence->id,
            'action_id' => $this->occurrence->action_id,
            'intention_id' => $this->occurrence->action->intention_id,
            'title' => $this->occurrence->action->intention->title,
            'fired_at' => $this->occurrence->fired_at?->toIso8601String(),
        ];
    }
```

No registration change is needed. `SendDueNotification` is not wired in any provider or in
`bootstrap/app.php` — Laravel discovers it from the `handle()` parameter's type-hint, so changing
that type-hint is the whole rewiring. `test_firing_dispatches_occurrence_fired_once` and
`SendDueNotificationTest` together prove it still fires.

- [ ] **Step 6: The command materialises, then fires**

`app/Console/Commands/FireDueActions.php`:

```php
use App\Services\Scheduling\MaterialiseOccurrences;
use App\Services\Scheduling\TriggerEngine;

/**
 * Builds today's grid, then delivers the cue for every occasion whose moment
 * has arrived. The scheduler runs this every minute (see routes/console.php).
 *
 * Materialising here is what lets the engine read rather than compute: a cron's
 * read is still a read, and the "never as a side effect of a write" invariant
 * is about logging an outcome, which must never conjure occasions the check-in
 * then asks about.
 */
class FireDueActions extends Command
{
    protected $signature = 'actions:fire';

    protected $description = 'Deliver the cue for every occasion whose moment has arrived';

    public function handle(MaterialiseOccurrences $materialise, TriggerEngine $engine): int
    {
        User::query()
            ->whereHas('intentions', fn (Builder $query) => $query->where('status', Intention::STATUS_ACTIVE))
            ->cursor()
            ->each(fn (User $user) => $materialise->forUser($user));

        $fired = $engine->fireDueOccurrences();

        $this->components->info("Fired {$fired} cue(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact --filter=TriggerEngineTest`
Run: `php artisan test --compact --filter=SendDueNotificationTest`
Run: `php artisan test --compact --filter=ActionDueNotificationTest`
Run: `php artisan test --compact --filter=FireDueActionsCommandTest`
Run: `php artisan test --compact --filter=InboxControllerTest`
Run: `php artisan test --compact --filter=UnreadCountSharedPropTest`
Expected: PASS all six. Update the three notification/inbox tests for the new payload key and the
new constructor as you go.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(scheduling): the trigger engine fires occasions, not actions"
```

---

### Task 7: `LogAction` stops rolling anything forward

**Files:**
- Modify: `app/Actions/LogAction.php`
- Test: `tests/Feature/Actions/LogActionTest.php`

**Interfaces:**
- Produces: `LogAction::handle(User, Action, array, ?Occurrence = null): ActionLog` — signature
  unchanged. It no longer writes to the `actions` table at all.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Actions/LogActionTest.php`:

```php
    public function test_logging_never_writes_to_the_action_row(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
        ]);
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $before = $action->fresh()->toArray();

        app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
            $occurrence,
        );

        // The action is the standing prescription. Completing one occasion of it
        // says nothing about the prescription itself.
        $this->assertSame($before, $action->fresh()->toArray());
    }

    public function test_completing_a_recurring_occasion_leaves_tomorrows_slot_alone(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
            $occurrence,
        );

        $this->assertSame(
            Carbon::parse('2026-08-24 09:00:00')->toDateTimeString(),
            $action->fresh()->series_started_at->toDateTimeString(),
        );
    }

    public function test_the_live_slot_is_todays_unlogged_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        $yesterday = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-23 09:00:00'),
        ]);
        $today = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $log = app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
        );

        // A card logs today, never a missed day. Catching up an older occasion
        // is what /catch-up and log-outcome are for, and both name the occasion.
        $this->assertSame($today->id, $log->occurrence_id);
        $this->assertNull($yesterday->fresh()->log);
    }

    public function test_an_anchored_action_stamps_its_occasion_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
        ]);

        $log = app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
        );

        $this->assertSame(
            '2026-08-24 12:00:00',
            $log->occurrence->scheduled_for->utc()->toDateTimeString(),
        );
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: FAIL — the action row is still updated, and `liveSlotFor` still reads `scheduled_for`.

- [ ] **Step 3: Delete roll-forward**

In `app/Actions/LogAction.php`, delete `closeOrRearm()`, `isLiveSlot()` and `actionStatusFor()`
entirely, along with the now-unused `Schedule`/`Recurrence`/`CarbonImmutable` imports that only
they used. The constructor becomes parameterless and is therefore removed outright (the class has
no other dependency).

Simplify `handle()`'s body:

```php
            $log = $action->logs()->create([...unchanged...]);

            $this->markCueAnswered($user, $action, $occurrence);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
```

Rewrite the class docblock's third and fourth paragraphs — the ones describing the next-due pointer
and roll-forward — as:

```
 * An outcome attaches to an {@see Occurrence}, not to the action, which is what
 * dates it by the occasion it describes rather than by the moment it was typed.
 * The action row is the standing prescription and this flow never writes to it:
 * completing one occasion says nothing about the prescription.
```

- [ ] **Step 4: Rewrite `liveSlotFor()`**

```php
    /**
     * The occasion a caller means when it names none: today's, which is what a
     * card on screen is about. Latest first, so a day with two slots resolves
     * the later one — the one whose moment has most recently passed.
     *
     * A cue-anchored action has no grid, and a day whose slots are all logged
     * has none left, so both fall through to a slot stamped now. That is how a
     * second log on an already-answered day is recorded as its own occasion
     * rather than colliding with the first.
     */
    private function liveSlotFor(Action $action): Occurrence
    {
        $now = Date::now();
        $timezone = $action->intention?->user?->timezone ?? (string) config('app.timezone');
        $localNow = Date::now($timezone);

        $slot = $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '<=', $now)
            ->where('scheduled_for', '>=', $localNow->copy()->startOfDay()->utc())
            ->orderByDesc('scheduled_for')
            ->first();

        return $slot ?? $this->freeSlotAt($action, $now);
    }
```

- [ ] **Step 5: Mark the cue answered by occasion**

```php
    /**
     * Logging any outcome answers the cue for that occasion, so mark its
     * unread notification(s) read. Matches on occurrence_id, falling back to
     * action_id so a cue delivered before occasions carried their own id still
     * clears when answered. Filtered in memory (unread sets are tiny) to stay
     * portable across database drivers.
     */
    private function markCueAnswered(User $user, Action $action, Occurrence $occurrence): void
    {
        $user->unreadNotifications()->get()
            ->filter(function (DatabaseNotification $notification) use ($action, $occurrence): bool {
                $occurrenceId = $notification->data['occurrence_id'] ?? null;

                return $occurrenceId === null
                    ? ($notification->data['action_id'] ?? null) === $action->id
                    : $occurrenceId === $occurrence->id;
            })
            ->each->markAsRead();
    }
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=LogActionTest`
Run: `php artisan test --compact --filter=ActionLogWebTest`
Run: `php artisan test --compact --filter=ActionLogTest`
Run: `php artisan test --compact --filter=LogOutcomeToolTest`
Run: `php artisan test --compact --filter=CatchUpScreenTest`
Expected: PASS all five.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor(logging): LogAction no longer rolls anything forward"
```

---

### Task 8: Resource shapes and the loop card

**Files:**
- Modify: `app/Models/Intention.php`, `app/Http/Resources/IntentionResource.php`,
  `app/Concerns/DescribesActionShape.php`, `app/Http/Controllers/Api/ActionController.php`,
  `resources/js/patyourself/types.ts`
- Test: `tests/Feature/Actions/ActiveActionResourceTest.php`,
  `tests/Feature/Api/IntentionCrudTest.php`, `tests/Feature/Mcp/ActionCrudToolsTest.php`,
  `resources/js/pages/loops/show.test.tsx`

**Interfaces:**
- Produces: `Action::nextOccurrenceAt(): ?CarbonImmutable` — the earliest unlogged occurrence at or
  after now, or null. Every surface that used to expose `scheduled_for` now exposes
  `next_occurrence_at` from this one method, so the three cannot drift.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Actions/ActiveActionResourceTest.php`:

```php
    public function test_next_occurrence_at_is_the_earliest_unlogged_slot_from_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 09:00:00')]);
        $next = Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertTrue($next->scheduled_for->equalTo($action->nextOccurrenceAt()));
    }

    public function test_next_occurrence_at_is_null_when_nothing_is_left_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 09:00:00')]);

        // The grid stops at the end of today, so "nothing left" is the honest
        // answer — reaching into tomorrow would invent a row that does not exist.
        $this->assertNull($action->nextOccurrenceAt());
    }

    public function test_the_resource_exposes_next_occurrence_at(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => 'daily',
        ]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $payload = (new IntentionResource($loop->load('activeAction')))->toArray(request());

        $this->assertArrayNotHasKey('scheduled_for', $payload['active_action']);
        $this->assertNotNull($payload['active_action']['next_occurrence_at']);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact --filter=ActiveActionResourceTest`
Expected: FAIL — `nextOccurrenceAt()` undefined.

- [ ] **Step 3: Add the method to `Action`**

```php
    /**
     * The next occasion still awaiting an outcome, at or after now. Null when
     * there is none — including for a cue-anchored action, which has no grid,
     * and for a day whose slots are all behind us: the grid is materialised
     * only through the end of the local day, so there is genuinely nothing
     * further to report.
     */
    public function nextOccurrenceAt(): ?CarbonImmutable
    {
        return $this->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>=', Date::now())
            ->orderBy('scheduled_for')
            ->value('scheduled_for');
    }
```

Import `Carbon\CarbonImmutable` and `Illuminate\Support\Facades\Date`.

- [ ] **Step 4: Repoint the three surfaces**

`IntentionResource` — in the `active_action` block replace
`'scheduled_for' => $this->activeAction->scheduled_for,` with
`'next_occurrence_at' => $this->activeAction->nextOccurrenceAt(),` and drop
`'status' => $this->activeAction->status,` (the values it reported are being retired).

`DescribesActionShape::describeAction()` — replace
`'scheduled_for' => $action->scheduled_for?->toIso8601String(),` with
`'next_occurrence_at' => $action->nextOccurrenceAt()?->toIso8601String(),` and drop the `status`
key for the same reason.

`Api\ActionController` — same substitution.

`Intention::activeAction()`:

```php
    /**
     * The loggable action a card posts an outcome against: the loop's most
     * recent action that has not been archived. Which *occasion* it logs is
     * LogAction's business, not the relation's.
     *
     * @return HasOne<Action, $this>
     */
    public function activeAction(): HasOne
    {
        return $this->hasOne(Action::class)
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->latestOfMany();
    }
```

- [ ] **Step 5: Update the frontend types**

In `resources/js/patyourself/types.ts`, `ActiveActionData` becomes:

```ts
/** The loggable action embedded in an IntentionResource (the card's quick-log target). */
export interface ActiveActionData {
    id: number;
    title: string;
    description: string | null;
    /** The next occasion still awaiting an outcome, or null when today has none left. */
    next_occurrence_at: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
}
```

Then fix any fixture in `resources/js/pages/loops/show.test.tsx` that sets `status` or
`scheduled_for` on an `active_action`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=ActiveActionResourceTest`
Run: `php artisan test --compact --filter=IntentionCrudTest`
Run: `php artisan test --compact --filter=ActionCrudToolsTest`
Run: `php artisan test --compact --filter=IntentionScreensTest`
Run: `npx vitest run`
Expected: PASS all five.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(api): surfaces report next_occurrence_at instead of the cursor"
```

---

### Task 9: Every writer stops writing `scheduled_for`

The column still exists after this task; nothing writes it and nothing reads it.

**Files:**
- Modify: `app/Actions/CreateAction.php`, `app/Actions/PersistAuthoredIntention.php`,
  `app/Actions/RescheduleAction.php`, `app/Actions/StartExperiment.php`,
  `app/Actions/UpdateIntention.php`, `database/factories/ActionFactory.php`,
  `database/seeders/HabitDataSeeder.php`
- Test: every test that sets `'scheduled_for' => …` **on an Action** (23 files list matches, but
  most of those matches are on `Occurrence`, which keeps the column — check each one).

**Interfaces:**
- Produces: `ActionFactory` no longer sets `scheduled_for`; its `pending()` and `completed()` states
  are removed and an `archived()` state is added.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Actions/ActionWritersTest.php`:

```php
    public function test_creating_an_action_writes_only_the_anchor(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $action = app(CreateAction::class)->handle(
            $loop->refresh(),
            new AuthoredAction(
                title: 'Fill your water bottle first thing',
                description: null,
                kind: 'clock',
                time: '07:00',
                recurrence: 'daily',
                anchor: null,
            ),
        );

        $this->assertNotNull($action->series_started_at);
        $this->assertArrayNotHasKey('scheduled_for', $action->getAttributes());
    }
```

The `AuthoredAction` constructor signature above is exact: `title`, `description`, `kind`, `time`,
`recurrence`, `anchor`, all promoted public readonly.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=ActionWritersTest`
Expected: FAIL — `scheduled_for` is still in the attributes.

- [ ] **Step 3: Remove the writes**

- `CreateAction`: delete the `'scheduled_for' => $scheduledFor,` line; keep
  `'series_started_at' => $scheduledFor,`. Change `'status' => Action::STATUS_PENDING` to
  `'status' => Action::STATUS_ACTIVE`.
- `PersistAuthoredIntention`: same two changes.
- `RescheduleAction`: delete `'scheduled_for' => $scheduledFor,`; keep the `series_started_at` write
  and the purge from Task 3.
- `StartExperiment`: delete the `'scheduled_for' => $scheduledFor,` line and change
  `'status' => Action::STATUS_PENDING` to `Action::STATUS_ACTIVE`. Its carry-over query at line 124,
  `->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])`, becomes
  `->where('status', '!=', Action::STATUS_ARCHIVED)`. Its `$prior?->scheduled_for` fallback becomes
  `$prior?->series_started_at`.
- `UpdateIntention`: nothing to do — Task 3 already removed its write.
- Remove `'scheduled_for'` from `Action`'s `#[Fillable]` list and from `casts()`.

- [ ] **Step 4: Update the factory and seeder**

`database/factories/ActionFactory.php`:

```php
    public function definition(): array
    {
        return [
            'intention_id' => Intention::factory(),
            'strategy_id' => Strategy::factory(),
            'title' => fake()->randomElement([
                'Lay the book on your pillow each morning',
                'Put the snacks out of sight tonight',
                'Set your shoes by the door',
                'Leave your phone in another room',
                'Fill your water bottle first thing',
            ]),
            'description' => fake()->sentence(9),
            'series_started_at' => fake()->dateTimeBetween('-3 days', '+4 days'),
            'recurrence' => fake()->randomElement([null, 'daily', 'weekdays']),
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['schedule_kind' => 'clock', 'card' => ['style' => 'default']],
        ];
    }

    /** A cue-anchored action: no clock time, so no grid of occasions. */
    public function anchored(): static
    {
        return $this->state([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);
    }

    /** Removed from the loop's live set. `remove-action` archives, never deletes. */
    public function archived(): static
    {
        return $this->state(['status' => Action::STATUS_ARCHIVED]);
    }
```

Delete `pending()` and `completed()`, then fix their 26 call sites: `->pending()` becomes nothing,
and `->completed()` becomes either nothing or `->archived()` depending on what the test meant —
read each one.

Update `database/seeders/HabitDataSeeder.php` the same way.

- [ ] **Step 5: Sweep the remaining test fixtures**

```bash
grep -rn "'scheduled_for' =>" tests | grep -v Occurrence
grep -rn "Action::STATUS_PENDING\|Action::STATUS_COMPLETED\|Action::STATUS_SKIPPED" tests app
```

Every remaining hit on an Action becomes `series_started_at` / `Action::STATUS_ACTIVE`.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor(actions): nothing writes the next-due cursor any more"
```

---

### Task 10: Drop the cursor and the retired statuses

**Files:**
- Create: `database/migrations/2026_08_26_120001_retire_action_scheduled_for.php`
- Modify: `app/Models/Action.php`
- Test: `tests/Feature/Database/OccurrenceSchemaTest.php`, `tests/Feature/Models/ActionTest.php`

**Interfaces:**
- Produces: `Action::STATUSES === ['active', 'archived']`. `STATUS_PENDING`, `STATUS_COMPLETED`,
  `STATUS_SKIPPED`, `OPEN_STATUSES`, `isOpen()` and the `pending()` scope are all removed.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Models/ActionTest.php`:

```php
    public function test_an_action_holds_only_a_lifecycle_status(): void
    {
        $this->assertSame(
            [Action::STATUS_ACTIVE, Action::STATUS_ARCHIVED],
            Action::STATUSES,
        );
    }

    public function test_the_next_due_cursor_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('actions', 'scheduled_for'));
    }
```

Import `Illuminate\Support\Facades\Schema`.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=ActionTest`
Expected: FAIL — the column still exists and `STATUSES` still has five entries.

- [ ] **Step 3: Write the migration**

```php
<?php

use App\Models\Action;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the next-due cursor and the per-occasion action statuses.
 *
 * `scheduled_for` was the one answer to "what is due", and it was lossy:
 * Schedule::nextAfter() fast-forwarded past every missed slot, so a miss
 * vanished. Occurrences carry the whole grid and are now the only answer.
 *
 * The status values go with it. `pending -> active -> completed -> pending` was
 * the roll-forward cycle itself; a standing prescription is either live or
 * archived, and whether one of its occasions has been answered is a fact about
 * the occasion.
 *
 * Nothing here is unrecoverable: `scheduled_for` is derivable from
 * `series_started_at` + `recurrence`, and the retired statuses are derivable
 * from an action's logs and its occasions. down() restores the model, not each
 * row's exact prior value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('actions')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->update(['status' => Action::STATUS_ACTIVE]);

        Schema::table('actions', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn('scheduled_for');

            // The column default was 'pending', a value that no longer exists.
            // Left alone, any insert that omits status would write a status the
            // application cannot interpret.
            $table->string('status')->default(Action::STATUS_ACTIVE)->change();
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->timestamp('scheduled_for')->nullable()->after('description');
            $table->index('scheduled_for');
            $table->string('status')->default('pending')->change();
        });

        // The cursor's meaning was "the next slot at or after now", which the
        // anchor and the recurrence still describe exactly.
        DB::table('actions')
            ->whereNotNull('series_started_at')
            ->update(['scheduled_for' => DB::raw('series_started_at')]);

        DB::table('actions')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->update(['status' => 'pending']);
    }
};
```

Verified against `2026_06_04_100003_create_actions_table.php`: `scheduled_for` sits directly after
`description`, its index is the unnamed `$table->index('scheduled_for')` (so
`dropIndex(['scheduled_for'])` resolves to `actions_scheduled_for_index` correctly), and
`status` is declared `->default('pending')`.

`->change()` needs `doctrine/dbal` on older Laravel but not on 13 — if the change call fails,
fall back to a raw `ALTER TABLE` per driver rather than adding a dependency, which needs approval.

- [ ] **Step 4: Trim the `Action` model**

```php
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Every status an action can hold. A standing prescription is live or it is
     * put away; whether any given occasion of it was answered is a fact about
     * the occasion, not about the prescription.
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];
```

Delete `STATUS_PENDING`, `STATUS_COMPLETED`, `STATUS_SKIPPED`, `OPEN_STATUSES`, `isOpen()` and the
`pending()` scope. Grep for each before deleting:

```bash
grep -rn "OPEN_STATUSES\|isOpen()\|STATUS_PENDING\|STATUS_COMPLETED\|STATUS_SKIPPED" app tests resources | grep -v "Intention::\|Strategy::\|ActionLog::"
```

- [ ] **Step 5: Run the full suite on SQLite**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 6: Migration cycle and rehearsal on MySQL 8**

Point `.env` at MySQL 8, then:

```bash
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=2
php artisan migrate
php artisan test --compact
```

Then the data rehearsal, which is the part SQLite cannot prove. Seed a pre-branch-shaped loop:
a daily action mid-series with logs behind it, **and** a completed one-off whose log sits on an
occurrence stamped at `logged_at` rather than at its scheduled time — the migration `093412`
artefact. Confirm:

- the existing 24-August failure keeps its reason **verbatim**;
- the one-off's `logged_at`-stamped occurrence and its anchor-stamped sibling both exist, and
  neither appears in `TodaysOccasions::for()`;
- `php artisan actions:fire` fires today's occasions only, and firing twice fires nothing the
  second time.

Report the counts rather than asserting the rehearsal "looked fine".

- [ ] **Step 7: Frontend verification**

```bash
npx vitest run
npm run build
npm run lint
```

Expected: PASS all three.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(actions): retire the next-due cursor and the per-occasion statuses"
```

---

## Finishing

Follow `superpowers:finishing-a-development-branch`. Per the project's working agreements: merge
into local `main` with `--no-ff` and push `main` only. Never push the feature branch — CI only fires
on `main`, and a PR per feature doubles the runs.

Then append a **Carry-forward** section to this plan recording what the build found that is not
derivable from the code, in the style of the two previous plans, and commit that too.

## Self-review notes

- **Spec coverage.** Due window → T4, T6. Fire-state on the occurrence + lifecycle status → T1, T6,
  T10. Anchored stays a standing card → T4 (union), T7 (`freeSlotAt` path), T9 (`anchored()` state).
  Horizon at end of local day → T2. Incremental pass → T2. Reschedule purge → T3. Materialisation
  from the trigger engine → T6. Drop in one branch → T9 then T10. `next_occurrence_at` → T8.
  `OccurrenceFired` + notification payload → T6. Known `093412` artefact → T10 step 6.
- **Type consistency.** `TodaysOccasion` has the same four public properties everywhere it is
  constructed or read (T4, T5). `due` is only ever one of the three `TodaysOccasion` constants.
  `nextOccurrenceAt()` is defined once on `Action` (T8) and is the sole source for
  `next_occurrence_at` in all three resources. `fireDueOccurrences()` is the only public method
  added to `TriggerEngine`. `MaterialiseOccurrences::forUser`/`forLoop` keep their signatures
  throughout.
- **Ordering.** The read side (T4, T5) precedes the write side (T6) because `due` is derived from
  the clock, not from fire-state — that is what keeps the two from being circular. T9 must precede
  T10: dropping a column that code still writes fails at runtime, not at migrate time.
- **Found while reviewing this plan, not in the spec:** `actions.status` carries a database-level
  `default('pending')` from `2026_06_04_100003_create_actions_table.php`. Retiring the value without
  changing the default would leave any insert that omits `status` writing a status the application
  can no longer interpret. T10's migration changes it in both directions.
- **Deliberately not covered** (spec "Out of scope"): `conclude-experiment` and the `review_at`
  retention change, `write-reflection` / the `summaries` writer, MCP prompts, the dashboard reframe,
  `/log`, the `/loops` index redesign, the item-7 cleanups, removing `laravel/ai`.

## Carry-forward: what the build found

Facts discovered across the ten tasks that are not derivable from the code.

**1. `npm install` in a worktree rewrites `package-lock.json`'s `name` field to the worktree
directory name.** Every task that ran it had to revert the file before committing. Not a bug in
this branch — an artefact of how npm resolves the package name inside a linked worktree — but it
will bite the next branch built the same way if unremembered.

**2. The purge-block duplication between `RescheduleAction` and `UpdateIntention` is intentional,
not missed.** Task 3 found the same "delete unlogged future occasions, leave logged ones alone"
block copy-pasted in both places and proposed extracting it; the human partner ruled the two call
sites are conceptually independent (a user editing a schedule vs. a paused loop reactivating) and a
shared helper on the model would wrongly couple them. Left duplicated on purpose — a future pass
should not "clean this up" without re-reading that ruling.

**3. The plan baked the same non-discriminating test fixture into two different tasks.** Both
Task 4's (`TodaysOccasions`) and Task 6's (`TriggerEngine`) local-day-window tests used a fixture
that a global-UTC-day regression would also satisfy, so neither, as originally written, actually
proved the per-user-timezone window was in effect. Both were caught only because the review step
hand-computed the two candidate windows and checked the fixture sat inside one and outside the
other. Worth remembering as a category of mistake — a plan can specify a test that looks
discriminating and isn't — not just a one-off typo.

**4. Retiring `Action::STATUSES` down to two values retroactively empties two tests, not one —
but only one of the two carried surviving coverage.** The plan's own file list only named
`Action::pending()`/`isOpen()` for removal, but `ActionTest::test_pending_scope_and_is_open_predicate_track_unlogged_cards`
called those methods directly and had to go with them. Separately, `LoopRelationshipsTest` carried
two more tests Task 9 deliberately left alone specifically so Task 10 could decide their fate:
`test_pending_scope_returns_only_open_actions` tested the now-deleted `pending()` scope directly and
was correctly deleted outright. `test_active_action_is_the_most_recent_action_regardless_of_status`
was first deleted the same way, but that was wrong: underneath the retired "regardless of status"
framing it was also the *only* test covering `Intention::activeAction()`'s `latestOfMany()`
selection among several qualifying rows — untouched behaviour that still applies. Review caught
this and it was restored in reduced form as
`test_active_action_is_the_most_recent_non_archived_action` (two `STATUS_ACTIVE` actions on one
loop, asserting `activeAction` resolves to the higher-`id` one), with the "regardless of status"
claim dropped rather than resurrected via a legacy constant. Verified non-vacuous by temporarily
swapping the relation's `latestOfMany()` for `oldestOfMany()`: the test failed
(`Failed asserting that 1 is identical to 2`), confirming it would catch a real ordering
regression; reverted afterward. The lesson for future deletions in this position: "the premise this
test's name states is gone" and "there is no other test covering what this test happens to cover"
are two separate questions, and answering only the first one is how coverage quietly disappears.

**5. The `093412` backfill artefact is real and still invisible to "today" by construction, not by
luck.** A log written against an anchor but stamped well after it (a completed one-off logged at
08:47 against an 08:00 anchor) leaves two occurrence rows for one action: the anchor-stamped one
materialisation produces, and the `logged_at`-stamped one that migration synthesised. Rehearsed on
MySQL 9.3 by hand-reconstructing the pair (the migration itself only converts rows present at the
moment it runs, so replaying it against fresh data produces nothing): both rows exist, and
`TodaysOccasions::for()` — which only ever looks inside the caller's *current* local day — excludes
both regardless, with no special-case logic needed to keep the duplicate off the list.

**Verification performed beyond the per-task suites:** the full PHP suite (511/511) and Vitest
suite (81/81) at the branch tip; a `migrate:fresh --seed` → `migrate:rollback --step=2` → `migrate`
cycle on MySQL 9.3.0 run twice — once against `HabitDataSeeder`'s randomised data, once against a
hand-built dataset with real logs and occurrences attached — confirming `down()` recomputes
`scheduled_for` from `series_started_at` and restores the `pending` default, and `up()` re-collapses
`status` and drops the column again, with `occurrences`/`action_logs` row counts and a
verbatim-reason byte comparison (`HEX()`) unchanged across every step; and two live
`php artisan actions:fire` runs confirming exactly one occasion (today's) fires and a second,
immediate run fires zero.

**Environment note:** local MySQL is 9.3.0; production is MySQL 8. Nothing in the final migration
uses version-specific syntax (`->change()` needed no `doctrine/dbal` on Laravel 13, so no
driver-specific `ALTER TABLE` fallback was needed), but every MySQL rehearsal in this branch proves
9.3 behaviour, not 8's specifically.

**Still open, inherited and untouched:** `fired_at`'s sub-second precision is kept in memory after
a guarded fire but truncated on write (Task 6, display-only, no test catches it); the
`action_id`-fallback branch of `markCueAnswered` has its own action-discrimination unproven (Task 7);
`Api\ActionController::update()` has zero test coverage, pre-existing; `ConcludeExperiment` still
clears `review_at` with no caller and `laravel/ai` remains an unused dependency, both out of scope
for this whole branch.
