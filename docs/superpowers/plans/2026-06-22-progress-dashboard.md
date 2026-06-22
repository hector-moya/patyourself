# SP5 — Progress Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only progress dashboard — a new `/progress` route and 4th bottom-nav tab — that surfaces each active loop's momentum (streak, completion rate, recent activity), the strategy journey, and the coach's rolling narrative.

**Architecture:** Pure read-side. One aggregation service (`LoopProgress`) composes existing models into a per-loop metric block; a `ProgressController` renders an index of active-loop cards and an owner-gated drill-in detail. The detail reuses the existing strategy-timeline component (extracted into a shared module) and the `StrategyResource`. No new domain logic, writes, LLM calls, models, migrations, or schema.

**Tech Stack:** Laravel 13 (PHP 8.4), Inertia v3 + React 19, Tailwind v4, PHPUnit 12, vitest + Testing Library, Pint.

## Global Constraints

- App served by Herd at `https://patyourself.test`. NEVER run `php artisan serve`; only `npm run dev` for Vite.
- After editing PHP: `vendor/bin/pint --dirty --format agent` (never `--test`).
- Format only touched JS/TS via `npx prettier --write <files>`. Never run a whole-tree formatter.
- Frontend tooling needs Node 22 — prefix every npm/npx with `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH"`.
- `tsc` has pre-existing Wayfinder `@/routes` / `@/actions` codegen errors — ignore those; only new errors in touched files matter.
- Streak is **active-strategy-scoped** (via `OutcomeStreak`); completion rate + totals are **loop-lifetime**. `skipped` is neutral: excluded from the rate, kept in the recent strip.
- Commit trailer on every commit: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Conventions (apply to every task)

- **PHP feature tests** extend `Tests\TestCase`, `use RefreshDatabase`, and call `$this->withoutVite();` in `setUp()` (page renders need no Vite manifest — `assertInertia` checks props server-side, the React component is never resolved).
- **Run one PHP test file:** `php artisan test --compact --filter=<ClassName>`.
- **Run one vitest file:** `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run <path>`.
- **vitest page tests** mock `@inertiajs/react` (stub `Head` → `() => null` and `usePage` → a mutable `page` object) because `CoachLayout`'s `<Head>` and `BottomNav`'s `usePage` need Inertia context. Copy the mock block from `resources/js/pages/inbox.test.tsx`.
- **Commit** at the end of each task with the trailer above.

---

## File Structure

**New (backend)**
- `app/Services/Progress/LoopProgress.php` — per-loop metric aggregation (the only new "logic").
- `app/Http/Controllers/ProgressController.php` — `index` (active-loop cards) + `show` (owner-gated detail).
- `tests/Feature/Progress/LoopProgressTest.php` — the service's behavior.
- `tests/Feature/Progress/ProgressIndexTest.php` — the index route/controller.
- `tests/Feature/Progress/ProgressShowTest.php` — the detail route/controller + gate.

**New (frontend)**
- `resources/js/patyourself/strategy-timeline.tsx` — the journey timeline, extracted from `intentions/show.tsx` and shared.
- `resources/js/patyourself/strategy-timeline.test.tsx` — guards the extraction.
- `resources/js/patyourself/progress/outcome-strip.tsx` — the recent-activity sparkline.
- `resources/js/patyourself/progress/streak-badge.tsx` — the streak pill.
- `resources/js/patyourself/progress/progress-card.tsx` — one index card (links to detail).
- `resources/js/pages/progress/index.tsx` — the index page.
- `resources/js/pages/progress/index.test.tsx` — index page test.
- `resources/js/pages/progress/show.tsx` — the detail page.
- `resources/js/pages/progress/show.test.tsx` — detail page test.

**Modified**
- `routes/web.php` — two `progress` routes.
- `resources/js/patyourself/types.ts` — `OutcomeMark`, `LoopStreak`, `LoopProgressCard`, `LoopProgressDetail`.
- `resources/js/patyourself/bottom-nav.tsx` — the Progress tab.
- `resources/js/patyourself/bottom-nav.test.tsx` — Progress-tab assertions.
- `resources/js/pages/intentions/show.tsx` — import the extracted timeline instead of its local copy.

**Deleted**
- `resources/js/pages/dashboard.tsx` — orphaned Laravel starter placeholder.

**Reused unchanged**
- `app/Services/Coach/OutcomeStreak.php`, `app/Http/Resources/StrategyResource.php`, `app/Policies/IntentionPolicy.php`.

---

## Task 1: `LoopProgress` aggregation service

**Files:**
- Create: `app/Services/Progress/LoopProgress.php`
- Test: `tests/Feature/Progress/LoopProgressTest.php`

**Interfaces:**
- Consumes: `App\Services\Coach\OutcomeStreak::forStrategy(Strategy): array{0:?string,1:int,2:?string}` (reused, unchanged); `Intention::$actionLogs`, `Intention::$activeStrategy` (existing relations, eager-loaded by the caller).
- Produces: `LoopProgress::forLoop(Intention $loop): array{streak: array{outcome: ?string, length: int}, completion_rate: ?int, totals: array{completed:int, failed:int, skipped:int}, recent: list<string>, last_logged_at: ?string}`. Tasks 2 and 3 spread this into their props.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Progress/LoopProgressTest.php`:

```php
<?php

