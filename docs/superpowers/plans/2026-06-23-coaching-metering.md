# SP6 — Meter the Background Coaching Path — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Attribute the queued Strategist/Summarizer LLM calls to the loop owner so the existing `GuardCoachUsage` middleware meters and caps them, skip the auto-coaching pass gracefully when the owner is over budget, and surface today's per-user usage on the `/progress` dashboard.

**Architecture:** A small `MetersUsageToUser` trait gives `Strategist`/`Summarizer` a `forUser()`/`conversationParticipant()` pair, which the existing `GuardCoachUsage` middleware already resolves on the session-less path. The two coaching actions pass the loop owner when prompting; the queued listener's quota-skip is widened to cover the whole pass; the cost guard gains a read-only `snapshotFor()` (shared via a container binding) that feeds a new usage card on the progress index.

**Tech Stack:** Laravel 13, PHP 8.4, `laravel/ai` v0.7, Inertia v3 + React 19, Tailwind v4, PHPUnit 12, vitest.

## Global Constraints

- App is served by Herd at `https://patyourself.test` — NEVER run `php artisan serve`; only `npm run dev` for Vite.
- `config/inertia.php` has `testing.ensure_pages_exist => true`: a controller test asserting `->component('x')` needs `resources/js/pages/x.tsx` to exist. `progress/index.tsx` already exists, so no stub is needed here.
- DB-backed tests go in `tests/Feature`; `tests/Unit` is for pure no-DB tests only.
- After editing PHP: `vendor/bin/pint --dirty --format agent` (never `--test`).
- Never run whole-tree formatters. Format only touched JS/TS via `npx prettier --write <files>`, and run `npx eslint <files>` on touched files before claiming lint-clean (vitest does NOT catch `consistent-type-imports`).
- Frontend tooling needs Node 22: prefix npm/npx with `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"`.
- `tsc` has pre-existing Wayfinder `@/routes`/`@/actions` codegen errors; ignore those — only new errors in touched files matter.
- Build assets (`npm run build`) before running the full PHP suite or page-render feature tests, or they ViteManifestNotFound. (Inertia `assertInertia` requests return JSON and do NOT need a manifest.)
- Commit trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Run the minimum tests needed: `php artisan test --compact --filter=...` per task.

## Files

- Create: `app/Ai/Concerns/MetersUsageToUser.php` — `forUser()`/`conversationParticipant()` for non-conversational agents.
- Modify: `app/Ai/Agents/Strategist.php`, `app/Ai/Agents/Summarizer.php` — `use MetersUsageToUser`.
- Modify: `app/Actions/UpdateRollingSummary.php` — attribute the owner on the Summarizer call.
- Modify: `app/Actions/ReviseStrategy.php` — attribute the owner on the Strategist call.
- Modify: `app/Listeners/RunCoachingClosure.php` — widen the `CoachQuotaException` skip to the whole pass.
- Modify: `app/Services/Coach/Usage/CoachUsageGuard.php` — add `snapshotFor()`.
- Modify: `app/Ai/Middleware/GuardCoachUsage.php` — inject `CoachUsageGuard` instead of building it inline.
- Modify: `app/Providers/AppServiceProvider.php` — bind `CoachUsageGuard` from config.
- Modify: `app/Http/Controllers/ProgressController.php` — pass a `usage` prop on `index`.
- Create: `resources/js/patyourself/progress/coach-usage-card.tsx` — the usage card.
- Modify: `resources/js/patyourself/types.ts` — add `CoachUsageSnapshot`.
- Modify: `resources/js/pages/progress/index.tsx` — render the card.
- Test: `tests/Unit/Ai/MetersUsageToUserTest.php`, `tests/Feature/Ai/GuardCoachUsageTest.php`, `tests/Feature/Coach/AttributesCoachingUsageTest.php`, `tests/Feature/Coach/RunCoachingClosureTest.php`, `tests/Feature/Coach/CoachUsageGuardTest.php`, `tests/Feature/Progress/ProgressUsageTest.php`, `resources/js/patyourself/progress/coach-usage-card.test.tsx`, `resources/js/pages/progress/index.test.tsx`.

---

### Task 1: `MetersUsageToUser` trait + apply to Strategist/Summarizer

**Files:**
- Create: `app/Ai/Concerns/MetersUsageToUser.php`
- Modify: `app/Ai/Agents/Strategist.php`, `app/Ai/Agents/Summarizer.php`
- Test: `tests/Unit/Ai/MetersUsageToUserTest.php`

