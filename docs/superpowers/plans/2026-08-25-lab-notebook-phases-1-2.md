# Lab Notebook Reframe — Phases 1 & 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip every LLM call out of PatYourSelf and reframe a strategy version as an *experiment* — hypothesis, planned length, result, verdict — so Claude Desktop can coach over MCP while the app holds the record.

**Architecture:** Two phases. Phase 1 deletes the coach: the chat surface, four agents, the auto-revision listener, token metering and rate limiting. Two classes are *stripped*, not deleted — `AuthorIntention` and `ReviseStrategy` each already have a non-LLM branch that the MCP tool and the new experiment model depend on. Phase 2 adds three nullable columns to `strategies`, converts the stripped `ReviseStrategy` into `StartExperiment`, adds `ConcludeExperiment`, and teaches `LoopProgress` to break results down per version.

**Tech Stack:** PHP 8.4, Laravel 13, Inertia v3 + React 19, PHPUnit 12, Pint, SQLite (local) / MySQL (Forge).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-25-lab-notebook-reframe-design.md`. Read it before starting.
- PHP: curly braces always, explicit return types and param type hints, constructor property promotion, PHPDoc array shapes over inline comments.
- After touching any PHP: `vendor/bin/pint --dirty --format agent`.
- Tests: PHPUnit only. `php artisan test --compact --filter=<name>` per task; full suite at the end of each phase.
- Never delete a test file except where this plan names it explicitly.
- **No new base folders under `app/`.** CLAUDE.md forbids it. The spec suggested `App\Domain\` and `App\Support\` for the relocated survivors; this plan uses `App\Services\Strategy\` and `App\Services\Authoring\` instead, both under the existing `app/Services`. This is a deliberate deviation.
- The app is served by Herd at `https://patyourself.test`. Never run `php artisan serve`.
- Each task ends green and committed. Do not batch commits across tasks.

**Ordering constraint:** Task 1 must land before Task 4. `RunCoachingClosure` calls the LLM branch of `ReviseStrategy`; stripping that branch first would break a live listener.

---

# Phase 1 — Strip the LLM layer

### Task 1: Delete the auto-coaching closure

The queued listener that watched for outcome streaks and revised strategies automatically. Its death is the single biggest behaviour change in this plan: **revision becomes deliberate.** It also unblocks Task 4.

**Files:**
- Delete: `app/Listeners/RunCoachingClosure.php`
- Delete: `app/Notifications/StrategyRevisedNotification.php`
- Delete: `tests/Feature/Coach/RunCoachingClosureTest.php`
- Modify: `config/services.php` — remove `fail_streak` and `stack_streak` from the `coach` block (leave the rest of the block; Task 5 removes it entirely)
- Test: `tests/Feature/Actions/LogActionTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ActionLogged` still dispatches and now has no listeners. `ReviseStrategy` has no remaining caller, which Task 4 relies on.

- [ ] **Step 1: Check how the listener is registered**

Laravel 13 auto-discovers listeners. Confirm there is no manual registration:

```bash
grep -rn "RunCoachingClosure" app bootstrap config
```

Expected: only `app/Listeners/RunCoachingClosure.php` itself. If an `EventServiceProvider` or `bootstrap/app.php` entry appears, remove that line too in Step 4.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/Actions/LogActionTest.php`:

```php
public function test_logging_an_outcome_queues_no_coaching_job(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $intention = Intention::factory()->for($user)->create();
    $strategy = Strategy::factory()->for($intention)->create();
    $action = Action::factory()->for($intention)->for($strategy)->create();

    app(LogAction::class)->handle($action, $user, ActionLog::OUTCOME_FAILED, 'ate on autopilot');

    Queue::assertNothingPushed();
}
```

Add the imports it needs at the top of the file: `use Illuminate\Support\Facades\Queue;` plus whichever of `Action`, `ActionLog`, `Intention`, `Strategy`, `User`, `LogAction` are not already imported.

- [ ] **Step 3: Run it and watch it fail**

```bash
php artisan test --compact --filter=test_logging_an_outcome_queues_no_coaching_job
```

Expected: FAIL — a `RunCoachingClosure` job was pushed.

- [ ] **Step 4: Delete the listener and its notification**

```bash
git rm app/Listeners/RunCoachingClosure.php
git rm app/Notifications/StrategyRevisedNotification.php
git rm tests/Feature/Coach/RunCoachingClosureTest.php
```

In `config/services.php`, delete the `fail_streak` and `stack_streak` entries from the `coach` array.

- [ ] **Step 5: Run the test and the surrounding suite**

```bash
php artisan test --compact tests/Feature/Actions/LogActionTest.php
```

Expected: PASS.

- [ ] **Step 6: Confirm nothing else referenced them**

```bash
grep -rn "RunCoachingClosure\|StrategyRevisedNotification\|fail_streak\|stack_streak" app config tests
```

Expected: no output.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: delete the auto-coaching closure

Strategy revision was triggered automatically by a queued listener watching
for outcome streaks. Under the Lab Notebook reframe revision is a deliberate
act taken with Claude, so the listener, its notification and the streak
thresholds that drove it are removed.

ActionLogged still dispatches; it simply has no listeners now."
```

---

### Task 2: Delete the chat surface

**Files:**
- Delete: `app/Http/Controllers/ChatController.php`, `app/Actions/RespondToChat.php`, `app/Ai/Agents/Coach.php`, `app/Services/Coach/Chat/ChatResult.php`
- Delete: `resources/js/pages/coach.tsx`, `resources/js/patyourself/chat/` (entire directory)
- Delete: `tests/Feature/ChatEndpointTest.php`, `tests/Feature/Coach/CoachHardeningTest.php`, `tests/Feature/Ai/CoachConversationTest.php`
- Modify: `routes/web.php`, `resources/js/patyourself/nav-tabs.ts`, `resources/js/resolve-page-layout.ts`, `resources/js/resolve-page-layout.test.ts`, `resources/js/patyourself/app-rail.test.tsx`, `tests/TestCase.php`
- Test: `tests/Feature/DashboardTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: route name `dashboard` now resolves to `IntentionController@index`. Nav has three tabs: Loops, Progress, Inbox.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_the_loop_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('intentions/index'));
    }

    public function test_the_chat_endpoint_is_gone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/chat', ['message' => 'hello'])
            ->assertNotFound();
    }
}
```

Note: the component is still `intentions/index` here — Task 8 renames it to `loops/index` and updates this assertion.

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact tests/Feature/DashboardTest.php
```

Expected: FAIL — dashboard renders `coach`, and `/chat` returns 200 or 422 rather than 404.

- [ ] **Step 3: Repoint the routes**

In `routes/web.php`, replace the chat block:

```php
    // The daily-driver screen. Named `dashboard` because Fortify's post-login
    // redirect (config/fortify.php → home) targets that name. Phase 3 repoints
    // this at the Notebook; until then it shows the loop list.
    Route::get('dashboard', [IntentionController::class, 'index'])->name('dashboard');