namespace Tests\Feature\Progress;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Progress\LoopProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopProgressTest extends TestCase
{
    use RefreshDatabase;

    /** Build a loop with an active strategy + one action, return [loop, action]. */
    private function loopWithAction(): array
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        return [$loop, $action];
    }

    private function report(Intention $loop): array
    {
        $loop->load(['activeStrategy', 'latestSummary', 'actionLogs']);

        return app(LoopProgress::class)->forLoop($loop);
    }

    public function test_completion_rate_excludes_skips(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        ActionLog::factory()->for($action)->skipped()->create();

        $report = $this->report($loop);

        $this->assertSame(100, $report['completion_rate']);
        $this->assertSame(['completed' => 2, 'failed' => 0, 'skipped' => 1], $report['totals']);
    }

    public function test_completion_rate_is_rounded_share_of_decided_logs(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        ActionLog::factory()->for($action)->failed()->create();

        $this->assertSame(67, $this->report($loop)['completion_rate']);
    }

    public function test_completion_rate_is_null_without_decided_logs(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->skipped()->count(2)->create();

        $report = $this->report($loop);

        $this->assertNull($report['completion_rate']);
        $this->assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 2], $report['totals']);
    }

    public function test_streak_counts_the_active_strategys_leading_run(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(3)->create();

        $report = $this->report($loop);

        $this->assertSame('completed', $report['streak']['outcome']);
        $this->assertSame(3, $report['streak']['length']);
    }

    public function test_streak_ignores_logs_on_a_superseded_strategy(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $old = Strategy::factory()->for($loop)->superseded()->create(['version' => 1]);
        $active = Strategy::factory()->for($loop)->create(['version' => 2, 'status' => Strategy::STATUS_ACTIVE]);
        $oldAction = Action::factory()->for($loop)->for($old)->create();
        $newAction = Action::factory()->for($loop)->for($active)->create();
        ActionLog::factory()->for($oldAction)->completed()->count(5)->create();
        ActionLog::factory()->for($newAction)->completed()->create();

        // Streak is the active strategy's run (1), not the loop's lifetime (6).
        $this->assertSame(1, $this->report($loop)['streak']['length']);
    }

    public function test_streak_is_zero_without_an_active_strategy(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $retired = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_RETIRED]);
        $action = Action::factory()->for($loop)->for($retired)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();

        $report = $this->report($loop);

        $this->assertNull($report['streak']['outcome']);
        $this->assertSame(0, $report['streak']['length']);
        // Lifetime rate is still computed even with no active strategy.
        $this->assertSame(100, $report['completion_rate']);
    }

    public function test_recent_strip_is_oldest_to_newest_capped_at_ten(): void
    {
        [$loop, $action] = $this->loopWithAction();
        // 12 logs, one per day; outcomes alternate so order is observable.
        foreach (range(1, 12) as $day) {
            ActionLog::factory()->for($action)->create([
                'outcome' => $day % 2 === 0 ? ActionLog::OUTCOME_COMPLETED : ActionLog::OUTCOME_FAILED,
                'logged_at' => now()->subDays(20 - $day), // day 12 is the most recent
            ]);
        }

        $recent = $this->report($loop)['recent'];

        $this->assertCount(10, $recent);
        // Newest 10 are days 3..12; oldest-first means day 3 (odd → failed) leads,
        // day 12 (even → completed) is last.
        $this->assertSame(ActionLog::OUTCOME_FAILED, $recent[0]);
        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $recent[9]);
    }

    public function test_last_logged_at_is_the_most_recent_log(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->create(['logged_at' => now()->subDays(2)]);
        $latest = ActionLog::factory()->for($action)->completed()->create(['logged_at' => now()->subHour()]);

        $this->assertSame(
            $latest->logged_at->toIso8601String(),
            $this->report($loop)['last_logged_at'],
        );
    }

    public function test_empty_loop_reports_zeroed_metrics(): void
    {
        [$loop] = $this->loopWithAction(); // no logs

        $report = $this->report($loop);

        $this->assertNull($report['completion_rate']);
        $this->assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 0], $report['totals']);
        $this->assertSame([], $report['recent']);
        $this->assertNull($report['last_logged_at']);
        $this->assertSame(0, $report['streak']['length']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=LoopProgressTest`
Expected: FAIL — `Class "App\Services\Progress\LoopProgress" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `app/Services/Progress/LoopProgress.php`:

```php
<?php

namespace App\Services\Progress;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Services\Coach\OutcomeStreak;

/**
 * Read-side aggregation for one loop's progress card. Pure: no writes, no model
 * calls. Streak delegates to OutcomeStreak (the active-strategy leading run);
 * rate and totals span the loop's whole lifetime so they survive strategy
 * revisions. `skipped` outcomes are neutral — excluded from the rate, kept in
 * the recent strip. The caller eager-loads `activeStrategy` and `actionLogs`.
 */
final class LoopProgress
{
    public function __construct(private OutcomeStreak $streak) {}

    /**
     * @return array{
     *   streak: array{outcome: ?string, length: int},
     *   completion_rate: ?int,
     *   totals: array{completed: int, failed: int, skipped: int},
     *   recent: list<string>,
     *   last_logged_at: ?string,
     * }
     */
    public function forLoop(Intention $loop): array
    {
        $logs = $loop->actionLogs;

        $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();
        $failed = $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count();
        $skipped = $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count();

        $decided = $completed + $failed;

        [$outcome, $length] = $loop->activeStrategy === null
            ? [null, 0]
            : $this->streak->forStrategy($loop->activeStrategy);

        // The newest 10 logs, re-ordered oldest → newest so the strip reads left-to-right.
        $recent = $logs
            ->sortByDesc('logged_at')
            ->take(10)
            ->reverse()
            ->pluck('outcome')
            ->values()
            ->all();

        return [
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $decided === 0 ? null : (int) round($completed / $decided * 100),
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            'recent' => $recent,
            'last_logged_at' => $logs->max('logged_at')?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=LoopProgressTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Services/Progress/LoopProgress.php tests/Feature/Progress/LoopProgressTest.php
git commit -m "feat(sp5): LoopProgress read service for per-loop metrics

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Progress index route + controller

**Files:**
- Modify: `routes/web.php` (add inside the existing `auth`+`verified` group)
- Create: `app/Http/Controllers/ProgressController.php` (index only this task; show added in Task 3)
- Test: `tests/Feature/Progress/ProgressIndexTest.php`

**Interfaces:**
- Consumes: `LoopProgress::forLoop()` (Task 1); `User::intentions()->active()` scope; `Intention::$latestSummary`.
- Produces: Inertia component `progress/index` with prop `loops: list<array{id:int, title:string, type:string, streak:..., completion_rate:?int, totals:..., recent:list<string>, last_logged_at:?string, summary_excerpt:?string}>`. Task 5's `progress/index.tsx` consumes this shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Progress/ProgressIndexTest.php`:

```php
<?php

namespace Tests\Feature\Progress;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lists_only_the_users_active_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);
        Intention::factory()->for($user)->completed()->create();
        Intention::factory()->create(); // another user's active loop

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/index')
                ->has('loops', 2)
            );
    }

    public function test_card_carries_computed_metrics(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE, 'title' => 'Morning walk']);
        $strategy = Strategy::factory()->initial()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        ActionLog::factory()->for($action)->failed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.title', 'Morning walk')
                ->where('loops.0.completion_rate', 67)
                ->where('loops.0.totals.completed', 2)
                ->where('loops.0.totals.failed', 1)
                ->where('loops.0.streak.outcome', 'failed')
            );
    }

    public function test_summary_excerpt_is_the_trimmed_first_line(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => "First line of the summary.\nSecond line that is hidden.",
        ]);

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.summary_excerpt', 'First line of the summary.')
            );
    }

    public function test_loop_without_logs_reports_null_rate_and_empty_recent(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.completion_rate', null)
                ->where('loops.0.recent', [])
                ->where('loops.0.summary_excerpt', null)
            );
    }

    public function test_renders_with_no_active_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('progress/index')->has('loops', 0));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/progress')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ProgressIndexTest`
Expected: FAIL — route `/progress` not defined (404 / `RouteNotFoundException`).

- [ ] **Step 3: Create the controller (index)**

Create `app/Http/Controllers/ProgressController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only progress dashboard. `index` lists the user's active loops as
 * metric cards; `show` (Task 3) drills into one owned loop's full metrics,
 * strategy journey, and rolling narrative. Pure read — every write stays on its
 * existing screen.
 */
class ProgressController extends Controller
{
    public function index(Request $request, LoopProgress $progress): Response
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

        return Inertia::render('progress/index', ['loops' => $loops]);
    }

    /** First line of the rolling summary, trimmed for the index card. */
    private function excerpt(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return Str::limit(trim(strtok($content, "\n")), 120);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\ProgressController;
```

Inside the `Route::middleware(['auth', 'verified'])->group(function () { ... })` block, after the intentions resource block, add:

```php
    // The progress dashboard: active-loop metric cards (index) and a per-loop
    // drill-in (detail). Read-only aggregation over the loop's own data.
    Route::get('progress', [ProgressController::class, 'index'])->name('progress');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ProgressIndexTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ProgressController.php routes/web.php tests/Feature/Progress/ProgressIndexTest.php
git commit -m "feat(sp5): progress index route + controller

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Progress detail route + controller (owner-gated)

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ProgressController.php` (add `show`)
- Test: `tests/Feature/Progress/ProgressShowTest.php`

**Interfaces:**
- Consumes: `LoopProgress::forLoop()` (Task 1); `StrategyResource` (reused); `IntentionPolicy::view` via `Gate::authorize('view', $intention)`; `Intention::strategies()->orderedByVersion()`; `Intention::$latestSummary`.
- Produces: Inertia component `progress/show` with props `intention: array{id,title,type, ...metrics}`, `strategies: list<StrategyResource>`, `summary: ?string`. Task 6's `progress/show.tsx` consumes this.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Progress/ProgressShowTest.php`:

```php
<?php

namespace Tests\Feature\Progress;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_sees_metrics_journey_and_narrative(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE, 'title' => 'Morning walk']);
        $v1 = Strategy::factory()->for($loop)->superseded('kept missing it')->create(['version' => 1]);
        $v2 = Strategy::factory()->for($loop)->restrategized()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'parent_strategy_id' => $v1->id,
        ]);
        $action = Action::factory()->for($loop)->for($v2)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => 'You complete most mornings.',
        ]);

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/show')
                ->where('intention.title', 'Morning walk')
                ->where('intention.completion_rate', 100)
                ->where('intention.streak.length', 2)
                ->has('strategies', 2)
                ->where('strategies.0.version', 1) // ordered oldest-first
                ->where('strategies.1.version', 2)
                ->where('summary', 'You complete most mornings.')
            );
    }

    public function test_summary_is_null_when_absent(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertInertia(fn (Assert $page) => $page->where('summary', null));
    }

    public function test_serves_a_non_active_owned_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)->get("/progress/{$loop->id}")->assertOk();
    }

    public function test_forbids_viewing_another_users_loop(): void
    {
        $owner = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs(User::factory()->create())
            ->get("/progress/{$loop->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);

        $this->get("/progress/{$loop->id}")->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ProgressShowTest`
Expected: FAIL — route `/progress/{intention}` not defined.

- [ ] **Step 3: Add the `show` method**

In `app/Http/Controllers/ProgressController.php`, add these imports:

```php
use App\Http\Resources\StrategyResource;
use Illuminate\Support\Facades\Gate;
```

Add the method after `index`:

```php
    public function show(Intention $intention, LoopProgress $progress): Response
    {
        Gate::authorize('view', $intention);

        $intention->load(['activeStrategy', 'latestSummary', 'actionLogs']);
        $strategies = $intention->strategies()->orderedByVersion()->get();

        return Inertia::render('progress/show', [
            'intention' => [
                'id' => $intention->id,
                'title' => $intention->title,
                'type' => $intention->type,
                ...$progress->forLoop($intention),
            ],
            'strategies' => StrategyResource::collection($strategies)->resolve(),
            'summary' => $intention->latestSummary?->content,
        ]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, directly below the `progress` index route, add:

```php
    Route::get('progress/{intention}', [ProgressController::class, 'show'])->name('progress.show');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ProgressShowTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ProgressController.php routes/web.php tests/Feature/Progress/ProgressShowTest.php
git commit -m "feat(sp5): owner-gated progress detail route + controller

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Frontend types, shared strategy-timeline, delete the placeholder

**Files:**
- Modify: `resources/js/patyourself/types.ts`
- Create: `resources/js/patyourself/strategy-timeline.tsx` (moved out of `intentions/show.tsx`)
- Create: `resources/js/patyourself/strategy-timeline.test.tsx`
- Modify: `resources/js/pages/intentions/show.tsx` (import the extracted component, delete its local copy)
- Delete: `resources/js/pages/dashboard.tsx`

**Interfaces:**
- Consumes: existing `StrategyData` type.
- Produces: TS types `OutcomeMark`, `LoopStreak`, `LoopProgressCard`, `LoopProgressDetail` (consumed by Tasks 5, 6); exported components `StrategyTimeline` and `SectionHeading` from `@/patyourself/strategy-timeline` (consumed by Task 6 and `intentions/show.tsx`).

- [ ] **Step 1: Add the new types**

Append to `resources/js/patyourself/types.ts`:

```ts
/** One outcome mark in a progress sparkline. Mirrors ActionLog's OUTCOME_* values. */
export type OutcomeMark = 'completed' | 'failed' | 'skipped';

/** The active strategy's leading run (from OutcomeStreak), as shown on a progress card. */
export interface LoopStreak {
    outcome: 'completed' | 'failed' | null;
    length: number;
}

/** One active loop's metric card on the progress index (mirrors ProgressController@index). */
export interface LoopProgressCard {
    id: number;
    title: string;
    type: string;
    streak: LoopStreak;
    completion_rate: number | null; // 0–100, null when no decided logs
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[]; // oldest → newest, max 10
    last_logged_at: string | null;
    summary_excerpt: string | null;
}

/** The same metric block on the detail screen (no index-only excerpt). */
export type LoopProgressDetail = Omit<LoopProgressCard, 'summary_excerpt'>;
```

- [ ] **Step 2: Write the failing test for the extracted component**

Create `resources/js/patyourself/strategy-timeline.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StrategyData } from '@/patyourself/types';
import { StrategyTimeline } from './strategy-timeline';

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 1,
        version: 1,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: 'initial',
        superseded_reason: null,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('StrategyTimeline', () => {
    it('renders a node per version with its change-reason copy', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({ id: 1, version: 1, status: 'superseded', superseded_reason: 'kept missing it' }),
                    strategy({ id: 2, version: 2, status: 'active', change_reason: 'restrategized_on_failure' }),
                ]}
            />,
        );

        expect(screen.getByText(/v1/)).toBeInTheDocument();
        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(screen.getByText('Restrategized after a setback')).toBeInTheDocument();
        expect(screen.getByText(/kept missing it/)).toBeInTheDocument();
    });

    it('shows an empty state when there are no strategies', () => {
        render(<StrategyTimeline strategies={[]} />);

        expect(screen.getByText(/no strategy yet/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/strategy-timeline.test.tsx`
Expected: FAIL — cannot resolve `./strategy-timeline`.

- [ ] **Step 4: Create the shared component (moved verbatim from `intentions/show.tsx`)**

Create `resources/js/patyourself/strategy-timeline.tsx`:

```tsx
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import type { StrategyData } from '@/patyourself/types';

const CHANGE_REASON: Record<string, string> = {
    initial: 'Starting point',
    stacked_on_success: 'Stacked on success',
    restrategized_on_failure: 'Restrategized after a setback',
};

/**
 * The versioned strategy history as a vertical timeline (oldest → newest,
 * top-down, the active version flagged). Read-only: history is only ever
 * appended to. Shared by the loop-detail and progress-detail screens.
 */
export function StrategyTimeline({ strategies }: { strategies: StrategyData[] }) {
    return (
        <section>
            <SectionHeading>
                Strategy timeline
                <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                    ({strategies.length})
                </span>
            </SectionHeading>

            {strategies.length === 0 ? (
                <p className="text-sm text-muted-foreground">No strategy yet.</p>
            ) : (
                <ol className="flex flex-col">
                    {strategies.map((strategy, index) => (
                        <TimelineNode
                            key={strategy.id}
                            strategy={strategy}
                            last={index === strategies.length - 1}
                        />
                    ))}
                </ol>
            )}
        </section>
    );
}

function TimelineNode({
    strategy,
    last,
}: {
    strategy: StrategyData;
    last: boolean;
}) {
    const active = strategy.status === 'active';

    return (
        <li className="flex gap-3">
            <div className="flex flex-col items-center">
                <span
                    className={cn(
                        'mt-1 size-3 shrink-0 rounded-full border-2',
                        active
                            ? 'border-primary bg-primary'
                            : 'border-border bg-background',
                    )}
                />
                {!last && <span className="my-1 w-px flex-1 bg-border" />}
            </div>

            <div className="flex-1 pb-4">
                <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">
                        v{strategy.version} · {strategy.intervention_point}
                    </span>
                    {active && (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                            active
                        </span>
                    )}
                </div>

                <p className="mt-1 text-sm text-foreground">{strategy.approach}</p>

                {strategy.change_reason && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {CHANGE_REASON[strategy.change_reason] ??
                            strategy.change_reason}
                    </p>
                )}

                {strategy.superseded_reason && (
                    <p className="mt-1 text-xs text-muted-foreground/80 italic">
                        “{strategy.superseded_reason}”
                    </p>
                )}
            </div>
        </li>
    );
}

export function SectionHeading({ children }: { children: ReactNode }) {
    return (
        <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </h2>
    );
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/strategy-timeline.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 6: Update `intentions/show.tsx` to use the shared component**

In `resources/js/pages/intentions/show.tsx`:
1. Add the import (next to the other `@/patyourself` imports):

```tsx
import { SectionHeading, StrategyTimeline } from '@/patyourself/strategy-timeline';
```

2. **Delete** the now-duplicated local definitions from this file: the `CHANGE_REASON` constant, the `StrategyTimeline` function, the `TimelineNode` function, and the `SectionHeading` function. (`Anatomy` keeps using the imported `SectionHeading`; `cn` and the `Badge` component stay.)

- [ ] **Step 7: Verify the loop-detail page still compiles and its backend test still passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx tsc --noEmit 2>&1 | grep -E "intentions/show|strategy-timeline" || echo "no new errors in touched files"`
Expected: `no new errors in touched files` (ignore pre-existing `@/routes` / `@/actions` Wayfinder errors elsewhere).

Run: `php artisan test --compact --filter=IntentionScreensTest`
Expected: PASS (unchanged — the controller and props are untouched).

- [ ] **Step 8: Delete the orphaned placeholder**

```bash
rm resources/js/pages/dashboard.tsx
```

Verify nothing references it:

Run: `grep -rn "pages/dashboard" resources/js && echo "FOUND — investigate" || echo "no references"`
Expected: `no references` (the `dashboard` *route* helper from `@/routes` is unrelated and stays).

- [ ] **Step 9: Format the touched TS/TSX**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/patyourself/types.ts resources/js/patyourself/strategy-timeline.tsx resources/js/patyourself/strategy-timeline.test.tsx resources/js/pages/intentions/show.tsx`

- [ ] **Step 10: Commit**

```bash
git add resources/js/patyourself/types.ts resources/js/patyourself/strategy-timeline.tsx resources/js/patyourself/strategy-timeline.test.tsx resources/js/pages/intentions/show.tsx
git rm resources/js/pages/dashboard.tsx
git commit -m "refactor(sp5): extract shared StrategyTimeline, add progress types, drop dead dashboard page

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Progress index page, cards, and the Progress nav tab

**Files:**
- Create: `resources/js/patyourself/progress/outcome-strip.tsx`
- Create: `resources/js/patyourself/progress/streak-badge.tsx`
- Create: `resources/js/patyourself/progress/progress-card.tsx`
- Create: `resources/js/pages/progress/index.tsx`
- Create: `resources/js/pages/progress/index.test.tsx`
- Modify: `resources/js/patyourself/bottom-nav.tsx`
- Modify: `resources/js/patyourself/bottom-nav.test.tsx`

**Interfaces:**
- Consumes: `LoopProgressCard`, `LoopStreak`, `OutcomeMark` (Task 4); `Icon` from `@/patyourself/primitives`; `CoachLayout`, `BottomNav`.
- Produces: default page component `progress/index` rendering `loops: LoopProgressCard[]` (matches Task 2's prop). Each card links to `/progress/{id}`.

- [ ] **Step 1: Write the failing index-page test**

Create `resources/js/pages/progress/index.test.tsx`:

```tsx
import * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { LoopProgressCard } from '@/patyourself/types';

const page = { url: '/progress', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressIndex from './index';

function card(overrides: Partial<LoopProgressCard> = {}): LoopProgressCard {
    return {
        id: 3,
        title: 'Morning walk',
        type: 'build',
        streak: { outcome: 'completed', length: 5 },
        completion_rate: 82,
        totals: { completed: 12, failed: 2, skipped: 1 },
        recent: ['completed', 'completed', 'failed', 'completed'],
        last_logged_at: '2026-06-22T07:00:00Z',
        summary_excerpt: 'You complete most mornings.',
        ...overrides,
    };
}

describe('ProgressIndex', () => {
    it('renders a card per active loop with its streak, rate and sparkline', () => {
        render(<ProgressIndex loops={[card()]} />);

        expect(screen.getByText('Morning walk')).toBeInTheDocument();
        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(screen.getByText(/5 in a row/)).toBeInTheDocument();
        expect(screen.getByTestId('outcome-strip')).toBeInTheDocument();
    });

    it('links a card to its detail screen', () => {
        render(<ProgressIndex loops={[card({ id: 7 })]} />);

        expect(screen.getByText('Morning walk').closest('a')).toHaveAttribute('href', '/progress/7');
    });

    it('shows "No activity yet" and a dash rate for a loop with no logs', () => {
        render(
            <ProgressIndex
                loops={[card({ completion_rate: null, recent: [], streak: { outcome: null, length: 0 } })]}
            />,
        );

        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.getByText(/no activity yet/i)).toBeInTheDocument();
    });

    it('shows the empty state with a coach CTA when there are no loops', () => {
        render(<ProgressIndex loops={[]} />);

        expect(screen.getByText(/no active loops yet/i)).toBeInTheDocument();
        expect(screen.getByText(/start a loop with your coach/i).closest('a')).toHaveAttribute('href', '/dashboard');
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/progress/index.test.tsx`
Expected: FAIL — cannot resolve `./index`.

- [ ] **Step 3: Create the `OutcomeStrip` component**

Create `resources/js/patyourself/progress/outcome-strip.tsx`:

```tsx
import { cn } from '@/lib/utils';
import type { OutcomeMark } from '@/patyourself/types';

const MARK: Record<OutcomeMark, { glyph: string; className: string; label: string }> = {
    completed: { glyph: '●', className: 'text-primary', label: 'completed' },
    failed: { glyph: '×', className: 'text-destructive', label: 'failed' },
    skipped: { glyph: '–', className: 'text-muted-foreground/60', label: 'skipped' },
};

/** The recent-activity sparkline: the last N outcomes, oldest → newest. */
export function OutcomeStrip({ recent }: { recent: OutcomeMark[] }) {
    if (recent.length === 0) {
        return <p className="text-xs text-muted-foreground">No activity yet</p>;
    }

    return (
        <div
            data-testid="outcome-strip"
            className="flex items-center gap-1"
            aria-label="Recent activity"
        >
            {recent.map((mark, index) => (
                <span
                    key={index}
                    className={cn('text-sm leading-none', MARK[mark].className)}
                    aria-label={MARK[mark].label}
                    title={MARK[mark].label}
                >
                    {MARK[mark].glyph}
                </span>
            ))}
        </div>
    );
}
```

- [ ] **Step 4: Create the `StreakBadge` component**

Create `resources/js/patyourself/progress/streak-badge.tsx`:

```tsx
import { Icon } from '@/patyourself/primitives';
import type { LoopStreak } from '@/patyourself/types';

/** The streak pill: a win run (▲, primary), a miss run (▽, muted caution), or none. */
export function StreakBadge({ streak }: { streak: LoopStreak }) {
    if (streak.outcome === 'completed' && streak.length > 0) {
        return (
            <span className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                <Icon name="trending-up" size={16} />
                {streak.length} in a row
            </span>
        );
    }

    if (streak.outcome === 'failed' && streak.length > 0) {
        return (
            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                <Icon name="trending-down" size={16} />
                {streak.length} missed — restart
            </span>
        );
    }

    return <span className="text-sm text-muted-foreground">No streak yet</span>;
}
```

- [ ] **Step 5: Create the `ProgressCard` component**

Create `resources/js/patyourself/progress/progress-card.tsx`:

```tsx
import { Link } from '@inertiajs/react';

import type { LoopProgressCard } from '@/patyourself/types';
import { OutcomeStrip } from './outcome-strip';
import { StreakBadge } from './streak-badge';

/** One active loop on the progress index — metrics + a one-line narrative, linking to its detail. */
export function ProgressCard({ loop }: { loop: LoopProgressCard }) {
    return (
        <Link
            href={`/progress/${loop.id}`}
            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 transition-colors hover:border-primary/40"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-sm font-medium text-foreground">{loop.title}</span>
                <span className="shrink-0 text-sm text-muted-foreground">
                    {loop.completion_rate === null ? '—' : `${loop.completion_rate}%`}
                </span>
            </div>
            <StreakBadge streak={loop.streak} />
            <OutcomeStrip recent={loop.recent} />
            {loop.summary_excerpt && (
                <p className="line-clamp-1 text-xs text-muted-foreground">
                    {loop.summary_excerpt}
                </p>
            )}
        </Link>
    );
}
```

- [ ] **Step 6: Create the index page**

Create `resources/js/pages/progress/index.tsx`:

```tsx
import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ProgressCard } from '@/patyourself/progress/progress-card';
import type { LoopProgressCard } from '@/patyourself/types';

interface ProgressIndexProps {
    loops: LoopProgressCard[];
}

/**
 * Progress dashboard — a stack of active-loop metric cards (streak, completion
 * rate, recent-activity sparkline, narrative snippet), each linking to the
 * loop's detail. Read-only.
 */
export default function ProgressIndex({ loops }: ProgressIndexProps) {
    return (
        <CoachLayout title="Progress" bottomNav={<BottomNav />}>
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
        </CoachLayout>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-8 text-center">
            <p className="text-sm text-muted-foreground">No active loops yet.</p>
            <Link href="/dashboard" className="text-sm font-medium text-primary">
                Start a loop with your coach
            </Link>
        </div>
    );
}
```

- [ ] **Step 7: Run the index-page test to verify it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/progress/index.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 8: Add the Progress tab to the bottom nav (extend its test first)**

Append these tests to `resources/js/patyourself/bottom-nav.test.tsx` (inside the `describe('BottomNav', …)` block):

```tsx
    it('renders the Progress tab', () => {
        page.url = '/dashboard';
        render(<BottomNav />);

        expect(screen.getByText('Progress')).toBeInTheDocument();
    });

    it('marks the Progress tab active on a progress detail route', () => {
        page.url = '/progress/7';
        render(<BottomNav />);

        expect(screen.getByText('Progress').closest('a')).toHaveAttribute('aria-current', 'page');
        page.url = '/dashboard';
    });
```

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/bottom-nav.test.tsx`
Expected: FAIL — no "Progress" text yet.

- [ ] **Step 9: Add the tab**

In `resources/js/patyourself/bottom-nav.tsx`, insert into the `TABS` array between the `Loops` and `Inbox` entries:

```tsx
    {
        label: 'Progress',
        icon: 'trending-up',
        href: '/progress',
        match: ['/progress'],
    },
```

- [ ] **Step 10: Run the bottom-nav test to verify it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/patyourself/bottom-nav.test.tsx`
Expected: PASS (existing + 2 new).

- [ ] **Step 11: Format the touched files**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/patyourself/progress/outcome-strip.tsx resources/js/patyourself/progress/streak-badge.tsx resources/js/patyourself/progress/progress-card.tsx resources/js/pages/progress/index.tsx resources/js/pages/progress/index.test.tsx resources/js/patyourself/bottom-nav.tsx resources/js/patyourself/bottom-nav.test.tsx`

- [ ] **Step 12: Commit**

```bash
git add resources/js/patyourself/progress resources/js/pages/progress/index.tsx resources/js/pages/progress/index.test.tsx resources/js/patyourself/bottom-nav.tsx resources/js/patyourself/bottom-nav.test.tsx
git commit -m "feat(sp5): progress index page, metric cards, and Progress nav tab

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Progress detail page

**Files:**
- Create: `resources/js/pages/progress/show.tsx`
- Create: `resources/js/pages/progress/show.test.tsx`

**Interfaces:**
- Consumes: `LoopProgressDetail`, `StrategyData` (Task 4); `StrategyTimeline` + `SectionHeading` from `@/patyourself/strategy-timeline` (Task 4); `OutcomeStrip`, `StreakBadge` (Task 5); `CoachLayout`, `BottomNav`.
- Produces: default page component `progress/show` consuming props `{ intention: LoopProgressDetail; strategies: StrategyData[]; summary: string | null }` (matches Task 3).

- [ ] **Step 1: Write the failing detail-page test**

Create `resources/js/pages/progress/show.test.tsx`:

```tsx
import * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { LoopProgressDetail, StrategyData } from '@/patyourself/types';

const page = { url: '/progress/3', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressShow from './show';

function detail(overrides: Partial<LoopProgressDetail> = {}): LoopProgressDetail {
    return {
        id: 3,
        title: 'Morning walk',
        type: 'build',
        streak: { outcome: 'completed', length: 5 },
        completion_rate: 82,
        totals: { completed: 12, failed: 2, skipped: 1 },
        recent: ['completed', 'failed', 'completed'],
        last_logged_at: '2026-06-22T07:00:00Z',
        ...overrides,
    };
}

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 1,
        version: 1,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: 'initial',
        superseded_reason: null,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('ProgressShow', () => {
    it('renders the metric header, totals, and sparkline', () => {
        render(<ProgressShow intention={detail()} strategies={[strategy()]} summary={null} />);

        expect(screen.getByText(/5 in a row/)).toBeInTheDocument();
        expect(screen.getByText(/82% complete/)).toBeInTheDocument();
        expect(screen.getByText(/12 done · 2 missed · 1 skipped/)).toBeInTheDocument();
        expect(screen.getByTestId('outcome-strip')).toBeInTheDocument();
    });

    it('renders the reused strategy journey with its versions', () => {
        render(
            <ProgressShow
                intention={detail()}
                strategies={[
                    strategy({ id: 1, version: 1, status: 'superseded' }),
                    strategy({ id: 2, version: 2, status: 'active', change_reason: 'stacked_on_success' }),
                ]}
                summary="You complete most mornings."
            />,
        );

        expect(screen.getByText(/v1/)).toBeInTheDocument();
        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(screen.getByText('Stacked on success')).toBeInTheDocument();
        expect(screen.getByText('You complete most mornings.')).toBeInTheDocument();
    });

    it('shows the empty narrative line when there is no summary', () => {
        render(<ProgressShow intention={detail()} strategies={[strategy()]} summary={null} />);

        expect(screen.getByText(/hasn’t summarized this loop yet/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/progress/show.test.tsx`
Expected: FAIL — cannot resolve `./show`.

- [ ] **Step 3: Create the detail page**

Create `resources/js/pages/progress/show.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { OutcomeStrip } from '@/patyourself/progress/outcome-strip';
import { StreakBadge } from '@/patyourself/progress/streak-badge';
import { SectionHeading, StrategyTimeline } from '@/patyourself/strategy-timeline';
import type { LoopProgressDetail, StrategyData } from '@/patyourself/types';

interface ProgressShowProps {
    intention: LoopProgressDetail;
    strategies: StrategyData[];
    summary: string | null;
}

/**
 * Progress detail — one loop's full metrics (streak, completion rate, totals,
 * sparkline), its versioned strategy journey (reused timeline), and the coach's
 * rolling narrative. Read-only; back-links to the progress index.
 */
export default function ProgressShow({ intention, strategies, summary }: ProgressShowProps) {
    const { totals } = intention;

    const back = (
        <Link
            href="/progress"
            className="-ml-1 flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            aria-label="Back to progress"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );

    return (
        <CoachLayout title={intention.title} headerLeading={back} bottomNav={<BottomNav />}>
            <div className="flex flex-col gap-6">
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2">
                        <StreakBadge streak={intention.streak} />
                        <span className="text-sm font-medium text-foreground">
                            {intention.completion_rate === null
                                ? '—'
                                : `${intention.completion_rate}% complete`}
                        </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {totals.completed} done · {totals.failed} missed · {totals.skipped} skipped
                    </p>
                    <OutcomeStrip recent={intention.recent} />
                </section>

                <StrategyTimeline strategies={strategies} />

                <section>
                    <SectionHeading>Coach summary</SectionHeading>
                    {summary ? (
                        <p className="text-sm whitespace-pre-line text-foreground">{summary}</p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Your coach hasn’t summarized this loop yet.
                        </p>
                    )}
                </section>
            </div>
        </CoachLayout>
    );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run resources/js/pages/progress/show.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Format**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/pages/progress/show.tsx resources/js/pages/progress/show.test.tsx`

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/progress/show.tsx resources/js/pages/progress/show.test.tsx
git commit -m "feat(sp5): progress detail page with reused journey + narrative

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (after Task 6)

- [ ] **Build assets** (required before the full PHP suite / any page-render feature test, or it ViteManifestNotFounds):

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run build`
Expected: builds clean; the new `progress/index` and `progress/show` pages appear in the manifest.

- [ ] **Full vitest suite:**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx vitest run`
Expected: all green (existing + new).

- [ ] **Full PHP suite:**

Run: `php artisan test --compact`
Expected: all green.

- [ ] **Pint clean:**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no changes (already formatted per-task).

---

## Self-Review (completed by plan author)

**1. Spec coverage**
- Placement / new route + 4th tab → Tasks 2, 3 (routes), Task 5 (tab). ✓
- Delete orphaned `dashboard.tsx`; leave AppSidebar stack → Task 4 Step 8 (only `dashboard.tsx`). ✓
- Per-loop streak + rate + sparkline → Task 1 (service), Task 5 (`StreakBadge`, `OutcomeStrip`, card). ✓
- Strategy journey timeline (reused, not duplicated) → Task 4 (extract), Task 6 (consume). ✓
- Rolling-summary narrative (excerpt on index, full on detail) → Task 2 (`excerpt`), Task 3 (`summary`), Tasks 5/6 (render). ✓
- Active-only index; detail serves any owned loop → Task 2 (`active()` scope test), Task 3 (`test_serves_a_non_active_owned_loop`). ✓
- Owner-gated detail (403) → Task 3 (`Gate::authorize` + `test_forbids_viewing_another_users_loop`). ✓
- Direct load, no defer → controllers return plain props; no `Inertia::defer`. ✓
- Metric definitions (rate excludes skips, null on empty, streak active-strategy-scoped, recent oldest→newest cap 10) → Task 1 tests. ✓
- Empty states (no loops, no logs, no summary) → Task 5 (`EmptyState`, no-logs test), Task 6 (empty narrative test). ✓
- Reuses `OutcomeStreak`, `StrategyResource`, `IntentionPolicy` unchanged → Tasks 1, 3. ✓
- NOT in scope (no writes/LLM/schema/CoachUsage/chart lib/account header) → nothing in the plan adds them. ✓

**2. Placeholder scan** — no TBD/TODO/"add error handling"; every code step shows full code. ✓

**3. Type consistency** — `LoopProgress::forLoop` shape (Task 1) is spread verbatim by both controllers (Tasks 2, 3) and mirrored by `LoopProgressCard` / `LoopProgressDetail` (Task 4), consumed by the pages (Tasks 5, 6). `OutcomeMark`, `LoopStreak` names match across service strings and TS. `StrategyTimeline` / `SectionHeading` exports (Task 4) match the imports (Task 6 + `intentions/show.tsx`). ✓

**Note:** The DB-backed `LoopProgress` test lives in `tests/Feature/Progress/` (not `tests/Unit/`) to match the codebase convention — `tests/Unit/` here holds only no-DB pure tests; everything using factories + `RefreshDatabase` is a Feature test.