**Interfaces:**
- Produces: `MetersUsageToUser::forUser(User $user): static`, `MetersUsageToUser::conversationParticipant(): ?User`. Applied to `Strategist` and `Summarizer` so `GuardCoachUsage`'s `method_exists($agent, 'conversationParticipant')` branch resolves the owner.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Ai/MetersUsageToUserTest.php`. Pure (no DB), but extends `Tests\TestCase` because it instantiates Eloquent models:

```php
<?php

namespace Tests\Unit\Ai;

use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use App\Models\User;
use Tests\TestCase;

class MetersUsageToUserTest extends TestCase
{
    public function test_strategist_carries_the_attributed_user(): void
    {
        $user = new User(['id' => 1]);
        $agent = new Strategist;

        $returned = $agent->forUser($user);

        $this->assertSame($agent, $returned, 'forUser should return the agent for chaining');
        $this->assertSame($user, $agent->conversationParticipant());
    }

    public function test_summarizer_carries_the_attributed_user(): void
    {
        $user = new User(['id' => 2]);
        $agent = (new Summarizer)->forUser($user);

        $this->assertSame($user, $agent->conversationParticipant());
    }

    public function test_participant_is_null_until_attributed(): void
    {
        $this->assertNull((new Strategist)->conversationParticipant());
        $this->assertNull((new Summarizer)->conversationParticipant());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/Ai/MetersUsageToUserTest.php`
Expected: FAIL — `Call to undefined method App\Ai\Agents\Strategist::forUser()`.

- [ ] **Step 3: Create the trait**

Create `app/Ai/Concerns/MetersUsageToUser.php`:

```php
<?php

namespace App\Ai\Concerns;

use App\Models\User;

/**
 * Attributes a non-conversational agent's LLM spend to a specific user.
 *
 * The GuardCoachUsage middleware bills the authenticated user, falling back to
 * the agent's conversationParticipant() when there is no HTTP session (the
 * queued coaching path). Strategist/Summarizer have no conversation memory, so
 * this trait supplies just the participant hook the guard needs — letting the
 * background coaching pass be metered to the loop owner without pulling in the
 * full RemembersConversations machinery.
 */
trait MetersUsageToUser
{
    protected ?User $billedUser = null;

    /**
     * Attribute this agent's usage to the given user.
     */
    public function forUser(User $user): static
    {
        $this->billedUser = $user;

        return $this;
    }

    /**
     * The user GuardCoachUsage should bill — null until forUser() is called.
     */
    public function conversationParticipant(): ?User
    {
        return $this->billedUser;
    }
}
```

- [ ] **Step 4: Apply the trait to both agents**

In `app/Ai/Agents/Strategist.php`, add the import and use the trait alongside `Promptable`:

```php
use App\Ai\Concerns\MetersUsageToUser;
use App\Ai\Middleware\GuardCoachUsage;
```

```php
class Strategist implements Agent, HasMiddleware, HasStructuredOutput
{
    use MetersUsageToUser, Promptable;
```

In `app/Ai/Agents/Summarizer.php`, the same:

```php
use App\Ai\Concerns\MetersUsageToUser;
use App\Ai\Middleware\GuardCoachUsage;
```

```php
class Summarizer implements Agent, HasMiddleware, HasStructuredOutput
{
    use MetersUsageToUser, Promptable;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Unit/Ai/MetersUsageToUserTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Ai/Concerns/MetersUsageToUser.php app/Ai/Agents/Strategist.php app/Ai/Agents/Summarizer.php tests/Unit/Ai/MetersUsageToUserTest.php
git commit -m "feat(sp6): attribute Strategist/Summarizer usage to a billed user

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: GuardCoachUsage meters a participant-attributed agent on the session-less path

**Files:**
- Test: `tests/Feature/Ai/GuardCoachUsageTest.php` (add cases + one stub agent)

**Interfaces:**
- Consumes: `MetersUsageToUser` (Task 1). Proves the existing middleware records and caps when the agent carries a `conversationParticipant()` but there is no authenticated user — the core "queued path is now metered" guarantee.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Ai/GuardCoachUsageTest.php`, add an attributed stub agent next to the existing inline stubs at the bottom of the file:

```php
/** An agent that carries an explicitly billed user, like Strategist/Summarizer on the queued path. */
final class StubAttributed implements Agent
{
    use \App\Ai\Concerns\MetersUsageToUser, Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}
```

Add these test methods to the class:

```php
public function test_records_usage_for_the_billed_user_when_unauthenticated(): void
{
    // No actingAs — the session-less queued path.
    $user = User::factory()->create();
    $prompt = $this->agentPrompt((new StubAttributed)->forUser($user));

    $result = $this->middleware(200000)->handle($prompt, $this->respond());

    $this->assertSame('ok', $result->text);
    $this->assertDatabaseHas('coach_usages', [
        'user_id' => $user->id,
        'purpose' => 'stubattributed',
        'prompt_tokens' => 80,
        'completion_tokens' => 20,
        'total_tokens' => 100,
    ]);
}

public function test_rejects_an_over_budget_billed_user_when_unauthenticated(): void
{
    $user = User::factory()->create();
    (new CoachUsageGuard(100))->record($user, 'fake', 100, 0, 'summarizer');

    $called = false;

    $this->expectException(CoachQuotaException::class);

    try {
        $this->middleware(100)->handle(
            $this->agentPrompt((new StubAttributed)->forUser($user)),
            function () use (&$called) {
                $called = true;
            },
        );
    } finally {
        $this->assertFalse($called);
    }
}
```

- [ ] **Step 2: Run to verify it passes (already-present capability)**

Run: `php artisan test --compact tests/Feature/Ai/GuardCoachUsageTest.php`
Expected: PASS. The middleware already falls back to `conversationParticipant()`; Task 1 made `StubAttributed` expose it. This task is the regression proof that the queued path meters and caps.

If either new test FAILS, stop — the resolution path is broken and later tasks depend on it.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Ai/GuardCoachUsageTest.php
git commit -m "test(sp6): GuardCoachUsage meters a billed participant on the session-less path

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Attribute the loop owner in UpdateRollingSummary + ReviseStrategy

**Files:**
- Modify: `app/Actions/UpdateRollingSummary.php:50`, `app/Actions/ReviseStrategy.php:98`
- Test: `tests/Feature/Coach/AttributesCoachingUsageTest.php`

**Interfaces:**
- Consumes: `Strategist::forUser()`, `Summarizer::forUser()` (Task 1); `AgentPrompt->agent->conversationParticipant()` exposed to `assertPrompted` by the fake gateway (it records the full prompt, agent included).
- Produces: both coaching actions attribute their LLM call to `$intention->user` (the loop owner).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Coach/AttributesCoachingUsageTest.php`:

```php
<?php

namespace Tests\Feature\Coach;

