# Trigger Engine (SP2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the scheduler that scans due actions, fires them (`pending → active` so they surface as live to-dos), and rolls recurring actions forward to their next occurrence — without any notification delivery (SP3) or auto-revision (SP4).

**Architecture:** A pure `Schedule::nextAfter()` method fast-forwards a recurring time past missed slots to the first future occurrence. A `TriggerEngine` service scans `pending` actions due in active intentions and flips each to `active` with a guarded atomic update (the idempotency key). A thin `actions:fire` console command runs the engine; the scheduler invokes it `everyMinute()->withoutOverlapping()`. `LogAction` re-arms a recurring action when its occurrence is completed or skipped (one row rolled forward via `nextAfter`); one-offs and anchored actions close as before. The card shows a "Due now" badge when an action has fired.

**Tech Stack:** Laravel 13 / PHP 8.4, Carbon (^2.71 || ^3.0), PHPUnit 12, Inertia v3 + React 19 + TypeScript, Tailwind v4, Vitest, Pint. App served by Herd (never `php artisan serve`); frontend tooling needs Node 22.

---

## Architecture notes (read once before starting)

- **Roll-forward in place:** one Action row per recurring commitment, carrying the next `scheduled_for`. The engine never spawns rows; recurrence advances when an occurrence is resolved (in `LogAction`). Per-occurrence history lives in `action_logs.logged_at`.
- **Firing = `pending → active` only.** It does NOT touch `scheduled_for`. A fired action is `active` with `scheduled_for` still at the occurrence that came due (the card reads "due now").
- **Idempotency:** each row is flipped with `UPDATE … WHERE id=? AND status='pending'`; only the run whose update affects 1 row owns the fire. Plus `withoutOverlapping()` on the schedule.
- **Catch-up:** a stale pending action fires exactly once; on resolution, `nextAfter` skips all missed slots. No backfill.
- **DST:** wall-clock anchor. `Schedule::advance()` already does local-space math (`setTimezone → add period → ->utc()`); `nextAfter` loops it, so a 07:00 action stays 07:00 local across a DST boundary while the UTC instant shifts.
- **Scope:** engine only. No queue worker, no notifications (SP3), no auto-revision/summary (SP4), no anchored-recurrence support, no polling.

## File structure

**Create**
- `app/Services/Scheduling/TriggerEngine.php` — scan + fire due actions (the engine).
- `app/Console/Commands/FireDueActions.php` — `actions:fire`, thin wrapper over the engine.
- `tests/Feature/Scheduling/TriggerEngineTest.php` — engine behaviour.
- `tests/Feature/Console/FireDueActionsCommandTest.php` — command wiring.

**Modify**
- `app/Services/Scheduling/Schedule.php` — add `nextAfter()`.
- `tests/Unit/Scheduling/ScheduleTest.php` — `nextAfter` + DST tests.
- `app/Actions/LogAction.php` — re-arm recurring actions on resolution.
- `tests/Feature/Actions/LogActionTest.php` — re-arm tests + deterministic helper.
- `routes/console.php` — register the scheduled command.
- `resources/js/patyourself/chat/action-card.tsx` — "Due now" badge.
- `resources/js/patyourself/chat/action-card.test.tsx` — badge tests.

**Commands reference**
- PHP tests: `php artisan test --compact --filter=<name>`
- Pint (after any PHP edit): `vendor/bin/pint --dirty --format agent`
- Frontend (needs Node 22). Prefix every npm command with the Node 22 path:
  `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run <script>`

---

## Task 1: `Schedule::nextAfter()` — fast-forward math

**Files:**
- Modify: `app/Services/Scheduling/Schedule.php`
- Test: `tests/Unit/Scheduling/ScheduleTest.php`

- [ ] **Step 1: Add the failing tests**

Append these methods inside the `ScheduleTest` class in `tests/Unit/Scheduling/ScheduleTest.php` (before the closing `}`):

