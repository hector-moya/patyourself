# Auto-Coaching Closure (SP4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make logging an action automatically run the coaching pass — always refold the loop's rolling summary, and on a streak threshold (2 consecutive failures → restrategize, 5 consecutive successes → stack) auto-revise the strategy, surfacing the revision as an in-app inbox cue.

**Architecture:** `LogAction` dispatches an `ActionLogged` event after its transaction commits. An auto-discovered, queued `RunCoachingClosure` listener reacts off the request: it calls the existing `UpdateRollingSummary` (no-op safe), computes the active strategy's outcome streak via a new pure `OutcomeStreak` service, and on threshold calls the existing `ReviseStrategy`, then notifies the owner with a new `StrategyRevisedNotification` on SP3's `database` channel. No new coaching logic, model, migration, or table — SP4 is wiring around primitives SP1–SP3 already shipped.

**Tech Stack:** Laravel 13 / PHP 8.4, Laravel\Ai agents (`Promptable::fake`), `database` queue, Laravel database notifications, PHPUnit 12, Inertia v3 + React 19 + TS, Vitest.

## Global Constraints

- App served by Herd at https://patyourself.test — **NEVER** run `php artisan serve`. Only `npm run dev` for Vite if needed.
- After editing PHP, run `vendor/bin/pint --dirty --format agent` (never `--test`).
- Format touched JS/TS only: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write <files>`. Never whole-tree formatters.
- Frontend tooling needs Node 22 — prefix npm/npx: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"`.
- Tests: PHPUnit only (no Pest). `php artisan test --compact --filter=<name>` while iterating.
- Threshold defaults live in `config/services.php` under the existing `coach` array: `fail_streak` (2), `stack_streak` (5). Read via `config('services.coach.fail_streak', 2)` / `config('services.coach.stack_streak', 5)`.
- Reuse, do not modify: `App\Actions\ReviseStrategy`, `App\Actions\UpdateRollingSummary`, their agents and prompts.
- LLM agents are faked in tests with `Strategist::fake([$structured, …])` / `Summarizer::fake([['content'=>…, 'patterns'=>[…]]])` and asserted with `Strategist::assertNeverPrompted()` (from `Laravel\Ai\Promptable`).
- Commit message trailer on every commit: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File structure

**New**
- `app/Events/ActionLogged.php` — immutable event carrying `User`, `Action`, `ActionLog`; `ShouldDispatchAfterCommit`.
- `app/Notifications/StrategyRevisedNotification.php` — `database`-channel notification describing a revision.
- `app/Services/Coach/OutcomeStreak.php` — pure service: the leading non-skip outcome run on a strategy's logs.
- `app/Listeners/RunCoachingClosure.php` — queued listener composing summary + streak + revision + notification.
- `tests/Feature/Coach/OutcomeStreakTest.php`
- `tests/Feature/Coach/RunCoachingClosureTest.php`
- `tests/Feature/Notifications/StrategyRevisedNotificationTest.php`

**Modified**
- `app/Actions/LogAction.php` — dispatch `ActionLogged` after the log is written.
- `config/services.php` — `coach.fail_streak`, `coach.stack_streak`.
- `app/Http/Controllers/InboxController.php` — map `type` + `change_reason` + `approach` in `index`.
- `resources/js/patyourself/types.ts` — widen `NotificationData`.
- `resources/js/pages/inbox.tsx` — render the `strategy_revised` row type.
- `resources/js/pages/inbox.test.tsx` — revision-row + due-cue regression tests.
- `tests/Feature/Actions/LogActionTest.php` — `ActionLogged` dispatch tests.
- `tests/Feature/Inbox/InboxControllerTest.php` — `index` maps revision fields.

---

### Task 1: `ActionLogged` event + dispatch from `LogAction`

**Files:**
- Create: `app/Events/ActionLogged.php`
- Modify: `app/Actions/LogAction.php`
- Test: `tests/Feature/Actions/LogActionTest.php`