```

Delete the `Route::post('chat', ...)` line and the `use App\Http\Controllers\ChatController;` import.

- [ ] **Step 4: Delete the chat code**

```bash
git rm app/Http/Controllers/ChatController.php
git rm app/Actions/RespondToChat.php
git rm app/Ai/Agents/Coach.php
git rm app/Services/Coach/Chat/ChatResult.php
git rm resources/js/pages/coach.tsx
git rm -r resources/js/patyourself/chat
git rm tests/Feature/ChatEndpointTest.php
git rm tests/Feature/Coach/CoachHardeningTest.php
git rm tests/Feature/Ai/CoachConversationTest.php
```

`CoachConversationTest` exercises the Coach agent directly and must go in this commit — leaving it would make the suite red until Task 6.

- [ ] **Step 5: Remove the Coach agent fake from the test base class**

In `tests/TestCase.php`, delete the `use App\Ai\Agents\Coach;` import and the `Coach::fake();` line. Leave `Http::preventStrayRequests()` and the other three fakes — Task 6 removes those.

- [ ] **Step 6: Drop the Coach tab from navigation**

In `resources/js/patyourself/nav-tabs.ts`, delete the first entry of `NAV_TABS` (the `Coach` tab). Update the file's doc comment, which names the coach screen.

In `resources/js/resolve-page-layout.ts`, update the doc comment that lists `(coach, intentions, progress, inbox, …)` to drop `coach`. No code change — the `default: return null` branch already covers every first-party page.

Update `resources/js/resolve-page-layout.test.ts` and `resources/js/patyourself/app-rail.test.tsx` to drop any `coach` / `/dashboard`-as-coach expectations. Run them to find what breaks:

```bash
npm run test -- resolve-page-layout app-rail
```

- [ ] **Step 7: Run the tests**

```bash
php artisan test --compact tests/Feature/DashboardTest.php
npm run test -- resolve-page-layout app-rail nav
```

Expected: PASS.

- [ ] **Step 8: Confirm no dangling references**

```bash
grep -rn "ChatController\|RespondToChat\|ChatResult\|patyourself/chat\|pages/coach" app routes resources tests
```

Expected: no output.

- [ ] **Step 9: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: delete the in-app chat surface

Claude Desktop is the coaching surface now, over the MCP connector. The
chat endpoint, its controller, the Coach agent and the whole chat UI tree
are removed, and the dashboard route -- which Fortify's post-login redirect
targets by name -- now shows the loop list until phase 3 introduces the
Notebook."
```

---

### Task 3: Strip `AuthorIntention` down to its persistence half

**This is the task most likely to break something silently.** `CreateLoopTool` — the only working way Claude creates a loop — depends on `AuthorIntention`, but passes a pre-authored DTO so the LLM branch never runs. Delete the class and you lose Claude's write path.

**Files:**
- Create: `app/Actions/PersistAuthoredIntention.php`
- Delete: `app/Actions/AuthorIntention.php`, `app/Ai/Agents/IntentionAuthor.php`, `app/Services/Coach/Authoring/IntentionSchema.php`
- Delete: `tests/Feature/Ai/CreateLoopTest.php`, `tests/Feature/PromptVersioningTest.php`
- Modify: `app/Mcp/Tools/CreateLoopTool.php:38`, `app/Mcp/Tools/CreateLoopTool.php:83`
- Test (existing, do not modify): `tests/Feature/Mcp/CreateLoopToolTest.php`

**Do not confuse two similarly named tests.** `tests/Feature/Ai/CreateLoopTest.php` covers the *agent* tool `App\Ai\Tools\CreateLoop` and is deleted in Task 6. The regression net for **this** task is `tests/Feature/Mcp/CreateLoopToolTest.php`, which already exists and covers the MCP tool.