use App\Actions\ReviseStrategy;
use App\Actions\UpdateRollingSummary;
use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributesCoachingUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_rolling_summary_bills_the_loop_owner(): void
    {
        Summarizer::fake([['content' => 'A pattern.', 'patterns' => []]]);

        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->for($strategy)->create();
        ActionLog::factory()->for($action)->for($intention->user)->create([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'logged_at' => now(),
        ]);

        app(UpdateRollingSummary::class)->handle($intention);

        Summarizer::assertPrompted(
            fn ($prompt) => $prompt->agent->conversationParticipant()?->is($intention->user) === true,
        );
    }

    public function test_revise_strategy_bills_the_loop_owner(): void
    {
        Strategist::fake([[
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay shoes out the night before.',
            'rationale' => 'Because.',
        ]]);

        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create([
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Walk 15 minutes after coffee.',
        ]);

        app(ReviseStrategy::class)->restrategizeOnFailure($strategy, 'Too tired');

        Strategist::assertPrompted(
            fn ($prompt) => $prompt->agent->conversationParticipant()?->is($intention->user) === true,
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Coach/AttributesCoachingUsageTest.php`
Expected: FAIL — `conversationParticipant()` returns `null` (the actions don't call `forUser()` yet), so the truth test is false: "An expected prompt was not received."

- [ ] **Step 3: Attribute the owner in UpdateRollingSummary**

In `app/Actions/UpdateRollingSummary.php`, change the Summarizer call (currently line 50):

```php
$response = (new Summarizer)->forUser($intention->user)->prompt($userPrompt);
```

- [ ] **Step 4: Attribute the owner in ReviseStrategy**

In `app/Actions/ReviseStrategy.php`, change the Strategist call inside `revise()` (currently line 98). The loop owner is reachable from the strategy being revised:

```php
$response = (new Strategist)->forUser($current->intention->user)->prompt($userPrompt);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --compact tests/Feature/Coach/AttributesCoachingUsageTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/UpdateRollingSummary.php app/Actions/ReviseStrategy.php tests/Feature/Coach/AttributesCoachingUsageTest.php
git commit -m "feat(sp6): attribute auto-coaching LLM calls to the loop owner

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Widen the quota-skip to the whole coaching pass

**Files:**
- Modify: `app/Listeners/RunCoachingClosure.php:45-79`
- Test: `tests/Feature/Coach/RunCoachingClosureTest.php` (add one case)

**Interfaces:**
- Consumes: `CoachQuotaException` (already imported in the listener).
- Produces: an over-budget owner skips the entire pass (summary AND revision) without bubbling, failing the job, or writing partial state.

**Context:** Today the `CoachQuotaException` catch wraps only `reviseFor()`. The `updateSummary->handle()` call sits outside it. Once the Summarizer is metered (Task 3), an over-budget owner makes that call throw, which would bubble out of the queued job and retry 3× against a budget that will not free up in 10/30/60s. Widen the catch to the whole pass.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Coach/RunCoachingClosureTest.php`:

```php
public function test_over_budget_on_the_summary_skips_the_entire_pass(): void
{
    Notification::fake();

    // The summary's Summarizer call trips the budget guard before any revision.
    Summarizer::fake(function (): never {
        throw CoachQuotaException::dailyTokenBudget($this->intention->user, 200000, 200001);
    });
    Strategist::fake([]);

    $log = $this->logs([
        [ActionLog::OUTCOME_FAILED, 'one'],
        [ActionLog::OUTCOME_FAILED, 'two'],
    ]);

    $this->fire($log); // must not throw

    $this->assertSame(0, $this->intention->summaries()->count());
    $this->assertSame(1, $this->intention->strategies()->count());
    Strategist::assertNeverPrompted();
    Notification::assertNothingSent();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=test_over_budget_on_the_summary_skips_the_entire_pass`
Expected: FAIL — the `CoachQuotaException` from the summary call escapes `handle()` and the test errors with an unhandled exception.

- [ ] **Step 3: Widen the catch**

Replace the body of `handle()` in `app/Listeners/RunCoachingClosure.php` with a whole-pass `CoachQuotaException` guard (the `StrategyTransitionException` skip stays scoped to the revision):

```php
public function handle(ActionLogged $event): void
{
    $intention = $event->action->intention;

    // Serialize coaching per loop so a double-delivered job never double-spends
    // LLM tokens; the 75s TTL outlives one coaching pass (LLM timeout is 60s) so
    // the lock isn't released mid-call. The work is idempotent if it cannot be held.
    Cache::lock("coaching:intention:{$intention->id}", 75)->block(5, function () use ($intention): void {
        try {
            $this->updateSummary->handle($intention);

            $active = $intention->activeStrategy()->first();

            if ($active === null) {
                return;
            }

            [$outcome, $run, $reason] = $this->streak->forStrategy($active);

            try {
                $revised = $this->reviseFor($active, $outcome, $run, $reason);
            } catch (StrategyTransitionException $e) {
                // Already superseded by a concurrent run — skip the revision.
                // The streak persists, so the next qualifying log retries.
                Log::info('Coaching closure skipped revision: '.$e->getMessage(), [
                    'intention_id' => $intention->id,
                ]);

                return;
            }

            if ($revised !== null) {
                $intention->user->notify(new StrategyRevisedNotification($revised));
            }
        } catch (CoachQuotaException $e) {
            // The loop owner is over budget — skip the whole pass (summary and
            // revision). The streak persists, so the next qualifying log retries
            // once the rolling-24h window frees.
            Log::info('Coaching closure skipped (over budget): '.$e->getMessage(), [
                'intention_id' => $intention->id,
            ]);
        }
    });
}
```

- [ ] **Step 4: Run the whole closure suite to verify it passes**

Run: `php artisan test --compact tests/Feature/Coach/RunCoachingClosureTest.php`
Expected: PASS — the new test passes and all existing cases (including `test_quota_exhaustion_is_swallowed_and_skips_revision`, where the Strategist throws and the outer catch now handles it) stay green.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Listeners/RunCoachingClosure.php tests/Feature/Coach/RunCoachingClosureTest.php
git commit -m "fix(sp6): skip the whole coaching pass when the owner is over budget

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: `CoachUsageGuard::snapshotFor()` + container binding

**Files:**
- Modify: `app/Services/Coach/Usage/CoachUsageGuard.php`
- Modify: `app/Ai/Middleware/GuardCoachUsage.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Coach/CoachUsageGuardTest.php` (add cases)

**Interfaces:**
- Produces: `CoachUsageGuard::snapshotFor(User $user): array{used:int, budget:int, remaining:?int, breakdown:array<string,int>}`. `breakdown` maps `purpose` → today's `total_tokens` (null purpose keyed as `other`). `remaining` is `null` when uncapped. `CoachUsageGuard` is now resolvable from the container (config budget), consumed by `GuardCoachUsage` (Task 5) and `ProgressController` (Task 6).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Coach/CoachUsageGuardTest.php`:

```php
public function test_snapshot_reports_used_budget_remaining_and_breakdown(): void
{
    $user = User::factory()->create();
    $guard = new CoachUsageGuard(dailyTokenBudget: 1000);

    $guard->record($user, 'claude-haiku-4-5', 100, 50, 'summarizer'); // 150
    $guard->record($user, 'claude-haiku-4-5', 40, 10, 'strategist');   // 50
    $guard->record($user, 'claude-sonnet-4-6', 100, 100, 'coach');     // 200

    // An older call outside the window must not count.
    $old = $guard->record($user, 'claude-haiku-4-5', 500, 500, 'summarizer');
    $old->forceFill(['created_at' => Date::now()->subDays(2)])->save();

    $snapshot = $guard->snapshotFor($user);

    $this->assertSame(400, $snapshot['used']);
    $this->assertSame(1000, $snapshot['budget']);
    $this->assertSame(600, $snapshot['remaining']);
    $this->assertSame(150, $snapshot['breakdown']['summarizer']);
    $this->assertSame(50, $snapshot['breakdown']['strategist']);
    $this->assertSame(200, $snapshot['breakdown']['coach']);
}

public function test_snapshot_remaining_is_null_when_uncapped(): void
{
    $user = User::factory()->create();
    $guard = new CoachUsageGuard(dailyTokenBudget: 0);

    $guard->record($user, 'claude-haiku-4-5', 100, 100, 'summarizer');

    $snapshot = $guard->snapshotFor($user);

    $this->assertSame(200, $snapshot['used']);
    $this->assertSame(0, $snapshot['budget']);
    $this->assertNull($snapshot['remaining']);
}

public function test_snapshot_for_an_unused_account_is_zero(): void
{
    $user = User::factory()->create();
    $snapshot = (new CoachUsageGuard(1000))->snapshotFor($user);

    $this->assertSame(0, $snapshot['used']);
    $this->assertSame(1000, $snapshot['remaining']);
    $this->assertSame([], $snapshot['breakdown']);
}

public function test_guard_resolves_from_the_container_with_the_config_budget(): void
{
    config()->set('services.coach.daily_token_budget', 4242);

    $guard = $this->app->make(CoachUsageGuard::class);
    $user = User::factory()->create();

    $this->assertSame(4242, $guard->snapshotFor($user)['budget']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Coach/CoachUsageGuardTest.php`
Expected: FAIL — `Call to undefined method ...::snapshotFor()` and `CoachUsageGuard` not resolvable without args from the container.

- [ ] **Step 3: Add `snapshotFor()` to the guard**

In `app/Services/Coach/Usage/CoachUsageGuard.php`, add the import and method:

```php
use App\Models\CoachUsage;
```

```php
/**
 * Today's usage for the per-user card: total spent, the budget, remaining
 * (null when uncapped), and a per-purpose breakdown of the rolling window.
 *
 * @return array{used:int, budget:int, remaining:?int, breakdown:array<string,int>}
 */
public function snapshotFor(User $user): array
{
    $used = $this->tokensUsedToday($user);

    $breakdown = CoachUsage::query()
        ->where('user_id', $user->id)
        ->since(Date::now()->subDay())
        ->selectRaw('purpose, SUM(total_tokens) as tokens')
        ->groupBy('purpose')
        ->pluck('tokens', 'purpose')
        ->mapWithKeys(fn ($tokens, $purpose): array => [(string) ($purpose ?? 'other') => (int) $tokens])
        ->all();

    return [
        'used' => $used,
        'budget' => $this->dailyTokenBudget,
        'remaining' => $this->dailyTokenBudget > 0 ? max(0, $this->dailyTokenBudget - $used) : null,
        'breakdown' => $breakdown,
    ];
}
```

- [ ] **Step 4: Bind the guard in the container**

In `app/Providers/AppServiceProvider.php`, add the import and bind in `register()`:

```php
use App\Services\Coach\Usage\CoachUsageGuard;
```

```php
public function register(): void
{
    // Request-scoped collector; drained by ChatController after each coach turn.
    $this->app->scoped(TurnCollector::class);

    // The single cost guard, sourced from config so the middleware and the
    // progress dashboard share one construction. Bound (not singleton) so it
    // re-reads the budget per resolve — tests set it per case.
    $this->app->bind(
        CoachUsageGuard::class,
        fn (): CoachUsageGuard => new CoachUsageGuard((int) config('services.coach.daily_token_budget', 0)),
    );
}
```

- [ ] **Step 5: Resolve the guard in the middleware**

In `app/Ai/Middleware/GuardCoachUsage.php`, inject `CoachUsageGuard` and drop the inline `guard()` builder. Replace the constructor and the two guard usages:

```php
public function __construct(
    private readonly AuthFactory $auth,
    private readonly CoachUsageGuard $guard,
) {}
```

In `handle()`, use the injected guard and delete the private `guard()` method:

```php
$this->guard->ensureWithinBudget($user);

$purpose = strtolower(class_basename($prompt->agent));

// NOTE: if $next throws mid-tool-loop, partially accrued provider spend is
// not recorded (SDK exposes no usage on failure) — accepted, same as the old decorator.
return $next($prompt)->then(function ($response) use ($user, $purpose) {
    $this->guard->record(
        $user,
        $response->meta->model ?? 'unknown',
        $response->usage->promptTokens,
        $response->usage->completionTokens,
        $purpose,
    );
});
```

Remove the now-unused `private function guard(): CoachUsageGuard { ... }` method and its `config(...)` call.

- [ ] **Step 6: Run guard + middleware suites to verify they pass**

Run: `php artisan test --compact tests/Feature/Coach/CoachUsageGuardTest.php tests/Feature/Ai/GuardCoachUsageTest.php`
Expected: PASS — the new snapshot/binding tests pass and the existing middleware tests (which set `services.coach.daily_token_budget` before `$this->app->make(GuardCoachUsage::class)`) still resolve the correct budget through the bind.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Coach/Usage/CoachUsageGuard.php app/Ai/Middleware/GuardCoachUsage.php app/Providers/AppServiceProvider.php tests/Feature/Coach/CoachUsageGuardTest.php
git commit -m "feat(sp6): CoachUsageGuard::snapshotFor + shared container binding

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: ProgressController passes the usage snapshot

**Files:**
- Modify: `app/Http/Controllers/ProgressController.php:22-39`
- Test: `tests/Feature/Progress/ProgressUsageTest.php`

**Interfaces:**
- Consumes: `CoachUsageGuard::snapshotFor()` (Task 5).
- Produces: a `usage` prop on `progress/index` with `{used, budget, remaining, breakdown}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Progress/ProgressUsageTest.php`:

```php
<?php

namespace Tests\Feature\Progress;

use App\Models\User;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_includes_the_coach_usage_snapshot(): void
    {
        config()->set('services.coach.daily_token_budget', 200000);

        $user = User::factory()->create();
        (new CoachUsageGuard(200000))->record($user, 'claude-haiku-4-5', 100, 50, 'summarizer');

        $this->actingAs($user)
            ->get(route('progress'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/index')
                ->where('usage.used', 150)
                ->where('usage.budget', 200000)
                ->where('usage.remaining', 199850)
                ->where('usage.breakdown.summarizer', 150),
            );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Progress/ProgressUsageTest.php`
Expected: FAIL — `usage` prop is missing.

- [ ] **Step 3: Add the prop**

In `app/Http/Controllers/ProgressController.php`, add the import and inject the guard into `index`:

```php
use App\Services\Coach\Usage\CoachUsageGuard;
```

```php
public function index(Request $request, LoopProgress $progress, CoachUsageGuard $guard): Response
{
    $loops = $request->user()->intentions()
        ->active()
        ->with(['activeStrategy', 'latestSummary', 'actionLogs'])
        ->latest()
        ->get()
        ->map(fn (Intention $loop): array => [
            'id' => $loop->id,
            'title' => $loop->title,
            'type' => $loop->type,
            ...$progress->forLoop($loop),
            'summary_excerpt' => $this->excerpt($loop->latestSummary?->content),
        ])
        ->values();

    return Inertia::render('progress/index', [
        'loops' => $loops,
        'usage' => $guard->snapshotFor($request->user()),
    ]);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact tests/Feature/Progress/ProgressUsageTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ProgressController.php tests/Feature/Progress/ProgressUsageTest.php
git commit -m "feat(sp6): surface the coach usage snapshot on the progress index

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: CoachUsageCard component + render on the progress index

**Files:**
- Modify: `resources/js/patyourself/types.ts:114` (after `LoopProgressCard`)
- Create: `resources/js/patyourself/progress/coach-usage-card.tsx`
- Modify: `resources/js/pages/progress/index.tsx`
- Test: `resources/js/patyourself/progress/coach-usage-card.test.tsx`
- Modify: `resources/js/pages/progress/index.test.tsx` (pass the new required `usage` prop)

**Interfaces:**
- Consumes: the `usage` prop shape from Task 6.
- Produces: `CoachUsageSnapshot` type; `CoachUsageCard`; `ProgressIndexProps` gains `usage: CoachUsageSnapshot`.

- [ ] **Step 1: Write the failing card test**

Create `resources/js/patyourself/progress/coach-usage-card.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CoachUsageSnapshot } from '@/patyourself/types';
import { CoachUsageCard } from './coach-usage-card';

function usage(overrides: Partial<CoachUsageSnapshot> = {}): CoachUsageSnapshot {
    return {
        used: 1500,
        budget: 200000,
        remaining: 198500,
        breakdown: { summarizer: 900, strategist: 300, coach: 300 },
        ...overrides,
    };
}

describe('CoachUsageCard', () => {
    it('shows used over budget and the remaining tokens', () => {
        render(<CoachUsageCard usage={usage()} />);

        expect(screen.getByText('Coach usage today')).toBeInTheDocument();
        expect(screen.getByText('1,500 / 200,000')).toBeInTheDocument();
        expect(screen.getByText(/198,500 tokens remaining/)).toBeInTheDocument();
        expect(screen.getByTestId('usage-bar')).toBeInTheDocument();
    });

    it('groups the breakdown into auto-coaching vs chat', () => {
        render(<CoachUsageCard usage={usage()} />);

        // auto-coaching = summarizer (900) + strategist (300) = 1,200; chat = coach (300)
        expect(screen.getByText(/Auto-coaching 1,200/)).toBeInTheDocument();
        expect(screen.getByText(/Chat 300/)).toBeInTheDocument();
    });

    it('shows "No cap" and no bar when the budget is uncapped', () => {
        render(
            <CoachUsageCard
                usage={usage({ budget: 0, remaining: null })}
            />,
        );

        expect(screen.getByText(/no cap/i)).toBeInTheDocument();
        expect(screen.queryByTestId('usage-bar')).not.toBeInTheDocument();
    });

    it('flags an over-budget account', () => {
        render(
            <CoachUsageCard
                usage={usage({ used: 200000, remaining: 0 })}
            />,
        );

        expect(screen.getByText(/over budget/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/progress/coach-usage-card.test.tsx`
Expected: FAIL — cannot resolve `./coach-usage-card` / `CoachUsageSnapshot`.

- [ ] **Step 3: Add the type**

In `resources/js/patyourself/types.ts`, add after `LoopProgressDetail`:

```ts
/** The per-user coach token usage block on the progress index (mirrors CoachUsageGuard::snapshotFor). */
export interface CoachUsageSnapshot {
    used: number;
    budget: number; // 0 or less = uncapped
    remaining: number | null; // null when uncapped
    breakdown: Record<string, number>; // purpose => tokens in the rolling 24h
}
```

- [ ] **Step 4: Create the card**

Create `resources/js/patyourself/progress/coach-usage-card.tsx`:

```tsx
import { cn } from '@/lib/utils';
import type { CoachUsageSnapshot } from '@/patyourself/types';

/** Purposes that make up the background auto-coaching pass (vs interactive `coach` chat). */
const AUTO_COACHING_PURPOSES = ['summarizer', 'strategist'];

/**
 * The account-level coach token usage for today: used / budget / remaining with a
 * bar, plus an auto-coaching-vs-chat breakdown. Sits above the per-loop cards on
 * the progress index. Read-only.
 */
export function CoachUsageCard({ usage }: { usage: CoachUsageSnapshot }) {
    const capped = usage.budget > 0;
    const overBudget = capped && usage.remaining === 0;
    const pct = capped
        ? Math.min(100, Math.round((usage.used / usage.budget) * 100))
        : 0;

    const autoCoaching = AUTO_COACHING_PURPOSES.reduce(
        (sum, purpose) => sum + (usage.breakdown[purpose] ?? 0),
        0,
    );
    const chat = usage.breakdown['coach'] ?? 0;

    return (
        <section
            data-testid="coach-usage-card"
            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-foreground">
                    Coach usage today
                </span>
                <span
                    className={cn(
                        'text-sm tabular-nums',
                        overBudget
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {usage.used.toLocaleString()}
                    {capped ? ` / ${usage.budget.toLocaleString()}` : ''}
                </span>
            </div>

            {capped ? (
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        data-testid="usage-bar"
                        className={cn(
                            'h-full rounded-full',
                            overBudget ? 'bg-destructive' : 'bg-primary',
                        )}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            ) : (
                <span className="text-xs text-muted-foreground">No cap</span>
            )}

            <p className="text-xs text-muted-foreground">
                {!capped
                    ? 'Unlimited budget'
                    : overBudget
                      ? 'Over budget — auto-coaching paused until usage frees up.'
                      : `${usage.remaining!.toLocaleString()} tokens remaining`}
            </p>

            <p className="text-xs text-muted-foreground">
                Auto-coaching {autoCoaching.toLocaleString()} · Chat{' '}
                {chat.toLocaleString()}
            </p>
        </section>
    );
}
```

- [ ] **Step 5: Run the card test to verify it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/progress/coach-usage-card.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 6: Render the card on the index + update the index props/tests**

In `resources/js/pages/progress/index.tsx`, import the card and type, extend the props, and render the card above the list:

```tsx
import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { CoachUsageCard } from '@/patyourself/progress/coach-usage-card';
import { ProgressCard } from '@/patyourself/progress/progress-card';
import type { CoachUsageSnapshot, LoopProgressCard } from '@/patyourself/types';

interface ProgressIndexProps {
    loops: LoopProgressCard[];
    usage: CoachUsageSnapshot;
}

/**
 * Progress dashboard — the account's coach-usage card, then a stack of
 * active-loop metric cards (streak, completion rate, recent-activity sparkline,
 * narrative snippet), each linking to the loop's detail. Read-only.
 */
export default function ProgressIndex({ loops, usage }: ProgressIndexProps) {
    return (
        <CoachLayout title="Progress" bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-3">
                <CoachUsageCard usage={usage} />
                {loops.length === 0 ? (
                    <EmptyState />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {loops.map((loop) => (
                            <li key={loop.id}>
                                <ProgressCard loop={loop} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </CoachLayout>
    );
}
```

(Leave `EmptyState` unchanged below.)

In `resources/js/pages/progress/index.test.tsx`, add a `usage` factory and pass it to every render. Add the import and helper near the top (after the `card` factory):

```tsx
import type { CoachUsageSnapshot } from '@/patyourself/types';

function usage(overrides: Partial<CoachUsageSnapshot> = {}): CoachUsageSnapshot {
    return {
        used: 0,
        budget: 200000,
        remaining: 200000,
        breakdown: {},
        ...overrides,
    };
}
```

Update each `render(<ProgressIndex ... />)` call to pass `usage={usage()}`:

```tsx
render(<ProgressIndex loops={[card()]} usage={usage()} />);
```
```tsx
render(<ProgressIndex loops={[card({ id: 7 })]} usage={usage()} />);
```
```tsx
render(
    <ProgressIndex
        loops={[
            card({
                completion_rate: null,
                recent: [],
                streak: { outcome: null, length: 0 },
            }),
        ]}
        usage={usage()}
    />,
);
```
```tsx
render(<ProgressIndex loops={[]} usage={usage()} />);
```

- [ ] **Step 7: Run both progress vitest files to verify they pass**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/progress/coach-usage-card.test.tsx resources/js/pages/progress/index.test.tsx`
Expected: PASS.

- [ ] **Step 8: Prettier + eslint the touched JS/TS**

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/patyourself/progress/coach-usage-card.tsx resources/js/patyourself/progress/coach-usage-card.test.tsx resources/js/pages/progress/index.tsx resources/js/pages/progress/index.test.tsx resources/js/patyourself/types.ts
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx eslint resources/js/patyourself/progress/coach-usage-card.tsx resources/js/patyourself/progress/coach-usage-card.test.tsx resources/js/pages/progress/index.tsx resources/js/pages/progress/index.test.tsx resources/js/patyourself/types.ts
```
Expected: eslint clean (no `consistent-type-imports` or other errors).

- [ ] **Step 9: Commit**

```bash
git add resources/js/patyourself/progress/coach-usage-card.tsx resources/js/patyourself/progress/coach-usage-card.test.tsx resources/js/pages/progress/index.tsx resources/js/pages/progress/index.test.tsx resources/js/patyourself/types.ts
git commit -m "feat(sp6): coach usage card on the progress dashboard

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (after all tasks)

- [ ] Build assets so page-render feature tests find the Vite manifest:

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run build
```

- [ ] Run the full PHP suite:

```bash
php artisan test --compact
```
Expected: all green.

- [ ] Run the full vitest suite:

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run
```
Expected: all green.

- [ ] `vendor/bin/pint --dirty --format agent` reports no changes.

## Plan ↔ Spec coverage

- Attribution via agent-level `forUser()` → Tasks 1, 3.
- Queued path metered + capped → Tasks 1, 2, 3 (proven at the middleware seam in Task 2, wired in Task 3).
- Silent skip when over budget (whole pass) → Task 4.
- `snapshotFor` + container binding (DRY the inline `new`) → Task 5.
- Usage prop on `/progress` → Task 6.
- Usage card with breakdown, uncapped + over-budget states → Task 7.
- Interactive metering unchanged (`auth()` still wins) → unchanged middleware resolution order; covered by the existing `GuardCoachUsageTest::test_records_usage_for_the_authenticated_user`.