**Interfaces:**
- Produces: `App\Events\ActionLogged` with public readonly `$user: User`, `$action: Action`, `$log: ActionLog`; `ActionLogged::dispatch($user, $action, $log)`. `implements ShouldDispatchAfterCommit`. Consumed by Task 4's listener.

- [ ] **Step 1: Write the failing test** — append to `tests/Feature/Actions/LogActionTest.php`. Add the import `use App\Events\ActionLogged;` and `use Illuminate\Support\Facades\Event;` at the top, then:

```php
public function test_logging_dispatches_the_action_logged_event(): void
{
    Event::fake([ActionLogged::class]);

    $user = User::factory()->create();
    $action = $this->action($user);

    $log = app(LogAction::class)->handle($user, $action, [
        'outcome' => ActionLog::OUTCOME_FAILED,
        'reason' => 'Too tired',
    ]);

    Event::assertDispatched(ActionLogged::class, function (ActionLogged $event) use ($user, $action, $log): bool {
        return $event->user->is($user)
            && $event->action->is($action)
            && $event->log->is($log);
    });
}

public function test_logging_dispatches_the_event_for_every_outcome(): void
{
    Event::fake([ActionLogged::class]);

    $user = User::factory()->create();

    foreach ([ActionLog::OUTCOME_COMPLETED, ActionLog::OUTCOME_SKIPPED] as $outcome) {
        app(LogAction::class)->handle($user, $this->action($user), ['outcome' => $outcome]);
    }

    Event::assertDispatchedTimes(ActionLogged::class, 2);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='test_logging_dispatches'`
Expected: FAIL — `Class "App\Events\ActionLogged" not found`.

- [ ] **Step 3: Create the event**

```php
<?php

namespace App\Events;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after an action's outcome is durably logged (see {@see \App\Actions\LogAction}).
 * Carries the full context so an async listener can run the coaching closure (SP4)
 * without re-querying. ShouldDispatchAfterCommit: if LogAction's transaction rolls
 * back, no coaching is triggered.
 */
final class ActionLogged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Action $action,
        public readonly ActionLog $log,
    ) {}
}
```

- [ ] **Step 4: Dispatch it from `LogAction`** — in `app/Actions/LogAction.php` add `use App\Events\ActionLogged;` to the imports, then inside `handle()`'s `DB::transaction` closure, after `$this->markCueAnswered($user, $action);` and before `return $log;`:

```php
            $this->markCueAnswered($user, $action);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Actions/LogActionTest.php`