**Interfaces:**
- Consumes: `AuthoredIntention` (unchanged, still at `App\Services\Coach\Authoring\` until Task 7).
- Produces: `PersistAuthoredIntention::handle(User $user, AuthoredIntention $authored, string $status = Intention::STATUS_ACTIVE): Intention`. Note the signature change — `$goal` and `$context` were prompt inputs and are gone, and `$authored` is now required and second.

- [ ] **Step 1: Read the regression net**

Read `tests/Feature/Mcp/CreateLoopToolTest.php`. Its assertions define the behaviour this task must preserve exactly. **Do not modify this file at any point in this task** — if it needs changing, you have changed behaviour.

- [ ] **Step 2: Run it to establish the green baseline**

```bash
php artisan test --compact tests/Feature/Mcp/CreateLoopToolTest.php
```

Expected: PASS. **If it does not pass here, stop** — you cannot tell afterwards whether you broke it.

- [ ] **Step 3: Create the persistence-only action**

Create `app/Actions/PersistAuthoredIntention.php`. This is `AuthorIntention` with the agent call and prompt builder removed; `persist` and `persistAction` are copied verbatim:

```php
<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists an already-authored loop: the intention, version 1 of its strategy,
 * and its first action, in one transaction.
 *
 * The authoring itself happens in Claude and arrives through the MCP create-loop
 * tool as a validated {@see AuthoredIntention}. This class makes no model call.
 */
final readonly class PersistAuthoredIntention
{
    public function handle(
        User $user,
        AuthoredIntention $authored,
        string $status = Intention::STATUS_ACTIVE,
    ): Intention {
        return DB::transaction(fn (): Intention => $this->persist($user, $authored, $status));
    }

    private function persist(User $user, AuthoredIntention $authored, string $status): Intention
    {
        $intention = Intention::create([
            'user_id' => $user->id,
            'title' => $authored->title,
            'description' => $authored->description,
            'type' => $authored->type,
            'status' => $status,
            'cue' => $authored->cue,
            'craving' => $authored->craving,
            'response' => $authored->response,
            'reward' => $authored->reward,
            'metadata' => $authored->metadata(),
        ]);

        if ($authored->strategy !== null) {
            $strategy = $intention->strategies()->create([
                'version' => 1,
                'status' => Strategy::STATUS_ACTIVE,
                'intervention_point' => $authored->strategy->interventionPoint,
                'approach' => $authored->strategy->approach,
                'rationale' => $authored->strategy->rationale,
                'change_reason' => Strategy::REASON_INITIAL,
                'metadata' => array_filter(['prompt_version' => $authored->promptVersion]),
            ]);

            $intention->setRelation('activeStrategy', $intention->activeStrategy()->first());

            if ($authored->action !== null) {
                $this->persistAction($intention, $strategy, $user, $authored->action);
            }
        }

        return $intention;
    }

    private function persistAction(Intention $intention, Strategy $strategy, User $user, AuthoredAction $action): void
    {
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $scheduledFor = (new Schedule)->firstOccurrence(
            CarbonImmutable::now(),
            $action->time,
            $recurrence,
            $timezone,
        );

        $intention->actions()->create([
            'strategy_id' => $strategy->id,
            'title' => $action->title,
            'description' => $action->description,
            'scheduled_for' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => array_filter([
                'schedule_kind' => $action->kind,
                'anchor' => $action->anchor,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
```

- [ ] **Step 4: Repoint the MCP tool**

In `app/Mcp/Tools/CreateLoopTool.php`:

Replace the import `use App\Actions\AuthorIntention;` with `use App\Actions\PersistAuthoredIntention;`.

Change the handler signature and its doc comment:

```php
    /**
     * The client authors the structure; this only validates and persists. No
     * model call happens anywhere in this path.
     */
    public function handle(Request $request, PersistAuthoredIntention $persist): Response
```

Replace the call at the bottom of `handle`:

```php
        $intention = $persist->handle(
            $request->user(),
            $authored,
            Intention::STATUS_PAUSED,
        );
```

- [ ] **Step 5: Delete the LLM author**

```bash
git rm app/Actions/AuthorIntention.php
git rm app/Ai/Agents/IntentionAuthor.php
git rm app/Services/Coach/Authoring/IntentionSchema.php
git rm tests/Feature/Ai/CreateLoopTest.php
git rm tests/Feature/PromptVersioningTest.php
```

Both tests must go in this commit or the suite stays red until Task 6.
`tests/Feature/Ai/CreateLoopTest.php` covers the deleted *agent* tool
`App\Ai\Tools\CreateLoop`. `PromptVersioningTest` asserts that agent-authored
artifacts record which prompt version produced them — with no agents there are
no prompt versions to record.

In `tests/TestCase.php`, delete the `use App\Ai\Agents\IntentionAuthor;` import and the `IntentionAuthor::fake();` line.

- [ ] **Step 6: Run the regression net**

```bash
php artisan test --compact tests/Feature/Mcp/CreateLoopToolTest.php
```

Expected: PASS, unmodified, with the same assertions as Step 2.

- [ ] **Step 7: Confirm nothing else called it**

```bash
grep -rn "AuthorIntention\|IntentionAuthor\|IntentionSchema" app tests
```

Expected: `PersistAuthoredIntention` (it contains the substring), plus `tests/Feature/Ai/CreateLoopTest.php` and `tests/Feature/PromptVersioningTest.php`, which Task 6 deletes. If `CreateIntention` or a controller appears, repoint it.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: split AuthorIntention into PersistAuthoredIntention

AuthorIntention had two branches: prompt the IntentionAuthor agent, or
persist a DTO that was authored elsewhere. The MCP create-loop tool relies
entirely on the second -- it passes a pre-authored payload precisely so no
model call happens -- so deleting the class wholesale would have removed
Claude's only path for creating a loop.

Keeps the transactional writer, drops the agent branch, and renames the
class for what it actually does now."
```

---

### Task 4: Strip `ReviseStrategy` of its LLM branch

Same shape as Task 3. `supersedeAndCreate` and `authorActionFor` are subtle, correct, and become the backbone of `StartExperiment` in Phase 2 — they survive verbatim. Only the `revise()` agent call and the prompt builder go. The class keeps its name here; Task 10 renames it.

**Files:**
- Modify: `app/Actions/ReviseStrategy.php`
- Delete: `app/Ai/Agents/Strategist.php`, `tests/Feature/Ai/StrategistTest.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Feature/ReviseStrategyTest.php` (note: at the root of `tests/Feature`, not under `Actions/`)

**Interfaces:**
- Consumes: `AuthoredStrategy`, `BehavioralChain`, `StrategyTransitionException` (all unchanged until Task 7).
- Produces: `stackOnSuccess(Strategy $current, AuthoredStrategy $next): Strategy` and `restrategizeOnFailure(Strategy $current, string $reason, AuthoredStrategy $next): Strategy`. **`$next` is now required** and `$context` is gone.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReviseStrategyTest.php`:

```php
public function test_it_requires_a_pre_authored_revision(): void
{
    $intention = Intention::factory()->create();
    $current = Strategy::factory()->for($intention)->create([
        'status' => Strategy::STATUS_ACTIVE,
        'intervention_point' => Strategy::POINT_RESPONSE,
    ]);

    $next = new AuthoredStrategy(
        interventionPoint: Strategy::POINT_CUE,
        approach: 'put the fork down between bites',
        rationale: 'the gap has to exist before the fullness signal lands',
        promptVersion: 'test@1',
    );

    $revised = app(ReviseStrategy::class)->restrategizeOnFailure($current, 'ate on autopilot', $next);

    $this->assertSame(2, $revised->version);
    $this->assertSame(Strategy::POINT_CUE, $revised->intervention_point);
    $this->assertSame('earlier', $revised->metadata['direction']);
    $this->assertSame(Strategy::STATUS_SUPERSEDED, $current->fresh()->status);
    $this->assertSame('ate on autopilot', $current->fresh()->superseded_reason);
}
```

- [ ] **Step 2: Run it**

```bash
php artisan test --compact --filter=test_it_requires_a_pre_authored_revision
```

Expected: PASS already (the pre-authored path exists today). This test is the net that proves the strip does not change behaviour.

- [ ] **Step 3: Strip the class**

In `app/Actions/ReviseStrategy.php`:

Delete the `revise()` method, the `userPrompt()` method, and the `use App\Ai\Agents\Strategist;` and `use App\Services\Coach\Exceptions\CoachException;` imports.

Delete the `private ?AuthoredAction $revisedAction = null;` property and replace its every use in `authorActionFor` with a parameter. Change `supersedeAndCreate` to take the action through, and update `authorActionFor`'s signature to `authorActionFor(Intention $intention, Strategy $strategy, AuthoredStrategy $next, ?AuthoredAction $revisedAction): void`, replacing `$action = $this->revisedAction;` with `$action = $revisedAction;`.

For now no caller passes a revised action, so both public methods pass `null`. Phase 2 (Task 10) gives it a real parameter.

Make `$next` required in both public methods and delete the `$next ??= $this->revise(...)` lines and the `$context` parameters:

```php
    /**
     * The current strategy succeeded — stack toward a harder goal.
     *
     * @throws StrategyTransitionException
     */
    public function stackOnSuccess(Strategy $current, AuthoredStrategy $next): Strategy
    {
        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_STACKED_ON_SUCCESS,
            supersededReason: null,
            revisedAction: null,
        ));
    }

    /**
     * The current strategy failed — restrategize from the user-stated reason.
     *
     * @throws StrategyTransitionException
     */
    public function restrategizeOnFailure(Strategy $current, string $reason, AuthoredStrategy $next): Strategy
    {
        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            supersededReason: $reason,
            revisedAction: null,
        ));
    }
```

Update the class doc comment to say the revision arrives pre-authored rather than from the Strategist.

- [ ] **Step 4: Delete the Strategist**

```bash
git rm app/Ai/Agents/Strategist.php
git rm tests/Feature/Ai/StrategistTest.php
```

In `tests/TestCase.php`, delete the `use App\Ai\Agents\Strategist;` import and the `Strategist::fake();` line.

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Feature/ReviseStrategyTest.php tests/Feature/Actions
```

Expected: PASS. Any test that called `restrategizeOnFailure` or `stackOnSuccess` *without* a `$next` must be updated to build an `AuthoredStrategy` like the one in Step 1 — those tests were exercising the agent branch that no longer exists.

- [ ] **Step 6: Confirm**

```bash
grep -rn "Strategist" app tests
```

Expected: no output.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: strip ReviseStrategy of its agent branch

The revision now always arrives pre-authored; the Strategist agent and the
prompt it built are gone. The supersede-and-create transition itself is
untouched -- it is deterministic and becomes the backbone of StartExperiment
in phase 2."
```

---

### Task 5: Delete usage metering, the budget guard and the coach rate limiter

**Files:**
- Delete: `app/Services/Coach/Usage/CoachUsageGuard.php`, `app/Ai/Middleware/GuardCoachUsage.php`, `app/Ai/Concerns/MetersUsageToUser.php`, `app/Models/CoachUsage.php`
- Delete: `tests/Feature/Ai/GuardCoachUsageTest.php`, `tests/Feature/Coach/CoachUsageGuardTest.php`, `tests/Feature/Coach/AttributesCoachingUsageTest.php`, `tests/Unit/Ai/MetersUsageToUserTest.php`, `tests/Feature/Progress/ProgressUsageTest.php`
- Delete: `resources/js/patyourself/progress/coach-usage-card.tsx` and `coach-usage-card.test.tsx`
- Create: a migration dropping `coach_usages`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/services.php`, `.env.example`, `app/Http/Controllers/ProgressController.php`, `resources/js/pages/progress/index.tsx`, `resources/js/pages/progress/index.test.tsx`, `resources/js/patyourself/types.ts`

**Interfaces:**
- Consumes: nothing.
- Produces: `ProgressController@index` no longer passes a `usage` prop. The `coach` rate limiter no longer exists — any `throttle:coach` middleware left anywhere would now throw.

- [ ] **Step 1: Confirm no route still uses the limiter**

```bash
grep -rn "throttle:coach" routes app
```

Expected: no output (Task 2 removed the only one). If anything appears, remove it before continuing — a missing named limiter is a runtime 500, not a startup error.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/Progress/ProgressIndexTest.php`:

```php
public function test_the_progress_index_carries_no_usage_prop(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('progress'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('progress/index')->missing('usage'));
}
```

- [ ] **Step 3: Run it and watch it fail**

```bash
php artisan test --compact --filter=test_the_progress_index_carries_no_usage_prop
```

Expected: FAIL — the `usage` prop is present.

- [ ] **Step 4: Generate the drop migration**

```bash
php artisan make:migration drop_coach_usages_table --no-interaction
```

Fill it in:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('coach_usages');
    }

    /**
     * Deliberately irreversible. The app no longer meters token spend, so
     * recreating an empty table would restore the shape without the meaning.
     */
    public function down(): void
    {
        //
    }
};
```

- [ ] **Step 5: Delete the code**

```bash
git rm app/Services/Coach/Usage/CoachUsageGuard.php
git rm app/Ai/Middleware/GuardCoachUsage.php
git rm app/Ai/Concerns/MetersUsageToUser.php
git rm app/Models/CoachUsage.php
git rm tests/Feature/Ai/GuardCoachUsageTest.php
git rm tests/Feature/Coach/CoachUsageGuardTest.php
git rm tests/Feature/Coach/AttributesCoachingUsageTest.php
git rm tests/Unit/Ai/MetersUsageToUserTest.php
git rm tests/Feature/Progress/ProgressUsageTest.php
git rm resources/js/patyourself/progress/coach-usage-card.tsx
git rm resources/js/patyourself/progress/coach-usage-card.test.tsx
```

There is no `CoachUsageFactory` — `database/factories/` holds only Action, ActionLog, Intention, Strategy, Summary and User. Nothing to delete there.

After this commit `tests/Feature/Coach/` contains only `OutcomeStreakTest.php`. **Leave it** — `OutcomeStreak` survives, and Task 7 moves both the class and its test.

- [ ] **Step 6: Unwire the provider**

In `app/Providers/AppServiceProvider.php`: delete the `use App\Services\Coach\Usage\CoachUsageGuard;` import, the entire body of `register()` except the `TurnCollector` binding (Task 6 removes that), the `configureRateLimiting()` method and its call in `boot()`, and the now-unused `Limit`, `Request` and `RateLimiter` imports.

- [ ] **Step 7: Unwire the exception renderers**

In `bootstrap/app.php`: delete the `CoachException` / `CoachQuotaException` imports, the entire `$exceptions->render(...)` closure and the `JsonResponse` import. Keep `shouldRenderJsonWhen`.

Then delete `app/Services/Coach/Exceptions/CoachQuotaException.php`.

- [ ] **Step 8: Strip the config**

In `config/services.php`, delete the whole `'coach' => [...]` block. In `.env.example`, delete `ANTHROPIC_API_KEY` and any coach budget or rate keys.

- [ ] **Step 9: Strip the controller and the frontend**

In `app/Http/Controllers/ProgressController.php`: delete the `CoachUsageGuard` import, the `$guard` parameter from `index()`, and the `'usage' => $guard->snapshotFor($request->user()),` line.

In `resources/js/pages/progress/index.tsx`: delete the `usage` prop from the props interface and the `<CoachUsageCard>` render plus its import. In `index.test.tsx`, delete the `usage` fixture and any assertion on the card. In `resources/js/patyourself/types.ts`, delete the coach-usage types.

- [ ] **Step 10: Run everything touched**

```bash
php artisan migrate
php artisan test --compact tests/Feature/Progress
npm run test -- progress
```

Expected: PASS.

- [ ] **Step 11: Confirm**

```bash
grep -rn "CoachUsage\|GuardCoachUsage\|MetersUsageToUser\|CoachQuotaException\|ANTHROPIC_API_KEY\|throttle:coach" app config routes tests resources bootstrap .env.example
```

Expected: no output.

- [ ] **Step 12: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: remove token metering, the budget guard and the coach limiter

Nothing in the app spends tokens any more, so the per-user daily budget, the
usage middleware, the coach_usages table and the progress dashboard's usage
card have no subject. The coach rate limiter and the 429/503 renderers that
existed to degrade LLM failures gracefully go with them.

ANTHROPIC_API_KEY is no longer read anywhere and can be removed from Forge
once this deploys."
```

---

### Task 6: Delete the remaining agent infrastructure

**Files:**
- Delete: `app/Ai/` (whole tree — `Agents/Summarizer.php`, `Tools/`, `TurnCollector.php`), `app/Actions/UpdateRollingSummary.php`, `app/Console/Commands/CoachPing.php`, `config/ai.php`
- Delete: the rest of `tests/Feature/Ai/` — `TurnCollectorTest.php`, `ReadToolsTest.php`, `SdkInstallTest.php`, `SummarizerTest.php` — and `tests/Unit/Ai/`
- Modify: `tests/TestCase.php`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `app/Ai` no longer exists. The `summaries` table and `Summary` model **survive** — Phase 4 lets Claude write reflections into them.

- [ ] **Step 1: Check what still reads the summary**

```bash
grep -rn "UpdateRollingSummary\|Summarizer\|latestSummary\|config('ai" app resources tests
```

`ProgressController` and `progress/show.tsx` read `latestSummary` — those stay. Only the *writer* is being deleted. Note which files appear so Step 5 does not surprise you.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/NoLlmTest.php` with this test in it (namespace `Tests\Feature`, extending `Tests\TestCase`, no `RefreshDatabase` needed):

```php
public function test_the_application_has_no_ai_layer(): void
{
    $this->assertDirectoryDoesNotExist(app_path('Ai'));
    $this->assertFileDoesNotExist(config_path('ai.php'));
    $this->assertNull(config('ai'));
}
```

- [ ] **Step 3: Run it and watch it fail**

```bash
php artisan test --compact --filter=test_the_application_has_no_ai_layer
```

Expected: FAIL — the directory exists.

- [ ] **Step 4: Delete**

```bash
git rm -r app/Ai
git rm app/Actions/UpdateRollingSummary.php
git rm app/Console/Commands/CoachPing.php
git rm config/ai.php
git rm -r tests/Feature/Ai tests/Unit/Ai
```

That clears the last four agent tests (`TurnCollectorTest`, `ReadToolsTest`, `SdkInstallTest`, `SummarizerTest`). **Do not touch `tests/Feature/Coach/OutcomeStreakTest.php`** — `OutcomeStreak` survives and Task 7 relocates it.

- [ ] **Step 5: Clean the test base class and the provider**

`tests/TestCase.php` should now read:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may reach the network. The app makes no outbound calls of its
     * own any more, so this is a standing guarantee rather than an agent
     * workaround — leave it in place.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
```

In `app/Providers/AppServiceProvider.php`, delete the `use App\Ai\TurnCollector;` import and the `$this->app->scoped(TurnCollector::class);` line. `register()` is now empty — leave the method with an empty body and its docblock.

- [ ] **Step 6: Run the full suite**

```bash
php artisan test --compact
```

Expected: PASS. This is the first full-suite run of the phase; fix any straggler that referenced a deleted class.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: delete the remaining agent infrastructure

Removes the Summarizer, its rolling-summary writer, the agent tool wrappers,
the turn collector, the coach ping command and config/ai.php. The app now
makes no LLM calls at all.

The summaries table and Summary model survive deliberately: phase 4 lets
Claude write reflections into them over MCP, so the progress screen keeps its
narrative section."
```

---

### Task 7: Relocate the survivors out of `App\Services\Coach`

The coach is gone but four pieces of its namespace are not coach-specific: the authoring DTOs (the MCP tool's input contract), the base authoring exception, the behavioural-chain helper and the outcome-streak reader.

**Files:**
- Move: `app/Services/Coach/Authoring/*` → `app/Services/Authoring/`
- Move: `app/Services/Coach/Strategy/*` → `app/Services/Strategy/`
- Move: `app/Services/Coach/OutcomeStreak.php` → `app/Services/Strategy/OutcomeStreak.php`
- Move: `tests/Feature/Coach/OutcomeStreakTest.php` → `tests/Feature/Strategy/OutcomeStreakTest.php` (the last file in `tests/Feature/Coach/`)
- Rename: `CoachException` → `App\Services\Authoring\AuthoringException`
- Delete: `app/Services/Coach/` (now empty)
- Modify: every importer — `CreateLoopTool`, `PersistAuthoredIntention`, `ReviseStrategy`, `LoopProgress`, and their tests

**Interfaces:**
- Consumes: nothing new.
- Produces: `App\Services\Authoring\{AuthoredIntention, AuthoredStrategy, AuthoredAction, AuthoringException, IntentionAuthoringException}`, `App\Services\Strategy\{BehavioralChain, StrategyTransitionException, OutcomeStreak}`.

- [ ] **Step 1: Move the files**

```bash
mkdir -p app/Services/Authoring app/Services/Strategy
git mv app/Services/Coach/Authoring/AuthoredIntention.php app/Services/Authoring/
git mv app/Services/Coach/Authoring/AuthoredStrategy.php app/Services/Authoring/
git mv app/Services/Coach/Authoring/AuthoredAction.php app/Services/Authoring/
git mv app/Services/Coach/Authoring/IntentionAuthoringException.php app/Services/Authoring/
git mv app/Services/Coach/Exceptions/CoachException.php app/Services/Authoring/AuthoringException.php
git mv app/Services/Coach/Strategy/BehavioralChain.php app/Services/Strategy/
git mv app/Services/Coach/Strategy/StrategyTransitionException.php app/Services/Strategy/
git mv app/Services/Coach/OutcomeStreak.php app/Services/Strategy/
mkdir -p tests/Feature/Strategy
git mv tests/Feature/Coach/OutcomeStreakTest.php tests/Feature/Strategy/OutcomeStreakTest.php
```

`tests/Feature/Coach/` is now empty and can be removed. Change the moved test's namespace from `Tests\Feature\Coach` to `Tests\Feature\Strategy`.

- [ ] **Step 2: Rewrite the namespaces**

In each moved file, change the `namespace` line:
- `App\Services\Coach\Authoring` → `App\Services\Authoring`
- `App\Services\Coach\Exceptions` → `App\Services\Authoring`
- `App\Services\Coach\Strategy` → `App\Services\Strategy`
- `App\Services\Coach` (OutcomeStreak) → `App\Services\Strategy`

In `AuthoringException.php`, rename the class from `CoachException` to `AuthoringException` and update its docblock — it is raised when an authored payload cannot be parsed, not when a provider fails. Delete the `missingCredentials`, `requestFailed`, `unsupportedDriver` and `emptyResponse` factory methods; only `invalidJson` and whatever `AuthoredIntention::fromStructured` actually throws are still reachable. Verify which before deleting:

```bash
grep -rn "CoachException::" app
```

Keep exactly the factories that grep finds, delete the rest.

- [ ] **Step 3: Update every importer**

```bash
grep -rln "Services\\\\Coach" app tests
```

For each hit, rewrite the `use` statement to the new namespace and `CoachException` to `AuthoringException`. `OutcomeStreak` is injected into `LoopProgress`'s constructor — update that import too.

- [ ] **Step 4: Delete the empty tree**

```bash
rm -rf app/Services/Coach
```

- [ ] **Step 5: Run the full suite**

```bash
composer dump-autoload
php artisan test --compact
```

Expected: PASS.

- [ ] **Step 6: Confirm**

```bash
grep -rn "Services\\\\Coach\|CoachException" app tests
```

Expected: no output.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: move the coach namespace survivors to their own homes

Four pieces of App\\Services\\Coach were never coach-specific: the authoring
DTOs that form the MCP create-loop contract, the behavioural-chain direction
helper, the strategy-transition guard and the deterministic outcome-streak
reader.

They move to App\\Services\\Authoring and App\\Services\\Strategy, CoachException
becomes AuthoringException with only its reachable factories, and the coach
namespace is deleted."
```

---

### Task 8: Rename `/intentions` to `/loops`

The model stays `Intention`. Only URLs, route names and page paths change.

**Files:**
- Modify: `routes/web.php`
- Move: `resources/js/pages/intentions/` → `resources/js/pages/loops/`
- Modify: `resources/js/patyourself/nav-tabs.ts`, `resources/js/pages/inbox.tsx`, `resources/js/resolve-page-layout.test.ts`, `resources/js/patyourself/app-rail.test.tsx`, `resources/js/pages/inbox.test.tsx`, `tests/Feature/DashboardTest.php`, and every controller/test using `route('intentions.*')`

**Interfaces:**
- Consumes: nothing.
- Produces: route names `loops.index`, `loops.show`, `loops.store`, `loops.update`, `loops.destroy`. Inertia components `loops/index`, `loops/show`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
public function test_loops_live_at_the_loops_url(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)->get('/loops')->assertOk();
    $this->actingAs($user)->get('/intentions')->assertNotFound();
    $this->assertSame('/loops', route('loops.index', absolute: false));
}
```

Update the existing `test_dashboard_renders_the_loop_list` assertion from `component('intentions/index')` to `component('loops/index')`.

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact tests/Feature/DashboardTest.php
```

Expected: FAIL — `/loops` 404s.

- [ ] **Step 3: Rename the route**

In `routes/web.php`:

```php
    // Loops (the Intention model): list, detail and the write endpoints, all
    // sharing the same Actions as the MCP server.
    Route::resource('loops', IntentionController::class)
        ->parameters(['loops' => 'intention'])
        ->only(['index', 'show', 'store', 'update', 'destroy']);
```

The `parameters()` call keeps route-model binding on `{intention}`, so `IntentionController`'s method signatures are untouched.

Leave `progress/{intention}` alone — Phase 3 folds Progress into the lab record.

- [ ] **Step 4: Move the pages**

```bash
git mv resources/js/pages/intentions resources/js/pages/loops
```

In `resources/js/pages/loops/show.tsx`, change the back-link `href="/intentions"` to `href="/loops"`. In `show.test.tsx`, change the page url fixture `/intentions/1` to `/loops/1`. In `index.tsx`, change the card `href` template from `/intentions/${loop.id}` to `/loops/${loop.id}`.

- [ ] **Step 5: Update every other path literal**

```bash
grep -rn "/intentions\|intentions\." resources/js routes app tests
```

Fix each hit:
- `nav-tabs.ts`: the Loops tab `href` and `match` become `/loops`.
- `inbox.tsx`: the notification link template becomes `/loops/${notification.intention_id}`, and its comment.
- `inbox.test.tsx`, `app-rail.test.tsx`, `resolve-page-layout.test.ts`: update the expected paths and page names.
- Any PHP using `route('intentions.…')` becomes `route('loops.…')`.

Leave the `intentions` *table* and the `Intention` *model* alone — only paths and route names change.

- [ ] **Step 6: Run everything**

```bash
php artisan test --compact
npm run test
```

Expected: PASS.

- [ ] **Step 7: Confirm**

```bash
grep -rn "'/intentions\|\"/intentions\|route('intentions" resources/js app routes tests
```

Expected: no output.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: move loops from /intentions to /loops

The UI, the MCP server and the owner all say 'loops'; only the URLs said
'intentions'. Route model binding still resolves {intention}, so the
controller and the Intention model are untouched."
```

---

## Phase 1 gate

- [ ] **Full suite green**

```bash
php artisan test --compact
npm run test
```

- [ ] **The MCP contract is untouched**

```bash
php artisan test --compact tests/Feature/Mcp
```

Expected: PASS, with no edits to any file in `tests/Feature/Mcp/`. `McpEndpointTest` asserts the exact, ordered tool names *and* that every advertised name appears in the server's `#[Instructions]`. Phase 1 changes neither, so a failure here means something in the strip reached further than intended. `CreateLoopToolTest` proves Claude can still create a loop.

---

# Phase 2 — The experiment model

### Task 9: Add the experiment columns to `strategies`

**Files:**
- Create: a migration adding `review_at`, `verdict`, `verdict_note`
- Modify: `app/Models/Strategy.php`
- Test: `tests/Unit/Models/StrategyTest.php` (create if absent)

**Interfaces:**
- Produces: on `Strategy` — constants `VERDICT_WORKED`, `VERDICT_FAILED`, `VERDICT_INCONCLUSIVE`, array `VERDICTS`; methods `isConcluded(): bool`, `isUnderReview(): bool`, `dayOfExperiment(): int`, `plannedDays(): ?int`. Cast `review_at` to `immutable_datetime`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/StrategyTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Strategy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_strategy_is_not_concluded(): void
    {
        $strategy = Strategy::factory()->create();

        $this->assertFalse($strategy->isConcluded());
        $this->assertNull($strategy->verdict);
    }

    public function test_a_strategy_with_a_verdict_is_concluded(): void
    {
        $strategy = Strategy::factory()->create([
            'verdict' => Strategy::VERDICT_WORKED,
            'verdict_note' => 'the pause stuck once I put the fork down',
        ]);

        $this->assertTrue($strategy->isConcluded());
    }

    public function test_an_open_ended_experiment_is_never_under_review(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');

        $strategy = Strategy::factory()->create(['review_at' => null]);

        $this->assertFalse($strategy->isUnderReview());
        $this->assertNull($strategy->plannedDays());
    }

    public function test_it_is_under_review_only_after_the_review_date(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');

        $strategy = Strategy::factory()->create(['review_at' => CarbonImmutable::parse('2026-09-10 12:00:00')]);

        $this->assertFalse($strategy->isUnderReview());

        CarbonImmutable::setTestNow('2026-09-11 12:00:00');

        $this->assertTrue($strategy->fresh()->isUnderReview());
    }

    public function test_a_concluded_experiment_is_no_longer_under_review(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 12:00:00');

        $strategy = Strategy::factory()->create([
            'review_at' => CarbonImmutable::parse('2026-09-10 12:00:00'),
            'verdict' => Strategy::VERDICT_FAILED,
        ]);

        $this->assertFalse($strategy->isUnderReview());
    }

    public function test_it_counts_the_days_of_the_experiment(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $strategy = Strategy::factory()->create([
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
        ]);

        $this->assertSame(12, $strategy->dayOfExperiment());
        $this->assertSame(21, $strategy->plannedDays());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact tests/Unit/Models/StrategyTest.php
```

Expected: FAIL — unknown column `review_at`.

- [ ] **Step 3: Generate the migration**

```bash
php artisan make:migration add_experiment_columns_to_strategies_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A strategy version is an experiment: it runs for a planned length and
     * ends with a verdict.
     *
     * All three are nullable, and existing rows keep null — which reads
     * correctly as "open-ended, never concluded". `verdict_note` is separate
     * from `superseded_reason` because a strategy concluded as `worked` is
     * never superseded, so its note would otherwise live permanently in a
     * column whose name denies it.
     */
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dateTime('review_at')->nullable()->after('superseded_reason');
            $table->string('verdict')->nullable()->after('review_at');
            $table->text('verdict_note')->nullable()->after('verdict');
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn(['review_at', 'verdict', 'verdict_note']);
        });
    }
};
```

- [ ] **Step 4: Extend the model**

In `app/Models/Strategy.php`, add `'review_at'`, `'verdict'` and `'verdict_note'` to the `#[Fillable]` attribute list, then add the constants after `REASON_RESTRATEGIZED_ON_FAILURE`:

```php
    public const VERDICT_WORKED = 'worked';

    public const VERDICT_FAILED = 'failed';

    public const VERDICT_INCONCLUSIVE = 'inconclusive';

    /** Every verdict an experiment can end with. */
    public const VERDICTS = [self::VERDICT_WORKED, self::VERDICT_FAILED, self::VERDICT_INCONCLUSIVE];
```

Extend `casts()`:

```php
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'metadata' => 'array',
            'review_at' => 'immutable_datetime',
        ];
    }
```

Add the helpers after `isActive()`:

```php
    /**
     * A version is concluded once it carries a verdict. Concluding does not
     * supersede it — a strategy that worked keeps running.
     */
    public function isConcluded(): bool
    {
        return $this->verdict !== null;
    }

    /**
     * Past its planned end and still waiting on a verdict. Open-ended
     * experiments (no `review_at`) are never under review, which is what keeps
     * the notebook from nagging.
     */
    public function isUnderReview(): bool
    {
        return ! $this->isConcluded()
            && $this->review_at !== null
            && $this->review_at->isPast();
    }

    /** Whole days elapsed since this version became active, counting from 0. */
    public function dayOfExperiment(): int
    {
        return (int) $this->created_at->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** The planned run length in whole days, or null when open-ended. */
    public function plannedDays(): ?int
    {
        return $this->review_at === null
            ? null
            : (int) $this->created_at->startOfDay()->diffInDays($this->review_at->startOfDay());
    }
```

- [ ] **Step 5: Run the tests**

```bash
php artisan migrate
php artisan test --compact tests/Unit/Models/StrategyTest.php
```

Expected: PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: model a strategy version as an experiment

Adds review_at, verdict and verdict_note to strategies, plus the helpers that
read them: isConcluded, isUnderReview, dayOfExperiment and plannedDays.

review_at is nullable and null means open-ended -- an experiment with no
planned end is never 'under review', which is what stops the notebook from
pressuring the user to always be running one."
```

---

### Task 10: Convert `ReviseStrategy` into `StartExperiment`

**Files:**
- Move: `app/Actions/ReviseStrategy.php` → `app/Actions/StartExperiment.php`
- Modify: its tests (rename the file to match)

**Interfaces:**
- Consumes: `AuthoredStrategy`, `AuthoredAction`, `BehavioralChain`, `StrategyTransitionException` from `App\Services\{Authoring,Strategy}`.
- Produces: `StartExperiment::handle(Strategy $current, AuthoredStrategy $next, string $changeReason, ?string $supersededReason = null, ?int $reviewAfterDays = null, ?AuthoredAction $revisedAction = null): Strategy`. Replaces both `stackOnSuccess` and `restrategizeOnFailure` — the caller now passes `Strategy::REASON_STACKED_ON_SUCCESS` or `Strategy::REASON_RESTRATEGIZED_ON_FAILURE` directly.

- [ ] **Step 1: Write the failing test**

Rename the existing revise test file to `tests/Feature/Actions/StartExperimentTest.php`, update its namespace and class name, and rewrite its calls onto the new API. Add:

```php
public function test_it_sets_a_review_date_when_given_a_run_length(): void
{
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');

    $intention = Intention::factory()->create();
    $current = Strategy::factory()->for($intention)->create([
        'status' => Strategy::STATUS_ACTIVE,
        'intervention_point' => Strategy::POINT_RESPONSE,
    ]);

    $next = new AuthoredStrategy(
        interventionPoint: Strategy::POINT_CUE,
        approach: 'put the fork down between bites',
        rationale: 'the gap has to exist before the fullness signal lands',
        promptVersion: 'mcp@1',
    );

    $experiment = app(StartExperiment::class)->handle(
        $current,
        $next,
        Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        supersededReason: 'ate on autopilot',
        reviewAfterDays: 21,
    );

    $this->assertSame(2, $experiment->version);
    $this->assertSame('2026-09-22', $experiment->review_at->toDateString());
    $this->assertSame(21, $experiment->plannedDays());
    $this->assertNull($experiment->verdict);
    $this->assertSame(Strategy::STATUS_SUPERSEDED, $current->fresh()->status);
    $this->assertSame('ate on autopilot', $current->fresh()->superseded_reason);
}

public function test_an_experiment_without_a_run_length_is_open_ended(): void
{
    $intention = Intention::factory()->create();
    $current = Strategy::factory()->for($intention)->create(['status' => Strategy::STATUS_ACTIVE]);

    $next = new AuthoredStrategy(
        interventionPoint: Strategy::POINT_REWARD,
        approach: 'log the meal before leaving the table',
        rationale: null,
        promptVersion: 'mcp@1',
    );

    $experiment = app(StartExperiment::class)->handle($current, $next, Strategy::REASON_STACKED_ON_SUCCESS);

    $this->assertNull($experiment->review_at);
    $this->assertFalse($experiment->isUnderReview());
}

public function test_it_refuses_to_start_from_a_superseded_version(): void
{
    $current = Strategy::factory()->create(['status' => Strategy::STATUS_SUPERSEDED]);

    $next = new AuthoredStrategy(
        interventionPoint: Strategy::POINT_CUE,
        approach: 'anything',
        rationale: null,
        promptVersion: 'mcp@1',
    );

    $this->expectException(StrategyTransitionException::class);

    app(StartExperiment::class)->handle($current, $next, Strategy::REASON_STACKED_ON_SUCCESS);
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact tests/Feature/Actions/StartExperimentTest.php
```

Expected: FAIL — class `StartExperiment` not found.

- [ ] **Step 3: Rename and collapse the API**

```bash
git mv app/Actions/ReviseStrategy.php app/Actions/StartExperiment.php
```

Rename the class to `StartExperiment` and replace the two public methods with one. Keep `guardActive`, `supersedeAndCreate` and `authorActionFor` exactly as they are, adding only the `review_at` write:

```php
/**
 * Starts a new experiment on a loop: supersedes the current strategy version
 * and creates the next one.
 *
 * History is never rewritten in place. Each transition records WHY it happened,
 * WHERE in the cue → craving → response → reward chain the new version
 * intervenes, and how long it is meant to run before it gets a verdict. This
 * action is the only place those writes happen.
 */
final class StartExperiment
{
    /**
     * @param  AuthoredStrategy  $next  The hypothesis, authored in Claude and arriving through MCP.
     * @param  string  $changeReason  One of Strategy::CHANGE_REASONS.
     * @param  string|null  $supersededReason  Why the outgoing version is being replaced.
     * @param  int|null  $reviewAfterDays  Planned run length; null leaves the experiment open-ended.
     *
     * @throws StrategyTransitionException
     */
    public function handle(
        Strategy $current,
        AuthoredStrategy $next,
        string $changeReason,
        ?string $supersededReason = null,
        ?int $reviewAfterDays = null,
        ?AuthoredAction $revisedAction = null,
    ): Strategy {
        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            $changeReason,
            $supersededReason,
            $reviewAfterDays,
            $revisedAction,
        ));
    }
```

`supersedeAndCreate` already takes `$revisedAction` from Task 4, so `$reviewAfterDays` is the only new parameter. Add it and set `review_at` in the `create()` array:

```php
            'change_reason' => $changeReason,
            'review_at' => $reviewAfterDays === null ? null : now()->addDays($reviewAfterDays),
```

and pass `$revisedAction` through to `authorActionFor`.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Feature/Actions/StartExperimentTest.php
```

Expected: PASS.

- [ ] **Step 5: Confirm nothing still calls the old names**

```bash
grep -rn "ReviseStrategy\|stackOnSuccess\|restrategizeOnFailure" app tests
```

Expected: no output.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: StartExperiment replaces ReviseStrategy

Collapses stackOnSuccess and restrategizeOnFailure into one handle() that
takes the change reason directly, and sets review_at from an optional run
length. The supersede-and-create transition is unchanged.

Nothing calls this yet -- phase 4 wires it to an MCP tool and phase 3 to the
Notebook UI."
```

---

### Task 11: `ConcludeExperiment`

**Files:**
- Create: `app/Actions/ConcludeExperiment.php`
- Test: `tests/Feature/Actions/ConcludeExperimentTest.php`

**Interfaces:**
- Consumes: `Strategy` (Task 9's helpers).
- Produces: `ConcludeExperiment::handle(Strategy $strategy, string $verdict, ?string $note = null): Strategy`. Throws `InvalidArgumentException` on an unknown verdict, `StrategyTransitionException::alreadyConcluded()` on a second conclusion.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Actions/ConcludeExperimentTest.php`:

```php
<?php

namespace Tests\Feature\Actions;

use App\Actions\ConcludeExperiment;
use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ConcludeExperimentTest extends TestCase
{
    use RefreshDatabase;

    public function test_concluding_records_the_verdict_and_clears_the_review_date(): void
    {
        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
        ]);

        $concluded = app(ConcludeExperiment::class)->handle(
            $strategy,
            Strategy::VERDICT_WORKED,
            'the pause stuck once I put the fork down',
        );

        $this->assertSame(Strategy::VERDICT_WORKED, $concluded->verdict);
        $this->assertSame('the pause stuck once I put the fork down', $concluded->verdict_note);
        $this->assertNull($concluded->review_at);
        $this->assertTrue($concluded->isConcluded());
        $this->assertFalse($concluded->isUnderReview());
    }

    public function test_a_strategy_that_worked_keeps_running(): void
    {
        $strategy = Strategy::factory()->create(['status' => Strategy::STATUS_ACTIVE]);

        $concluded = app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED);

        $this->assertSame(Strategy::STATUS_ACTIVE, $concluded->status);
        $this->assertTrue($concluded->isActive());
    }

    public function test_it_rejects_an_unknown_verdict(): void
    {
        $strategy = Strategy::factory()->create(['status' => Strategy::STATUS_ACTIVE]);

        $this->expectException(InvalidArgumentException::class);

        app(ConcludeExperiment::class)->handle($strategy, 'sort-of-worked');
    }

    public function test_it_refuses_to_conclude_twice(): void
    {
        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'verdict' => Strategy::VERDICT_FAILED,
        ]);

        $this->expectException(StrategyTransitionException::class);

        app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact tests/Feature/Actions/ConcludeExperimentTest.php
```

Expected: FAIL — class `ConcludeExperiment` not found.

- [ ] **Step 3: Add the exception factory**

In `app/Services/Strategy/StrategyTransitionException.php`, alongside the existing `notActive()`:

```php
    public static function alreadyConcluded(Strategy $strategy): self
    {
        return new self("Strategy version {$strategy->version} was already concluded as [{$strategy->verdict}].");
    }
```

Add `use App\Models\Strategy;` if the file does not already import it.

- [ ] **Step 4: Write the action**

Create `app/Actions/ConcludeExperiment.php`:

```php
<?php

namespace App\Actions;

use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use InvalidArgumentException;

/**
 * Ends an experiment with a verdict.
 *
 * Concluding is not superseding: a version concluded as `worked` stays active
 * and keeps running. Only {@see StartExperiment} supersedes, and it does so
 * when the *next* experiment begins.
 */
final readonly class ConcludeExperiment
{
    /**
     * @param  string  $verdict  One of Strategy::VERDICTS.
     *
     * @throws InvalidArgumentException|StrategyTransitionException
     */
    public function handle(Strategy $strategy, string $verdict, ?string $note = null): Strategy
    {
        if (! in_array($verdict, Strategy::VERDICTS, strict: true)) {
            throw new InvalidArgumentException("[{$verdict}] is not a valid experiment verdict.");
        }

        if ($strategy->isConcluded()) {
            throw StrategyTransitionException::alreadyConcluded($strategy);
        }

        $strategy->update([
            'verdict' => $verdict,
            'verdict_note' => $note,
            'review_at' => null,
        ]);

        return $strategy->refresh();
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Feature/Actions/ConcludeExperimentTest.php
```

Expected: PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: ConcludeExperiment records a verdict on a strategy version

Concluding is deliberately not superseding: a version concluded as 'worked'
stays active and keeps running, and clearing review_at takes it out of the
under-review state. Superseding happens later, when the next experiment
starts."
```

---

### Task 12: Break `LoopProgress` down per experiment

**Files:**
- Modify: `app/Services/Progress/LoopProgress.php`
- Test: `tests/Feature/Progress/LoopProgressTest.php`

**Interfaces:**
- Consumes: `Strategy` helpers from Task 9.
- Produces: `LoopProgress::experimentsFor(Intention $loop): array` returning a list shaped as:

```php
list<array{
  strategy_id: int,
  version: int,
  status: string,
  intervention_point: string,
  approach: string,
  hypothesis: ?string,
  started_at: string,
  review_at: ?string,
  day_of_experiment: int,
  planned_days: ?int,
  is_under_review: bool,
  verdict: ?string,
  verdict_note: ?string,
  outcomes: list<array{outcome: string, reason: ?string, logged_at: string}>,
  totals: array{completed: int, failed: int, skipped: int},
}>
```

`forLoop()` is unchanged — the loops index still needs the whole-loop aggregate.

- [ ] **Step 1: Write the failing test**

Add to the LoopProgress test file:

```php
public function test_it_attributes_each_log_to_the_version_that_was_running(): void
{
    $intention = Intention::factory()->create();

    $v1 = Strategy::factory()->for($intention)->create([
        'version' => 1,
        'status' => Strategy::STATUS_SUPERSEDED,
        'verdict' => Strategy::VERDICT_FAILED,
        'verdict_note' => 'never remembered to pause',
    ]);
    $v2 = Strategy::factory()->for($intention)->create([
        'version' => 2,
        'status' => Strategy::STATUS_ACTIVE,
    ]);

    $a1 = Action::factory()->for($intention)->for($v1)->create();
    $a2 = Action::factory()->for($intention)->for($v2)->create();

    ActionLog::factory()->for($a1)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
    ActionLog::factory()->for($a1)->create(['outcome' => ActionLog::OUTCOME_FAILED, 'reason' => 'tv was on']);
    ActionLog::factory()->for($a2)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
    ActionLog::factory()->for($a2)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
    ActionLog::factory()->for($a2)->create(['outcome' => ActionLog::OUTCOME_SKIPPED]);

    $experiments = app(LoopProgress::class)->experimentsFor($intention);

    $this->assertCount(2, $experiments);

    $this->assertSame(1, $experiments[0]['version']);
    $this->assertSame(['completed' => 1, 'failed' => 1, 'skipped' => 0], $experiments[0]['totals']);
    $this->assertSame(Strategy::VERDICT_FAILED, $experiments[0]['verdict']);
    $this->assertSame('tv was on', $experiments[0]['outcomes'][1]['reason']);

    $this->assertSame(2, $experiments[1]['version']);
    $this->assertSame(['completed' => 2, 'failed' => 0, 'skipped' => 1], $experiments[1]['totals']);
    $this->assertNull($experiments[1]['verdict']);
}

public function test_it_returns_counts_not_rounded_rates(): void
{
    $intention = Intention::factory()->create();
    $strategy = Strategy::factory()->for($intention)->create(['version' => 1]);
    $action = Action::factory()->for($intention)->for($strategy)->create();

    ActionLog::factory()->count(2)->for($action)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
    ActionLog::factory()->for($action)->create(['outcome' => ActionLog::OUTCOME_FAILED]);

    $experiments = app(LoopProgress::class)->experimentsFor($intention);

    $this->assertSame(['completed' => 2, 'failed' => 1, 'skipped' => 0], $experiments[0]['totals']);
    $this->assertArrayNotHasKey('completion_rate', $experiments[0]);
}

public function test_a_version_with_no_logs_reports_empty_totals(): void
{
    $intention = Intention::factory()->create();
    Strategy::factory()->for($intention)->create(['version' => 1]);

    $experiments = app(LoopProgress::class)->experimentsFor($intention);

    $this->assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 0], $experiments[0]['totals']);
    $this->assertSame([], $experiments[0]['outcomes']);
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact --filter=experimentsFor
```

Expected: FAIL — method `experimentsFor` not defined.

- [ ] **Step 3: Implement**

Add to `app/Services/Progress/LoopProgress.php` (keep `forLoop` exactly as it is):

```php
    /**
     * One entry per strategy version, oldest first — the loop's experiment
     * ladder. Logs attribute to a version through `actions.strategy_id`, so a
     * log always belongs to the experiment that was running when it was made.
     *
     * Totals are raw counts, never a rounded rate: with a handful of logs a
     * percentage hides its own denominator, and rendering is where that
     * judgement belongs.
     *
     * @return list<array<string, mixed>>
     */
    public function experimentsFor(Intention $loop): array
    {
        $strategies = $loop->strategies()->orderedByVersion()->get();

        $logsByStrategy = ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->where('actions.intention_id', $loop->id)
            ->orderBy('action_logs.logged_at')
            ->orderBy('action_logs.id')
            ->get([
                'action_logs.outcome',
                'action_logs.reason',
                'action_logs.logged_at',
                'actions.strategy_id',
            ])
            ->groupBy('strategy_id');

        return $strategies->map(function (Strategy $strategy) use ($logsByStrategy): array {
            $logs = $logsByStrategy->get($strategy->id) ?? collect();

            return [
                'strategy_id' => $strategy->id,
                'version' => $strategy->version,
                'status' => $strategy->status,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'hypothesis' => $strategy->rationale,
                'started_at' => $strategy->created_at->toIso8601String(),
                'review_at' => $strategy->review_at?->toIso8601String(),
                'day_of_experiment' => $strategy->dayOfExperiment(),
                'planned_days' => $strategy->plannedDays(),
                'is_under_review' => $strategy->isUnderReview(),
                'verdict' => $strategy->verdict,
                'verdict_note' => $strategy->verdict_note,
                'outcomes' => $logs->map(fn (ActionLog $log): array => [
                    'outcome' => $log->outcome,
                    'reason' => $log->reason,
                    'logged_at' => $log->logged_at->toIso8601String(),
                ])->values()->all(),
                'totals' => [
                    'completed' => $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count(),
                    'failed' => $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count(),
                    'skipped' => $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count(),
                ],
            ];
        })->values()->all();
    }