```php
    public function test_next_after_takes_one_step_for_a_fresh_base(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-13 07:00:00');     // the occurrence that just fired
        $now = $this->at('2026-06-13 07:00:30');      // a moment later

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_next_after_fast_forwards_past_stale_daily_slots(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-10 07:00:00');     // 3 days stale
        $now = $this->at('2026-06-13 09:00:00');

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'UTC');

        // First daily slot strictly after now (06-13 07:00 is before 09:00).
        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_next_after_weekly_preserves_the_weekday(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-05-29 07:00:00');     // a Friday
        $this->assertTrue($base->isFriday());
        $now = $this->at('2026-06-13 09:00:00');

        $next = $schedule->nextAfter($base, $now, Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-06-19 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($next->isFriday());          // still a Friday
    }

    public function test_next_after_weekdays_skips_the_weekend(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-12 07:00:00');     // a Friday
        $now = $this->at('2026-06-13 09:00:00');      // Saturday

        $next = $schedule->nextAfter($base, $now, Recurrence::Weekdays, 'UTC');

        $this->assertSame('2026-06-15 07:00:00', $next->utc()->format('Y-m-d H:i:s')); // Monday
    }

    public function test_next_after_returns_null_for_a_one_off(): void
    {
        $next = (new Schedule)->nextAfter($this->at('2026-06-13 07:00:00'), $this->at('2026-06-13 08:00:00'), null, 'UTC');

        $this->assertNull($next);
    }

    public function test_advance_holds_wall_clock_across_spring_forward(): void
    {
        // 07:00 in New York on Sat 2026-03-07 (EST, UTC-5) == 12:00 UTC.
        $current = $this->at('2026-03-07 12:00:00');

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York');

        // Sun 2026-03-08 is EDT (UTC-4): 07:00 local == 11:00 UTC (UTC shifts, local holds).
        $this->assertSame('2026-03-08 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_advance_holds_wall_clock_across_fall_back(): void
    {
        // 07:00 in New York on Sat 2026-10-31 (EDT, UTC-4) == 11:00 UTC.
        $current = $this->at('2026-10-31 11:00:00');

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York');

        // Sun 2026-11-01 is EST (UTC-5): 07:00 local == 12:00 UTC.
        $this->assertSame('2026-11-01 12:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_next_after_fast_forwards_across_a_dst_boundary(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-03-06 12:00:00');     // Fri 07:00 EST
        $now = $this->at('2026-03-09 09:00:00');      // Mon, after spring-forward

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'America/New_York');

        // Mon 2026-03-09 07:00 EDT == 11:00 UTC; still 07:00 local.
        $this->assertSame('2026-03-09 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_advance_survives_the_spring_forward_gap_hour(): void
    {
        // 02:30 New York on 2026-03-07 (EST) == 07:30 UTC. Advancing a day lands
        // on 2026-03-08, when 02:30 local does not exist (clocks jump 02:00->03:00).
        // We assert it produces a valid instant strictly after the base rather than
        // a brittle exact value, since gap resolution is Carbon-version dependent.
        $base = $this->at('2026-03-07 07:30:00');

        $next = (new Schedule)->advance($base, Recurrence::Daily, 'America/New_York');

        $this->assertNotNull($next);
        $this->assertTrue($next->utc()->greaterThan($base));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: FAIL — `Call to undefined method App\Services\Scheduling\Schedule::nextAfter()` (the `advance` DST tests also run; they pass, but `nextAfter` tests error).

- [ ] **Step 3: Implement `nextAfter`**

In `app/Services/Scheduling/Schedule.php`, add this method after `advance()` (before `skipWeekend`):

```php
    /**
     * The next fire time strictly after `now`, in UTC — fast-forwarding past any
     * occurrences missed while the app was down. Repeatedly applies advance()
     * (which preserves wall-clock time in the user's timezone, so it is
     * DST-correct and keeps weekly's weekday). Null for a one-off, which is never
     * re-armed.
     */
    public function nextAfter(CarbonImmutable $from, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        if ($recurrence === null) {
            return null;
        }

        $next = $from;

        do {
            $next = $this->advance($next, $recurrence, $timezone);
        } while ($next !== null && $next->lessThanOrEqualTo($now));

        return $next;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: PASS (original 6 + 9 new = 15 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scheduling/Schedule.php tests/Unit/Scheduling/ScheduleTest.php
git commit -m "feat(scheduling): Schedule::nextAfter fast-forwards past missed slots"
```

---

## Task 2: `TriggerEngine` service

**Files:**
- Create: `app/Services/Scheduling/TriggerEngine.php`
- Test: `tests/Feature/Scheduling/TriggerEngineTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Scheduling/TriggerEngineTest.php`:

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriggerEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A pending action due `subMinute` ago in an active intention, unless
     * overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function dueAction(array $overrides = [], string $intentionStatus = Intention::STATUS_ACTIVE): Action
    {
        $intention = Intention::factory()->create(['status' => $intentionStatus]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        return Action::factory()->for($intention)->create(array_merge([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => 'daily',
        ], $overrides));
    }

    public function test_fires_a_due_pending_action(): void
    {
        $action = $this->dueAction();

        $fired = app(TriggerEngine::class)->fireDueActions();

        $this->assertSame(1, $fired);
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
        $this->assertNotNull($action->fresh()->metadata['fired_at']);
    }

    public function test_does_not_fire_a_future_action(): void
    {
        $action = $this->dueAction(['scheduled_for' => now()->addHour()]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_fire_an_anchored_action(): void
    {
        $action = $this->dueAction(['scheduled_for' => null, 'recurrence' => null]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_fire_when_the_intention_is_not_active(): void
    {
        $action = $this->dueAction([], Intention::STATUS_PAUSED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_refire_an_already_active_action(): void
    {
        $action = $this->dueAction(['status' => Action::STATUS_ACTIVE]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
    }

    public function test_is_idempotent_across_runs(): void
    {
        $action = $this->dueAction();
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueActions());
        $this->assertSame(0, $engine->fireDueActions()); // second run fires nothing
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }

    public function test_catch_up_fires_a_stale_action_exactly_once(): void
    {
        $action = $this->dueAction(['scheduled_for' => now()->subDays(3)]);
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueActions());
        $this->assertSame(0, $engine->fireDueActions()); // no backfill
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }

    public function test_returns_the_count_of_fired_actions(): void
    {
        $this->dueAction();
        $this->dueAction();
        $this->dueAction(['scheduled_for' => now()->addHour()]); // future, not fired

        $this->assertSame(2, app(TriggerEngine::class)->fireDueActions());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=TriggerEngineTest`
Expected: FAIL — `Class "App\Services\Scheduling\TriggerEngine" not found`.

- [ ] **Step 3: Implement the engine**

Create `app/Services/Scheduling/TriggerEngine.php`:

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use Illuminate\Database\Eloquent\Builder;

/**
 * The trigger engine: scans for actions whose scheduled fire time has arrived
 * and transitions them pending -> active so they surface as live "due" to-dos.
 * Firing is idempotent — each row is flipped with a guarded conditional update,
 * so an overlapping or repeated run fires every occurrence at most once. The
 * actions:fire command runs this every minute.
 *
 * SP2 does nothing beyond this in-app state transition. Recurrence roll-forward
 * happens when an occurrence is resolved (see App\Actions\LogAction); rich
 * notification delivery is SP3.
 */
final class TriggerEngine
{
    /**
     * Fire every due, pending action belonging to an active intention. Returns
     * the number actually fired (won by this run's guarded update).
     */
    public function fireDueActions(): int
    {
        $due = Action::query()
            ->where('status', Action::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->whereHas('intention', function (Builder $query): void {
                $query->where('status', Intention::STATUS_ACTIVE);
            })
            ->get();

        $fired = 0;

        foreach ($due as $action) {
            if ($this->fire($action)) {
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Atomically flip one action pending -> active. Returns true only for the
     * run whose guarded update actually changed the row (the fire owner); a
     * concurrent or repeated run sees 0 affected rows and returns false.
     */
    private function fire(Action $action): bool
    {
        $metadata = array_merge($action->metadata ?? [], [
            'fired_at' => now()->toIso8601String(),
        ]);

        $affected = Action::query()
            ->whereKey($action->getKey())
            ->where('status', Action::STATUS_PENDING)
            ->update([
                'status' => Action::STATUS_ACTIVE,
                'metadata' => json_encode($metadata),
            ]);

        return $affected === 1;
    }
}
```

(Note: the guarded `update()` runs through the base query builder, so `metadata` is encoded explicitly with `json_encode` — the `array` cast only applies on model reads.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TriggerEngineTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scheduling/TriggerEngine.php tests/Feature/Scheduling/TriggerEngineTest.php
git commit -m "feat(scheduling): TriggerEngine fires due pending actions idempotently"
```

---

## Task 3: `actions:fire` command + scheduler registration

**Files:**
- Create: `app/Console/Commands/FireDueActions.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/FireDueActionsCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/FireDueActionsCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FireDueActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fires_due_actions(): void
    {
        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => 'daily',
        ]);

        $this->artisan('actions:fire')->assertSuccessful();

        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=FireDueActionsCommandTest`
Expected: FAIL — `The command "actions:fire" does not exist.`

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/FireDueActions.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Scheduling\TriggerEngine;
use Illuminate\Console\Command;

/**
 * Scans for actions whose scheduled fire time has arrived and transitions them
 * pending -> active so they surface as live to-dos. The scheduler runs this
 * every minute (see routes/console.php). A thin wrapper: all logic lives in the
 * TriggerEngine service so it can be feature-tested directly.
 */
class FireDueActions extends Command
{
    protected $signature = 'actions:fire';

    protected $description = 'Fire due actions (pending -> active) for the trigger engine';

    public function handle(TriggerEngine $engine): int
    {
        $fired = $engine->fireDueActions();

        $this->components->info("Fired {$fired} action(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=FireDueActionsCommandTest`
Expected: PASS (1 test).

- [ ] **Step 5: Register the scheduled command**

In `routes/console.php`, add the imports below the existing `use` lines and append the schedule registration at the end of the file:

```php
use App\Console\Commands\FireDueActions;
use Illuminate\Support\Facades\Schedule;
```

```php
// The trigger engine: every minute, fire any actions whose time has come.
// withoutOverlapping() prevents a slow run from racing the next minute's run;
// the engine's own guarded update is the second idempotency layer.
Schedule::command(FireDueActions::class)->everyMinute()->withoutOverlapping();
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `php artisan schedule:list`
Expected: a line for `php artisan actions:fire` running `* * * * *` (every minute).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/FireDueActions.php routes/console.php tests/Feature/Console/FireDueActionsCommandTest.php
git commit -m "feat(scheduling): actions:fire command scheduled every minute"
```

---

## Task 4: `LogAction` re-arms recurring actions

**Files:**
- Modify: `app/Actions/LogAction.php`
- Test: `tests/Feature/Actions/LogActionTest.php`

- [ ] **Step 1: Make the existing helper deterministic**

`ActionFactory` randomises `recurrence`/`scheduled_for`, so once re-arm lands, the existing completion/skip tests would flap (a recurring action re-arms to `pending` instead of closing). Pin the shared helper to a one-off. In `tests/Feature/Actions/LogActionTest.php`, replace the `action()` method body:

```php
    private function action(User $user): Action
    {
        // A one-off (no recurrence): completing or skipping it closes it out,
        // which is what the existing close-behaviour tests assert. Recurring
        // re-arm is covered by the dedicated tests below.
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'scheduled_for' => null,
            ]);
    }
```

Run: `php artisan test --compact --filter=LogActionTest`
Expected: PASS (the existing 4 tests still pass — behaviour for one-offs is unchanged).

- [ ] **Step 2: Write the failing re-arm tests**

Append these methods inside the `LogActionTest` class (before the closing `}`):

```php
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recurringAction(User $user, array $overrides = []): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(array_merge([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => 'daily',
                'scheduled_for' => now()->subMinutes(5),
            ], $overrides));
    }

    public function test_completing_a_recurring_action_rearms_it_to_the_next_occurrence(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
        // The completion is preserved as a log event.
        $this->assertSame(1, $fresh->logs()->where('outcome', ActionLog::OUTCOME_COMPLETED)->count());
    }

    public function test_skipping_a_recurring_action_rearms_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_SKIPPED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
    }

    public function test_failing_a_recurring_action_leaves_it_open(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'busy',
        ]);

        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status); // unchanged, no re-arm
    }

    public function test_completing_a_one_off_action_closes_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, ['recurrence' => null]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_completing_an_anchored_action_closes_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, [
            'recurrence' => null,
            'scheduled_for' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after coffee'],
        ]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_completing_a_stale_recurring_action_fast_forwards_to_the_future(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, ['scheduled_for' => now()->subDays(3)]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture()); // not a past slot
    }
```

Add `use App\Models\User;` if not already imported (it is, in the existing file).

- [ ] **Step 3: Run them to verify they fail**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: FAIL — `test_completing_a_recurring_action_rearms_it...` expects `pending` but gets `completed`; the skip/stale tests fail likewise.

- [ ] **Step 4: Implement re-arm in `LogAction`**

Rewrite `app/Actions/LogAction.php` to inject `Schedule` and re-arm recurring actions. Add the imports, a constructor, and a `closeOrRearm` helper:

```php
<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Records the outcome of an action — completed, failed, or skipped — and
 * advances the action's own status to match. A log is an immutable event; on
 * failure it carries the user-stated reason, which is the raw material the
 * versioned-strategy logic and the rolling summaries later feed on.
 *
 * A recurring action does not close when an occurrence is completed or skipped:
 * it rolls forward in place to its next occurrence (the SP2 trigger engine's
 * recurrence mechanic). One-off and anchored actions close as before.
 *
 * This is the only place the logging flow writes to the database. It is
 * deliberately free of LLM side-effects — revising a strategy and refolding a
 * summary both make model calls, so they run as separate, explicit steps.
 */
final readonly class LogAction
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  array<string, mixed>  $data  Validated outcome / reason / metadata.
     */
    public function handle(User $user, Action $action, array $data): ActionLog
    {
        return DB::transaction(function () use ($user, $action, $data): ActionLog {
            $log = $action->logs()->create([
                'user_id' => $user->id,
                'outcome' => $data['outcome'],
                'reason' => $data['reason'] ?? null,
                'logged_at' => Date::now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $status = $this->actionStatusFor($data['outcome']);

            if ($status !== null) {
                $this->closeOrRearm($user, $action, $status);
            }

            return $log;
        });
    }

    /**
     * A completion or skip closes a one-off / anchored action, but rolls a
     * recurring action forward to its next occurrence (status back to pending,
     * scheduled_for fast-forwarded past any missed slots).
     */
    private function closeOrRearm(User $user, Action $action, string $closingStatus): void
    {
        $isRecurring = $action->recurrence !== null && $action->scheduled_for !== null;

        if (! $isRecurring) {
            $action->update(['status' => $closingStatus]);

            return;
        }

        $next = $this->schedule->nextAfter(
            $action->scheduled_for->toImmutable(),
            CarbonImmutable::now(),
            Recurrence::tryFromToken($action->recurrence),
            $user->timezone ?? (string) config('app.timezone'),
        );

        if ($next === null) {
            // Defensive: an unrecognised recurrence token — close it out.
            $action->update(['status' => $closingStatus]);

            return;
        }

        $action->update([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => $next,
        ]);
    }

    /**
     * How an outcome moves the action card. A failure leaves it open so the
     * user can retry (or a strategy revision can supersede it later); only a
     * completion or a skip closes — or, for a recurring action, re-arms — it.
     */
    private function actionStatusFor(string $outcome): ?string
    {
        return match ($outcome) {
            ActionLog::OUTCOME_COMPLETED => Action::STATUS_COMPLETED,
            ActionLog::OUTCOME_SKIPPED => Action::STATUS_SKIPPED,
            default => null,
        };
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: PASS (existing 4 + 6 new = 10 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/LogAction.php tests/Feature/Actions/LogActionTest.php
git commit -m "feat(scheduling): LogAction rolls recurring actions forward on resolution"
```

---

## Task 5: "Due now" badge on the action card

**Files:**
- Modify: `resources/js/patyourself/chat/action-card.tsx`
- Test: `resources/js/patyourself/chat/action-card.test.tsx`

The `active_action.status` field is already serialized (SP1) and typed in `types.ts` (`ActiveActionData.status: string`). No type or backend change is needed — only the card render.

- [ ] **Step 1: Write the failing tests**

Append these `it(...)` blocks inside the `describe('ActionCard', ...)` block in `resources/js/patyourself/chat/action-card.test.tsx` (before the closing `});`):

```tsx
    it('shows a "Due now" badge when the active action has fired', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 8,
                        title: 'Walk',
                        description: null,
                        status: 'active',
                        scheduled_for: '2026-06-15T11:00:00.000000Z',
                        recurrence: 'daily',
                        schedule_kind: 'clock',
                        anchor: null,
                    },
                })}
            />,
        );

        expect(screen.getByText('Due now')).toBeInTheDocument();
    });

    it('omits the "Due now" badge when the action is only pending', () => {
        render(
            <ActionCard
                intention={makeIntention({
                    active_action: {
                        id: 9,
                        title: 'Walk',
                        description: null,
                        status: 'pending',
                        scheduled_for: '2026-06-15T11:00:00.000000Z',
                        recurrence: 'daily',
                        schedule_kind: 'clock',
                        anchor: null,
                    },
                })}
            />,
        );

        expect(screen.queryByText('Due now')).not.toBeInTheDocument();
    });
```

- [ ] **Step 2: Run them to verify they fail**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- resources/js/patyourself/chat/action-card.test.tsx`
Expected: FAIL — `Unable to find an element with the text: Due now`.

- [ ] **Step 3: Render the badge**

In `resources/js/patyourself/chat/action-card.tsx`, add a "Due now" badge immediately after the `ScheduleChip` block. Replace:

```tsx
            {intention.active_action && (
                <ScheduleChip action={intention.active_action} />
            )}
```

with:

```tsx
            {intention.active_action && (
                <div className="mt-2 flex items-center gap-2">
                    <ScheduleChip action={intention.active_action} />
                    {intention.active_action.status === 'active' && (
                        <span className="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                            Due now
                        </span>
                    )}
                </div>
            )}
```

(The `ScheduleChip` keeps its own `mt-2`; the wrapping `div` groups the chip and badge on one row. This is purely additive — the chip still renders for pending actions, just without the badge.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- resources/js/patyourself/chat/action-card.test.tsx`
Expected: PASS (all ActionCard tests, including the 2 new ones).

- [ ] **Step 5: Type-check, format, commit**

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run types:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run format
git add resources/js/patyourself/chat/action-card.tsx resources/js/patyourself/chat/action-card.test.tsx
git commit -m "feat(ui): show a Due now badge when an action has fired"
```

---

## Task 6: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHP test suite**

Run: `php artisan test --compact`
Expected: all green. Pay attention to `AuthorIntentionTest`, `ReviseStrategyTest`, `RescheduleActionWebTest`, and `LogActionTest` (SP1 paths that touch actions) — they must still pass.

- [ ] **Step 2: Run the full frontend test suite + type-check**

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run types:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run lint:check
```
Expected: tests pass, no type errors, no lint errors.

- [ ] **Step 3: Final Pint check**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean (nothing to fix, or already committed).

- [ ] **Step 4: Confirm the success criteria**

Re-read `docs/superpowers/specs/2026-06-14-trigger-engine-design.md` "Success criteria" and confirm each is met by a passing test:
1. Due pending → active (TriggerEngineTest).
2. Recurring rolls forward; one-off closes; anchored never fires (LogActionTest + TriggerEngineTest).
3. Fires at most once (TriggerEngineTest idempotency).
4. Catch-up fires once + fast-forwards (TriggerEngineTest + LogActionTest stale).
5. DST-correct (ScheduleTest).
6. Tests pass, Pint clean.
7. No notifications / no auto-revision (nothing added beyond the above).

- [ ] **Step 5: Hand off**

Report status and use `superpowers:finishing-a-development-branch` to choose how to integrate (merge / PR).

---

## Self-review notes (for the planner — not an execution step)

- **Spec coverage:** every spec component maps to a task — `nextAfter` (T1), `TriggerEngine` (T2), `actions:fire` + schedule (T3), `LogAction` re-arm (T4), "Due now" badge (T5), full verification (T6). DST, idempotency, and catch-up each have dedicated tests.
- **Type consistency:** `fireDueActions(): int`, `nextAfter(CarbonImmutable, CarbonImmutable, ?Recurrence, string): ?CarbonImmutable`, `closeOrRearm(User, Action, string): void` are used consistently across tasks. `Recurrence::tryFromToken` and `Schedule::advance` are pre-existing (SP1).
- **No new schema:** SP2 reuses `actions.scheduled_for` / `recurrence` / `metadata` and `users.timezone` (all from SP1). No migration.