Expected: PASS (the two new tests + all existing LogAction tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Events/ActionLogged.php app/Actions/LogAction.php tests/Feature/Actions/LogActionTest.php
git commit -m "feat(sp4): dispatch ActionLogged event after a log is written

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: `StrategyRevisedNotification`

**Files:**
- Create: `app/Notifications/StrategyRevisedNotification.php`
- Test: `tests/Feature/Notifications/StrategyRevisedNotificationTest.php`

**Interfaces:**
- Produces: `App\Notifications\StrategyRevisedNotification(Strategy $strategy)`; `via()` → `['database']`; `toArray()` → `array{type:'strategy_revised', intention_id:int, strategy_id:int, change_reason:string, title:string, approach:string}`. Consumed by Task 4 (`$user->notify(...)`) and Task 5 (inbox payload).

- [ ] **Step 1: Write the failing test** — create `tests/Feature/Notifications/StrategyRevisedNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Models\Intention;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyRevisedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(): Strategy
    {
        $intention = Intention::factory()->create(['title' => 'Morning run']);

        return Strategy::factory()->stacked()->for($intention)->create([
            'approach' => 'Run 20 minutes after coffee.',
        ]);
    }

    public function test_via_uses_the_database_channel(): void
    {
        $notification = new StrategyRevisedNotification($this->strategy());

        $this->assertSame(['database'], $notification->via(new \stdClass));
    }

    public function test_to_array_payload_describes_the_revision(): void
    {
        $strategy = $this->strategy();

        $payload = (new StrategyRevisedNotification($strategy))->toArray(new \stdClass);

        $this->assertSame('strategy_revised', $payload['type']);
        $this->assertSame($strategy->intention_id, $payload['intention_id']);
        $this->assertSame($strategy->id, $payload['strategy_id']);
        $this->assertSame(Strategy::REASON_STACKED_ON_SUCCESS, $payload['change_reason']);
        $this->assertSame('Morning run', $payload['title']);
        $this->assertSame('Run 20 minutes after coffee.', $payload['approach']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Notifications/StrategyRevisedNotificationTest.php`
Expected: FAIL — `Class "App\Notifications\StrategyRevisedNotification" not found`.

- [ ] **Step 3: Create the notification**

```php
<?php

namespace App\Notifications;

use App\Models\Strategy;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their habit's strategy was automatically revised by the coaching
 * closure (SP4). Delivered in-app via the database channel and surfaced in the
 * inbox alongside due cues — the `type` discriminator lets the inbox render it
 * distinctly. Email/push are future via() channels, as with ActionDueNotification.
 */
final class StrategyRevisedNotification extends Notification
{
    public function __construct(public readonly Strategy $strategy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type:string, intention_id:int, strategy_id:int, change_reason:string, title:string, approach:string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strategy_revised',
            'intention_id' => $this->strategy->intention_id,
            'strategy_id' => $this->strategy->id,
            'change_reason' => $this->strategy->change_reason,
            'title' => $this->strategy->intention->title,
            'approach' => $this->strategy->approach,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Notifications/StrategyRevisedNotificationTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/StrategyRevisedNotification.php tests/Feature/Notifications/StrategyRevisedNotificationTest.php
git commit -m "feat(sp4): StrategyRevisedNotification on the database channel

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: `OutcomeStreak` service (pure streak computation)

**Files:**
- Create: `app/Services/Coach/OutcomeStreak.php`
- Test: `tests/Feature/Coach/OutcomeStreakTest.php`

**Interfaces:**
- Produces: `App\Services\Coach\OutcomeStreak::forStrategy(Strategy $strategy): array` returning `array{0: ?string, 1: int, 2: ?string}` = `[outcome, runLength, latestFailureReason]`. `outcome` is `ActionLog::OUTCOME_FAILED` / `OUTCOME_COMPLETED` (never `skipped`) or `null` when there are no non-skip logs. `runLength` is the leading contiguous run of that outcome. The reason is the newest `failed` log's `reason` in the run, else `null`. Consumed by Task 4.

**Notes for the implementer:** the streak is scoped to the active strategy's own action-logs (`ActionLog` whose `action.strategy_id` equals the strategy id). `skipped` logs are dropped before measuring (they neither extend nor break a run). Order newest-first by `logged_at` then `id` (stable tie-break for logs sharing a timestamp in tests). The relations exist: `ActionLog::action()` (belongsTo) and `Action.strategy_id`.

- [ ] **Step 1: Write the failing test** — create `tests/Feature/Coach/OutcomeStreakTest.php`:

```php
<?php

namespace Tests\Feature\Coach;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\OutcomeStreak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeStreakTest extends TestCase
{
    use RefreshDatabase;

    private Strategy $strategy;
    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $intention = Intention::factory()->create();
        $this->strategy = Strategy::factory()->initial()->for($intention)->create();
        $this->action = Action::factory()
            ->for($intention)
            ->for($this->strategy)
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    /**
     * Append logs oldest-first; bump logged_at so order is deterministic.
     *
     * @param  array<int, array{0:string, 1?:?string}>  $outcomes  [outcome, reason?]
     */
    private function logs(array $outcomes): void
    {
        foreach ($outcomes as $i => [$outcome, $reason]) {
            ActionLog::factory()
                ->for($this->action)
                ->for($this->action->intention->user)
                ->create([
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'logged_at' => now()->addMinutes($i),
                ]);
        }
    }

    public function test_no_logs_returns_null_outcome(): void
    {
        $this->assertSame([null, 0, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_two_failures_counts_a_failure_run_with_latest_reason(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'First reason'],
            [ActionLog::OUTCOME_FAILED, 'Latest reason'],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 2, 'Latest reason'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_five_completions_counts_a_completion_run(): void
    {
        $this->logs(array_fill(0, 5, [ActionLog::OUTCOME_COMPLETED, null]));

        $this->assertSame([ActionLog::OUTCOME_COMPLETED, 5, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_skips_are_ignored_and_do_not_break_a_run(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'a'],
            [ActionLog::OUTCOME_SKIPPED, null],
            [ActionLog::OUTCOME_FAILED, 'b'],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 2, 'b'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_opposite_outcome_resets_the_run(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'old'],
            [ActionLog::OUTCOME_COMPLETED, null],
            [ActionLog::OUTCOME_FAILED, 'new'],
        ]);

        // Newest non-skip run is a single failure.
        $this->assertSame([ActionLog::OUTCOME_FAILED, 1, 'new'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_streak_is_scoped_to_the_given_strategy(): void
    {
        // Logs on a DIFFERENT strategy's action must not count.
        $other = Strategy::factory()->initial()->create();
        $otherAction = Action::factory()->for($other->intention)->for($other)->create();
        ActionLog::factory()->for($otherAction)->for($otherAction->intention->user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'logged_at' => now(),
        ]);

        $this->logs([[ActionLog::OUTCOME_COMPLETED, null]]);

        $this->assertSame([ActionLog::OUTCOME_COMPLETED, 1, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Coach/OutcomeStreakTest.php`
Expected: FAIL — `Class "App\Services\Coach\OutcomeStreak" not found`.

- [ ] **Step 3: Create the service**

```php
<?php

namespace App\Services\Coach;

use App\Models\ActionLog;
use App\Models\Strategy;

/**
 * Computes the leading run of one non-skip outcome on a strategy's own action
 * logs — the deterministic signal SP4's coaching closure uses to decide whether
 * to revise. `skipped` outcomes are removed before measuring (they neither extend
 * nor break a run); an opposite outcome breaks it. Pure read; no side effects.
 */
final class OutcomeStreak
{
    /**
     * @return array{0: ?string, 1: int, 2: ?string}  [outcome, runLength, latestFailureReason]
     */
    public function forStrategy(Strategy $strategy): array
    {
        $logs = ActionLog::query()
            ->whereHas('action', static fn ($query) => $query->where('strategy_id', $strategy->id))
            ->where('outcome', '!=', ActionLog::OUTCOME_SKIPPED)
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->get(['id', 'outcome', 'reason', 'logged_at', 'action_id']);

        $leading = $logs->first()?->outcome;

        if ($leading === null) {
            return [null, 0, null];
        }

        $run = 0;
        $reason = null;

        foreach ($logs as $log) {
            if ($log->outcome !== $leading) {
                break;
            }

            $run++;

            if ($leading === ActionLog::OUTCOME_FAILED && $reason === null && $log->reason !== null && $log->reason !== '') {
                $reason = $log->reason;
            }
        }

        return [$leading, $run, $reason];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Coach/OutcomeStreakTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Coach/OutcomeStreak.php tests/Feature/Coach/OutcomeStreakTest.php
git commit -m "feat(sp4): OutcomeStreak service for deterministic streak detection

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: config + `RunCoachingClosure` listener

**Files:**
- Modify: `config/services.php`
- Create: `app/Listeners/RunCoachingClosure.php`
- Test: `tests/Feature/Coach/RunCoachingClosureTest.php`

**Interfaces:**
- Consumes: `ActionLogged` (Task 1), `OutcomeStreak::forStrategy` (Task 3), `StrategyRevisedNotification` (Task 2), and the existing `UpdateRollingSummary::handle(Intention): ?Summary` + `ReviseStrategy::restrategizeOnFailure(Strategy, string): Strategy` / `stackOnSuccess(Strategy): Strategy`.
- Produces: `App\Listeners\RunCoachingClosure` (`ShouldQueue`, `$afterCommit = true`, `$tries = 3`), auto-discovered for `ActionLogged`.

- [ ] **Step 1: Add the config keys** — in `config/services.php`, inside the existing `'coach' => [ … ]` array, add (keep the existing keys):

```php
        // SP4 auto-coaching closure: consecutive-outcome thresholds on the active
        // strategy that trigger an automatic revision. Skips are ignored.
        'fail_streak' => (int) env('COACH_FAIL_STREAK', 2),
        'stack_streak' => (int) env('COACH_STACK_STREAK', 5),
```

- [ ] **Step 2: Write the failing test** — create `tests/Feature/Coach/RunCoachingClosureTest.php`:

```php
<?php

namespace Tests\Feature\Coach;

use App\Actions\ReviseStrategy;
use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use App\Events\ActionLogged;
use App\Listeners\RunCoachingClosure;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use App\Services\Coach\Exceptions\CoachQuotaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RunCoachingClosureTest extends TestCase
{
    use RefreshDatabase;

    private Intention $intention;
    private Strategy $strategy;
    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intention = Intention::factory()->create();
        $this->strategy = Strategy::factory()->initial()->for($this->intention)->create([
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Walk 15 minutes after coffee.',
        ]);
        $this->action = Action::factory()
            ->for($this->intention)
            ->for($this->strategy)
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    /** @param array<int, array{0:string, 1?:?string}> $outcomes */
    private function logs(array $outcomes): ActionLog
    {
        $last = null;
        foreach ($outcomes as $i => [$outcome, $reason]) {
            $last = ActionLog::factory()->for($this->action)->for($this->intention->user)->create([
                'outcome' => $outcome,
                'reason' => $reason ?? null,
                'logged_at' => now()->addMinutes($i),
            ]);
        }

        return $last;
    }

    private function fire(ActionLog $log): void
    {
        app(RunCoachingClosure::class)->handle(
            new ActionLogged($this->intention->user, $this->action->fresh(), $log),
        );
    }

    private function strategyRevision(string $point, string $approach): array
    {
        return ['intervention_point' => $point, 'approach' => $approach, 'rationale' => 'Because.'];
    }

    public function test_two_failures_restrategize_the_active_strategy(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'Two misses.', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_CUE, 'Lay shoes out the night before.')]);

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'Too tired'],
            [ActionLog::OUTCOME_FAILED, 'Got home late'],
        ]);

        $this->fire($log);

        $this->assertSame(2, $this->intention->strategies()->max('version'));
        $new = $this->intention->activeStrategy()->first();
        $this->assertSame(Strategy::REASON_RESTRATEGIZED_ON_FAILURE, $new->change_reason);
        $this->assertSame('Got home late', $this->strategy->fresh()->superseded_reason);
        Notification::assertSentTo($this->intention->user, StrategyRevisedNotification::class);
    }

    public function test_five_completions_stack_the_active_strategy(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'Five wins.', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_RESPONSE, 'Walk 25 minutes after coffee.')]);

        $log = $this->logs(array_fill(0, 5, [ActionLog::OUTCOME_COMPLETED, null]));

        $this->fire($log);

        $new = $this->intention->activeStrategy()->first();
        $this->assertSame(2, $new->version);
        $this->assertSame(Strategy::REASON_STACKED_ON_SUCCESS, $new->change_reason);
        Notification::assertSentTo($this->intention->user, StrategyRevisedNotification::class);
    }

    public function test_below_threshold_updates_summary_but_does_not_revise(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'One miss so far.', 'patterns' => []]]);
        Strategist::fake([]);

        $log = $this->logs([[ActionLog::OUTCOME_FAILED, 'Just once']]);

        $this->fire($log);

        Strategist::assertNeverPrompted();
        $this->assertSame(1, $this->intention->strategies()->count());
        $this->assertSame(1, $this->intention->summaries()->count());
        Notification::assertNothingSent();
    }

    public function test_skipped_alone_never_revises(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'A skip.', 'patterns' => []]]);
        Strategist::fake([]);

        $log = $this->logs([[ActionLog::OUTCOME_SKIPPED, null]]);

        $this->fire($log);

        Strategist::assertNeverPrompted();
        Notification::assertNothingSent();
    }

    public function test_revision_is_idempotent_on_a_second_run(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'a', 'patterns' => []], ['content' => 'b', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_CUE, 'Lay shoes out.')]);

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'one'],
            [ActionLog::OUTCOME_FAILED, 'two'],
        ]);

        $this->fire($log);
        $this->fire($log); // second delivery

        // Exactly one new version; the new active strategy has no logs so it does not re-revise.
        $this->assertSame(2, $this->intention->strategies()->max('version'));
    }

    public function test_quota_exhaustion_is_swallowed_and_skips_revision(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'x', 'patterns' => []]]);

        $this->mock(ReviseStrategy::class, function ($mock): void {
            $mock->shouldReceive('restrategizeOnFailure')
                ->andThrow(CoachQuotaException::dailyTokenBudget($this->intention->user, 200000, 200001));
        });

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'one'],
            [ActionLog::OUTCOME_FAILED, 'two'],
        ]);

        $this->fire($log); // must not throw

        $this->assertSame(1, $this->intention->strategies()->count());
        Notification::assertNothingSent();
    }

    public function test_listener_is_registered_for_the_event(): void
    {
        Event::fake();

        Event::assertListening(ActionLogged::class, RunCoachingClosure::class);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Coach/RunCoachingClosureTest.php`
Expected: FAIL — `Class "App\Listeners\RunCoachingClosure" not found`.

- [ ] **Step 4: Create the listener**

```php
<?php

namespace App\Listeners;

use App\Actions\ReviseStrategy;
use App\Actions\UpdateRollingSummary;
use App\Events\ActionLogged;
use App\Models\ActionLog;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\OutcomeStreak;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SP4 — the auto-coaching closure. When an action is logged, this queued listener
 * runs the LLM-bearing coaching pass off the request: it always refolds the loop's
 * rolling summary (a no-op when nothing new), then, on a deterministic outcome
 * streak, revises the active strategy and notifies the owner. Failures here never
 * affect the already-committed log.
 */
final class RunCoachingClosure implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly UpdateRollingSummary $updateSummary,
        private readonly ReviseStrategy $reviseStrategy,
        private readonly OutcomeStreak $streak,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ActionLogged $event): void
    {
        $intention = $event->action->intention;

        // Serialize coaching per loop so a double-delivered job never double-spends
        // LLM tokens; the work itself is idempotent if the lock cannot be held.
        Cache::lock("coaching:intention:{$intention->id}", 30)->block(5, function () use ($intention): void {
            $this->updateSummary->handle($intention);

            $active = $intention->activeStrategy()->first();

            if ($active === null) {
                return;
            }

            [$outcome, $run, $reason] = $this->streak->forStrategy($active);

            try {
                $revised = $this->reviseFor($active, $outcome, $run, $reason);
            } catch (StrategyTransitionException|CoachQuotaException $e) {
                // Already superseded by a concurrent run, or over budget — skip.
                // The streak persists, so the next qualifying log retries.
                Log::info('Coaching closure skipped revision: '.$e->getMessage(), [
                    'intention_id' => $intention->id,
                ]);

                return;
            }

            if ($revised !== null) {
                $intention->user->notify(new StrategyRevisedNotification($revised));
            }
        });
    }

    /**
     * @throws StrategyTransitionException
     * @throws CoachQuotaException
     */
    private function reviseFor(Strategy $active, ?string $outcome, int $run, ?string $reason): ?Strategy
    {
        if ($outcome === ActionLog::OUTCOME_FAILED && $run >= (int) config('services.coach.fail_streak', 2)) {
            return $this->reviseStrategy->restrategizeOnFailure($active, $reason ?? '');
        }

        if ($outcome === ActionLog::OUTCOME_COMPLETED && $run >= (int) config('services.coach.stack_streak', 5)) {
            return $this->reviseStrategy->stackOnSuccess($active);
        }

        return null;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Coach/RunCoachingClosureTest.php`
Expected: PASS (7 tests). If `test_listener_is_registered_for_the_event` fails, confirm event auto-discovery is on (it is — `app/Listeners/SendDueNotification.php` is discovered the same way); the listener only needs to type-hint `ActionLogged` in `handle()`.

- [ ] **Step 6: Verify discovery from the CLI**

Run: `php artisan event:list | grep -i ActionLogged`
Expected: shows `App\Events\ActionLogged … App\Listeners\RunCoachingClosure@handle`.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php app/Listeners/RunCoachingClosure.php tests/Feature/Coach/RunCoachingClosureTest.php
git commit -m "feat(sp4): RunCoachingClosure listener wires log -> summary + revision

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Inbox renders the revision cue

**Files:**
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `resources/js/patyourself/types.ts`
- Modify: `resources/js/pages/inbox.tsx`
- Test: `tests/Feature/Inbox/InboxControllerTest.php` (PHP), `resources/js/pages/inbox.test.tsx` (vitest)

**Interfaces:**
- Consumes: `StrategyRevisedNotification` payload (Task 2): `type`, `change_reason`, `approach`, `intention_id`, `title`.
- Produces: each inbox row gains `type` (`'action_due'` default), `change_reason`, `approach`. `inbox.tsx` renders a `strategy_revised` row distinctly; a missing `type` still renders as a due cue.

- [ ] **Step 1: Write the failing PHP test** — append to `tests/Feature/Inbox/InboxControllerTest.php` (reuse its existing `setUp`/helpers; add imports `use App\Models\Strategy;` and `use App\Notifications\StrategyRevisedNotification;` if absent):

```php
public function test_index_maps_strategy_revised_notification_fields(): void
{
    $user = User::factory()->create();
    $strategy = Strategy::factory()->stacked()
        ->for(Intention::factory()->for($user)->create(['title' => 'Evening reading']))
        ->create(['approach' => 'Read 10 pages before bed.']);

    $user->notify(new StrategyRevisedNotification($strategy));

    $this->actingAs($user)
        ->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inbox')
            ->where('notifications.0.type', 'strategy_revised')
            ->where('notifications.0.change_reason', Strategy::REASON_STACKED_ON_SUCCESS)
            ->where('notifications.0.approach', 'Read 10 pages before bed.')
            ->where('notifications.0.intention_id', $strategy->intention_id)
        );
}

public function test_index_defaults_type_to_action_due_for_legacy_cues(): void
{
    $user = User::factory()->create();
    $action = Action::factory()->for(Intention::factory()->for($user))->create();
    $user->notify(new ActionDueNotification($action));

    $this->actingAs($user)
        ->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('notifications.0.type', 'action_due'));
}
```

(If `InboxControllerTest` does not already import `Action`, `Intention`, `User`, `ActionDueNotification`, add those imports too.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='strategy_revised|defaults_type'`
Expected: FAIL — `notifications.0.type` does not exist / null.

- [ ] **Step 3: Map the new fields in `InboxController::index`** — replace the `->map(...)` callback body so each row is:

```php
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->data['type'] ?? 'action_due',
                'action_id' => $notification->data['action_id'] ?? null,
                'intention_id' => $notification->data['intention_id'] ?? null,
                'title' => $notification->data['title'] ?? null,
                'fired_at' => $notification->data['fired_at'] ?? null,
                'change_reason' => $notification->data['change_reason'] ?? null,
                'approach' => $notification->data['approach'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
            ])
```

- [ ] **Step 4: Run the PHP test to verify it passes**

Run: `php artisan test --compact tests/Feature/Inbox/InboxControllerTest.php`
Expected: PASS (new tests + existing inbox tests).

- [ ] **Step 5: Widen the TS type** — in `resources/js/patyourself/types.ts`, replace the `NotificationData` interface with:

```ts
export interface NotificationData {
    id: string;
    type?: 'action_due' | 'strategy_revised';
    action_id: number | null;
    intention_id: number | null;
    title: string | null;
    fired_at: string | null;
    change_reason?: string | null;
    approach?: string | null;
    read_at: string | null;
}
```

- [ ] **Step 6: Write the failing vitest** — append to `resources/js/pages/inbox.test.tsx` (follow the file's existing render/mocks helpers; build a notification object inline):

```tsx
it('renders a strategy_revised cue with its new approach and links to the loop', () => {
    render(
        <Inbox
            notifications={[
                {
                    id: 'n-rev',
                    type: 'strategy_revised',
                    action_id: null,
                    intention_id: 7,
                    title: 'Morning run',
                    fired_at: null,
                    change_reason: 'stacked_on_success',
                    approach: 'Run 25 minutes after coffee.',
                    read_at: null,
                },
            ]}
        />,
    );

    expect(screen.getByText(/plan updated/i)).toBeInTheDocument();
    expect(
        screen.getByText('Run 25 minutes after coffee.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('link')).toHaveAttribute(
        'href',
        '/intentions/7',
    );
});

it('still renders a cue with no type as a due cue', () => {
    render(
        <Inbox
            notifications={[
                {
                    id: 'n-due',
                    action_id: 1,
                    intention_id: 2,
                    title: 'Meditate',
                    fired_at: null,
                    read_at: null,
                },
            ]}
        />,
    );

    expect(screen.getByText(/due now/i)).toBeInTheDocument();
});
```

- [ ] **Step 7: Run vitest to verify it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/inbox.test.tsx`
Expected: FAIL — no "plan updated" text (inbox.tsx renders "due now" for all rows).

- [ ] **Step 8: Render the revision row** — in `resources/js/pages/inbox.tsx`, replace the `InboxItem` `content` definition (lines that build the `<>` fragment) with a type-aware version:

```tsx
    const isRevision = notification.type === 'strategy_revised';

    const content = (
        <>
            {unread && (
                <span
                    data-testid="unread-dot"
                    aria-label="Unread"
                    className="size-2 shrink-0 rounded-full bg-primary"
                />
            )}
            <span className="flex flex-1 flex-col gap-0.5">
                <span
                    className={cn(
                        'text-sm text-foreground',
                        unread && 'font-medium',
                    )}
                >
                    {isRevision
                        ? `${notification.title ?? 'Your plan'} — plan updated`
                        : `${notification.title ?? 'Action'} — due now`}
                </span>
                {isRevision && notification.approach && (
                    <span className="text-xs text-muted-foreground">
                        {notification.approach}
                    </span>
                )}
            </span>
            {!isRevision && (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {formatFiredAt(notification.fired_at)}
                </span>
            )}
        </>
    );
```

- [ ] **Step 9: Run vitest + tsc to verify they pass**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/inbox.test.tsx`
Expected: PASS (new tests + existing inbox tests).
Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx tsc --noEmit`
Expected: no output (clean).

- [ ] **Step 10: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/pages/inbox.tsx resources/js/pages/inbox.test.tsx resources/js/patyourself/types.ts
git add app/Http/Controllers/InboxController.php resources/js/patyourself/types.ts resources/js/pages/inbox.tsx resources/js/pages/inbox.test.tsx tests/Feature/Inbox/InboxControllerTest.php
git commit -m "feat(sp4): render strategy-revised cue in the inbox

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification

- [ ] **Full PHP suite:** `php artisan test --compact` — all green (expect +~15 new tests over the current 266).
- [ ] **Full JS suite:** `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run` — all green.
- [ ] **Types + lint:** `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx tsc --noEmit` and `npx eslint resources/js/pages/inbox.tsx resources/js/patyourself/types.ts` — clean.
- [ ] **Pint:** `vendor/bin/pint --dirty --format agent` — passed.
- [ ] **Event wiring:** `php artisan event:list | grep ActionLogged` — listener present.

## Success criteria (from the spec)

1. Logging refolds the rolling summary (`UpdateRollingSummary` no longer orphaned) — Tasks 4.
2. 2 consecutive failures restrategize; 5 consecutive successes stack — Task 4.
3. `skipped` never counts toward/breaks a streak; below-threshold does not revise — Tasks 3, 4.
4. Every revision yields a per-user `StrategyRevisedNotification` in `/inbox` + badge; SP3 due cues still render — Tasks 2, 5.
5. The pass runs off-request in a queued, after-commit listener; logging stays fast and always succeeds — Tasks 1, 4.
6. Idempotent: re-delivery/concurrency never double-revises; streak resets on the new active strategy — Tasks 3, 4.
7. All new/affected tests pass; Pint + types + lint clean — Final verification.