```

Add `use App\Models\Strategy;` to the imports.

Note the join reads `actions.intention_id` rather than walking the `actionLogs` relation, so a log whose action lost its strategy is simply absent from every band rather than crashing.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Feature/Progress
```

Expected: PASS, including the pre-existing `forLoop` tests.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: break loop progress down per experiment

Adds experimentsFor(), the ladder the lab record and the MCP journal both
read: one entry per strategy version with its hypothesis, dates, verdict, its
own logs (reasons included) and raw counts.

Counts, not rounded rates -- at this sample size a percentage hides its
denominator, and that call belongs to rendering."
```

---

### Task 13: Expose the experiment on `StrategyResource`

**Files:**
- Modify: `app/Http/Resources/StrategyResource.php`
- Test: `tests/Feature/Progress/ProgressShowTest.php`

**Interfaces:**
- Produces: the resource now carries `review_at`, `verdict`, `verdict_note`, `day_of_experiment`, `planned_days`, `is_under_review`.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_strategy_resource_carries_the_experiment_fields(): void
{
    CarbonImmutable::setTestNow('2026-09-13 12:00:00');

    $user = User::factory()->create();
    $intention = Intention::factory()->for($user)->create();
    Strategy::factory()->for($intention)->create([
        'version' => 1,
        'status' => Strategy::STATUS_ACTIVE,
        'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
    ]);

    $this->actingAs($user)
        ->get(route('progress.show', $intention))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('strategies.0.planned_days', 21)
            ->where('strategies.0.day_of_experiment', 12)
            ->where('strategies.0.is_under_review', false)
            ->where('strategies.0.verdict', null));
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --compact --filter=test_the_strategy_resource_carries_the_experiment_fields
```

Expected: FAIL — the keys are missing.

- [ ] **Step 3: Extend the resource**

In `app/Http/Resources/StrategyResource.php`, add after `'superseded_reason'`:

```php
            'review_at' => $this->review_at,
            'verdict' => $this->verdict,
            'verdict_note' => $this->verdict_note,
            'day_of_experiment' => $this->resource->dayOfExperiment(),
            'planned_days' => $this->resource->plannedDays(),
            'is_under_review' => $this->resource->isUnderReview(),
```

Update the class docblock: it now carries the experiment framing, not just the timeline provenance.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Feature/Progress
```

Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: expose the experiment fields on StrategyResource

The verdict, the planned length and the day count travel with every strategy
version now, so the phase 3 lab record and the phase 4 MCP tools read the same
shape."
```

---

## Phase 2 gate

- [ ] **Run the whole suite**

```bash
php artisan test --compact
npm run test
vendor/bin/pint --dirty --format agent
```

Expected: all green.

- [ ] **Confirm the app really is LLM-free**

```bash
grep -rn "anthropic\|ANTHROPIC\|App\\\\Ai\|Services\\\\Coach" app config bootstrap routes tests .env.example
```

Expected: no output. `composer.json` may still list an SDK — removing a dependency needs the owner's approval, so leave it and flag it in the handoff.

- [ ] **Merge to main locally** (per the owner's standing preference — no PR)

```bash
git checkout main
git merge --no-ff worktree-notebook-reframe
php artisan test --compact
```

## What is deliberately not wired up yet

`StartExperiment` and `ConcludeExperiment` have no caller. That is expected: Phase 4 gives them MCP tools and Phase 3 gives them buttons. Until one lands, starting a new experiment requires tinker — which is why the spec recommends doing **Phase 4 before Phase 3**.
