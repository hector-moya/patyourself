# Writable Notebook — Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the app its own write surface for experiments, notes and the action layer, so running a habit experiment no longer requires the MCP connector.

**Architecture:** No new Actions. `StartExperiment`, `ConcludeExperiment`, `LogNote`, `CreateAction` and `ArchiveAction` already exist and are already tested; this phase adds routes, FormRequests, one policy, thin controllers and forms. Every write is authorized through the owning loop, and validation is pinned to the model constants so the web boundary and the MCP boundary cannot drift.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3 + React 19, Wayfinder, Tailwind v4, PHPUnit 12, Vitest 4.

## Global Constraints

- **Verbatim text.** Reasons, notes and verdict notes are stored exactly as typed — never trimmed, squished or sentence-cased.
- **Failure language is about the strategy, never the user.** No discipline, willpower or motivation framing in any label, help text or validation message.
- **No gamification.** A streak is a statistic. Nothing congratulates.
- **No numeric targets**, and no quantities on eating loops.
- **The notebook never nags.** `planned_days: null` is open-ended and must never render as a countdown.
- **Append-only.** Nothing is edited in place; nothing is deleted. Retiring an action archives it.
- **After any PHP change:** run `vendor/bin/pint --dirty --format agent`.
- **After adding or renaming any route:** run `php artisan wayfinder:generate --with-form`. Without `--with-form` the generated `.form` helpers are missing and component tests fail with `update.form is not a function` — `vite.config.ts` sets `formVariants: true` but the Artisan command does not read it.
- **Run tests with** `php artisan test --compact --filter=<name>` and `npx vitest run <path>`.
- **Never run `php artisan serve`.** Herd serves the app at https://patyourself.test.

---

### Task 1: StrategyPolicy

**Files:**
- Create: `app/Policies/StrategyPolicy.php`
- Test: `tests/Feature/Policies/StrategyPolicyTest.php`

**Interfaces:**
- Consumes: `App\Models\Strategy` (has `intention()` belongsTo), `App\Models\User`.
- Produces: `StrategyPolicy::update(User $user, Strategy $strategy): bool` — used by Task 2's controller via `Gate::authorize('update', $strategy)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Policies;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_may_update_a_strategy_on_their_own_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create();

        $this->assertTrue($user->can('update', $strategy));
    }

    public function test_a_stranger_may_not_update_a_strategy(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create();

        $this->assertFalse($stranger->can('update', $strategy));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=StrategyPolicyTest`
Expected: FAIL — no policy is registered for `Strategy`, so `can()` returns false for the owner too.

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\Strategy;
use App\Models\User;

/**
 * A strategy version is private to whoever owns the loop it belongs to.
 *
 * Separate from {@see IntentionPolicy} because the verdict route is keyed on a
 * strategy rather than on a loop: the version is what carries the verdict, and
 * routing through the loop would let a caller pass a loop they own together
 * with a version they do not.
 */
class StrategyPolicy
{
    public function update(User $user, Strategy $strategy): bool
    {
        return $strategy->intention?->user_id === $user->id;
    }
}
```

Laravel 13 discovers `App\Policies\StrategyPolicy` for `App\Models\Strategy` by convention — the same way `IntentionPolicy` and `ActionPolicy` are already found. No registration needed.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=StrategyPolicyTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/StrategyPolicy.php tests/Feature/Policies/StrategyPolicyTest.php
git commit -m "feat(loops): a strategy version is gated on the loop that owns it"
```

---

### Task 2: Conclude an experiment

**Files:**
- Create: `app/Http/Requests/StoreVerdictRequest.php`
- Create: `app/Http/Controllers/VerdictController.php`
- Modify: `routes/web.php` (add inside the `auth`+`verified` group, after the `loops` resource)
- Test: `tests/Feature/Experiments/ConcludeExperimentWebTest.php`

**Interfaces:**
- Consumes: `StrategyPolicy::update` (Task 1); `App\Actions\ConcludeExperiment::handle(Strategy $strategy, string $verdict, ?string $note = null): Strategy`; `Strategy::VERDICTS` = `['worked','failed','inconclusive']`.
- Produces: route name `strategies.verdict.store` at `POST /strategies/{strategy}/verdict`, consumed by Task 6's form.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Experiments;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcludeExperimentWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_concludes_an_experiment_with_a_verdict(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create([
            'status' => Strategy::STATUS_ACTIVE,
            'verdict' => null,
        ]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), [
                'verdict' => Strategy::VERDICT_WORKED,
            ])
            ->assertRedirect();

        $this->assertSame(Strategy::VERDICT_WORKED, $strategy->refresh()->verdict);
    }

    public function test_a_failed_verdict_requires_a_note(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), [
                'verdict' => Strategy::VERDICT_FAILED,
            ])
            ->assertSessionHasErrors('note');

        $this->assertNull($strategy->refresh()->verdict);
    }

    public function test_the_note_is_stored_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);
        $note = "  The cue never fired.   Mornings are the wrong anchor.  ";

        $this->actingAs($user)->post(route('strategies.verdict.store', $strategy), [
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => $note,
        ]);

        $this->assertSame($note, $strategy->refresh()->verdict_note);
    }

    public function test_a_stranger_cannot_conclude_someone_elses_experiment(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);

        $this->actingAs($stranger)
            ->post(route('strategies.verdict.store', $strategy), ['verdict' => Strategy::VERDICT_WORKED])
            ->assertForbidden();

        $this->assertNull($strategy->refresh()->verdict);
    }

    public function test_concluding_an_already_concluded_experiment_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create([
            'verdict' => Strategy::VERDICT_WORKED,
        ]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), ['verdict' => Strategy::VERDICT_FAILED])
            ->assertSessionHasErrors('verdict');

        $this->assertSame(Strategy::VERDICT_WORKED, $strategy->refresh()->verdict);
    }

    public function test_guests_are_redirected(): void
    {
        $strategy = Strategy::factory()->create();

        $this->post(route('strategies.verdict.store', $strategy), ['verdict' => Strategy::VERDICT_WORKED])
            ->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ConcludeExperimentWebTest`
Expected: FAIL — `Route [strategies.verdict.store] not defined`.

- [ ] **Step 3: Write the request**

```php
<?php

namespace App\Http\Requests;

use App\Models\Strategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerdictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the StrategyPolicy
    }

    /**
     * Rules are built from Strategy::VERDICTS rather than a literal list, so a
     * new verdict reaches this boundary and the MCP tool's boundary together.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'verdict' => ['required', 'string', Rule::in(Strategy::VERDICTS)],
            // A failed experiment has to say what did not hold. The note is what
            // the next experiment gets written from, exactly as a failure reason is.
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(
                fn (): bool => $this->input('verdict') === Strategy::VERDICT_FAILED,
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Say what the strategy did not do. This is what the next experiment is written from.',
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\ConcludeExperiment;
use App\Http\Requests\StoreVerdictRequest;
use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ends an experiment with a verdict from the lab record.
 *
 * The dashboard already tells the user which experiment has reached its review
 * date; without this route it asks the question and offers no way to answer it.
 */
class VerdictController extends Controller
{
    public function store(StoreVerdictRequest $request, Strategy $strategy, ConcludeExperiment $conclude): RedirectResponse
    {
        Gate::authorize('update', $strategy);

        try {
            $conclude->handle(
                $strategy,
                $request->string('verdict')->toString(),
                // Verbatim: never trimmed or sentence-cased.
                $request->input('note'),
            );
        } catch (StrategyTransitionException $e) {
            // Realistically two tabs, not a malformed request — so it belongs on
            // the form rather than in a 500.
            throw ValidationException::withMessages(['verdict' => $e->getMessage()]);
        }

        return back();
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `auth`+`verified` group, immediately after the `Route::resource('loops', ...)` block:

```php
    // Answering the review the dashboard already surfaces. Keyed on the strategy
    // version, because the version is what carries the verdict.
    Route::post('strategies/{strategy}/verdict', [VerdictController::class, 'store'])
        ->name('strategies.verdict.store');
```

And add to the imports at the top of the file:

```php
use App\Http\Controllers\VerdictController;
```

- [ ] **Step 6: Regenerate Wayfinder and run the tests**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=ConcludeExperimentWebTest
```

Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreVerdictRequest.php app/Http/Controllers/VerdictController.php routes/web.php resources/js/routes resources/js/actions tests/Feature/Experiments/ConcludeExperimentWebTest.php
git commit -m "feat(experiments): an experiment can be concluded from the app"
```

---

### Task 3: Start the next experiment

**Files:**
- Create: `app/Http/Requests/StoreExperimentRequest.php`
- Create: `app/Http/Controllers/ExperimentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Experiments/StartExperimentWebTest.php`

**Interfaces:**
- Consumes: `IntentionPolicy::update`; `App\Actions\StartExperiment::handle(Strategy $current, AuthoredStrategy $next, string $changeReason, ?string $supersededReason = null, ?int $reviewAfterDays = null, ?AuthoredAction $revisedAction = null): Strategy`; `AuthoredStrategy::__construct(string $interventionPoint, string $approach, ?string $rationale = null, ?string $promptVersion = null)`; `AuthoredAction::__construct(string $title, ?string $description, string $kind, ?string $time, ?string $recurrence, ?string $anchor)`.
- Produces: route name `loops.experiments.store` at `POST /loops/{intention}/experiments`, consumed by Task 7's form.

**Note on `$revisedAction`:** passing `null` does **not** mean "no action". It means "inherit the prior action's cadence, retitled from the new approach". The form makes this an explicit choice; the controller passes `null` only when the user picked *keep the current cadence*.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Experiments;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartExperimentWebTest extends TestCase
{
    use RefreshDatabase;

    private function loopWithActiveVersion(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
        ]);

        return $loop->refresh();
    }

    public function test_the_owner_starts_the_next_experiment(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving out loud before opening the app.',
                'rationale' => 'The cue is unavoidable, so the cue is the wrong place to intervene.',
                'supersedes_reason' => 'Removing the cue did not survive contact with a working day.',
                'review_after_days' => 14,
                'cadence' => 'keep',
            ])
            ->assertRedirect();

        $loop->refresh();
        $this->assertSame(2, $loop->activeStrategy->version);
        $this->assertSame(Strategy::POINT_CRAVING, $loop->activeStrategy->intervention_point);
        $this->assertSame(14, $loop->activeStrategy->plannedDays());
    }

    public function test_an_invalid_intervention_point_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => 'willpower',
                'approach' => 'Try harder.',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('intervention_point');

        $this->assertSame(1, $loop->refresh()->activeStrategy->version);
    }

    public function test_an_empty_approach_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_REWARD,
                'approach' => '',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('approach');
    }

    public function test_a_negative_review_window_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_REWARD,
                'approach' => 'Log the reward you actually got.',
                'review_after_days' => -1,
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('review_after_days');
    }

    public function test_an_omitted_review_window_leaves_the_experiment_open_ended(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_REWARD,
            'approach' => 'Log the reward you actually got.',
            'cadence' => 'keep',
        ]);

        $this->assertNull($loop->refresh()->activeStrategy->plannedDays());
    }

    public function test_keeping_the_cadence_inherits_the_previous_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);
        Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'title' => 'Weigh in',
            'schedule_kind' => 'clock',
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving first.',
            'cadence' => 'keep',
        ]);

        $action = $loop->refresh()->activeAction;
        $this->assertSame('daily', $action->recurrence);
        $this->assertSame('clock', $action->schedule_kind);
    }

    public function test_changing_the_cadence_re_proposes_the_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);
        Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'schedule_kind' => 'clock',
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving first.',
            'cadence' => 'change',
            'action_title' => 'Say it out loud',
            'action_kind' => 'clock',
            'action_time' => '21:30',
            'action_recurrence' => 'weekdays',
        ]);

        $action = $loop->refresh()->activeAction;
        $this->assertSame('Say it out loud', $action->title);
        $this->assertSame('weekdays', $action->recurrence);
    }

    public function test_a_clock_cadence_without_a_time_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'change',
                'action_title' => 'Say it out loud',
                'action_kind' => 'clock',
            ])
            ->assertSessionHasErrors('action_time');
    }

    public function test_a_stranger_cannot_start_an_experiment(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion(User::factory()->create());

        $this->actingAs($stranger)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'keep',
            ])
            ->assertForbidden();
    }

    public function test_a_loop_with_no_active_version_reports_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('intervention_point');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=StartExperimentWebTest`
Expected: FAIL — `Route [loops.experiments.store] not defined`.

- [ ] **Step 3: Write the request**

```php
<?php

namespace App\Http\Requests;

use App\Models\Strategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * The web twin of StartExperimentTool's rules.
     *
     * AuthoredStrategy carries no guard of its own — the only validation of
     * `intervention_point` and a non-empty `approach` happens at whichever
     * boundary the write arrives through. Both boundaries build their rules
     * from the same model constants so a new point or reason moves them together.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'intervention_point' => ['required', 'string', Rule::in(Strategy::INTERVENTION_POINTS)],
            'approach' => ['required', 'string', 'min:1', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'supersedes_reason' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', Rule::in(Strategy::CHANGE_REASONS)],
            // Null is open-ended, and open-ended is a valid experiment.
            'review_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            // Passing an action re-proposes the cadence; omitting it inherits the
            // prior one. That is the least guessable part of StartExperiment's
            // API, so the form asks rather than defaulting.
            'cadence' => ['required', 'in:keep,change'],
            'action_title' => ['nullable', 'required_if:cadence,change', 'string', 'max:255'],
            'action_kind' => ['nullable', 'required_if:cadence,change', 'in:clock,anchored'],
            'action_time' => ['nullable', 'required_if:action_kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'action_recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'action_anchor' => ['nullable', 'required_if:action_kind,anchored', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\StartExperiment;
use App\Http\Requests\StoreExperimentRequest;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredStrategy;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts the next experiment on a loop from the lab record.
 *
 * Authoring a loop stays with the coach — a chain the user did not talk through
 * describes nothing. An experiment is different: the alternative to this form
 * was tinker.
 */
class ExperimentController extends Controller
{
    public function store(StoreExperimentRequest $request, Intention $intention, StartExperiment $start): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $current = $intention->activeStrategy;

        if (! $current instanceof Strategy) {
            throw ValidationException::withMessages([
                'intervention_point' => 'This loop has no active experiment to supersede. Activate the loop first.',
            ]);
        }

        try {
            $start->handle(
                current: $current,
                next: new AuthoredStrategy(
                    interventionPoint: $request->string('intervention_point')->toString(),
                    approach: $request->string('approach')->toString(),
                    rationale: $request->input('rationale'),
                ),
                changeReason: $request->input('change_reason', Strategy::REASON_RESTRATEGIZED_ON_FAILURE),
                supersededReason: $request->input('supersedes_reason'),
                reviewAfterDays: $request->filled('review_after_days')
                    ? $request->integer('review_after_days')
                    : null,
                // Null inherits the prior cadence. Only an explicit "change"
                // re-proposes it.
                revisedAction: $this->revisedAction($request),
            );
        } catch (StrategyTransitionException $e) {
            throw ValidationException::withMessages(['intervention_point' => $e->getMessage()]);
        }

        return back();
    }

    private function revisedAction(StoreExperimentRequest $request): ?AuthoredAction
    {
        if ($request->input('cadence') !== 'change') {
            return null;
        }

        $kind = $request->string('action_kind')->toString();

        return new AuthoredAction(
            title: $request->string('action_title')->toString(),
            description: null,
            kind: $kind,
            time: $kind === 'clock' ? $request->input('action_time') : null,
            recurrence: $kind === 'clock' ? $request->input('action_recurrence', 'once') : null,
            anchor: $kind === 'anchored' ? $request->input('action_anchor') : null,
        );
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, directly below the verdict route from Task 2:

```php
    // Starting the next version. Append-only: StartExperiment supersedes the
    // current version rather than editing it.
    Route::post('loops/{intention}/experiments', [ExperimentController::class, 'store'])
        ->name('loops.experiments.store');
```

Add to the imports:

```php
use App\Http\Controllers\ExperimentController;
```

- [ ] **Step 6: Regenerate Wayfinder and run the tests**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=StartExperimentWebTest
```

Expected: PASS, 10 tests.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreExperimentRequest.php app/Http/Controllers/ExperimentController.php routes/web.php resources/js/routes resources/js/actions tests/Feature/Experiments/StartExperimentWebTest.php
git commit -m "feat(experiments): the next experiment can be started from the app"
```

---

### Task 4: Boundary parity test

**Files:**
- Test: `tests/Feature/Experiments/ExperimentBoundaryParityTest.php`

**Interfaces:**
- Consumes: `StoreExperimentRequest::rules()` (Task 3), `StoreVerdictRequest::rules()` (Task 2), and the schemas on `App\Mcp\Tools\StartExperimentTool` / `ConcludeExperimentTool`.
- Produces: nothing. This is a drift guard.

This task exists because the two boundaries validate the same domain and there is no shared code between them. It fails the day someone adds a verdict or an intervention point to one side only.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Experiments;

use App\Http\Requests\StoreExperimentRequest;
use App\Http\Requests\StoreVerdictRequest;
use App\Models\Strategy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Tests\TestCase;

/**
 * The web form and the MCP tool are two doors onto the same writers, and
 * AuthoredStrategy guards nothing itself. If the two boundaries drift, one door
 * starts accepting values the other rejects and the difference is invisible
 * until bad data is already written.
 */
class ExperimentBoundaryParityTest extends TestCase
{
    public function test_the_verdict_rule_covers_every_verdict_the_model_defines(): void
    {
        $rules = (new StoreVerdictRequest)->rules();

        foreach (Strategy::VERDICTS as $verdict) {
            $this->assertStringContainsString($verdict, (string) $this->inRule($rules['verdict']));
        }
    }

    public function test_the_intervention_rule_covers_every_point_the_model_defines(): void
    {
        $rules = (new StoreExperimentRequest)->rules();

        foreach (Strategy::INTERVENTION_POINTS as $point) {
            $this->assertStringContainsString($point, (string) $this->inRule($rules['intervention_point']));
        }
    }

    public function test_the_change_reason_rule_covers_every_reason_the_model_defines(): void
    {
        $rules = (new StoreExperimentRequest)->rules();

        foreach (Strategy::CHANGE_REASONS as $reason) {
            $this->assertStringContainsString($reason, (string) $this->inRule($rules['change_reason']));
        }
    }

    /**
     * @param  array<int, mixed>  $rule
     */
    private function inRule(array $rule): In
    {
        foreach ($rule as $part) {
            if ($part instanceof In) {
                return $part;
            }
        }

        $this->fail('Expected an Rule::in() constraint built from the model constants, found none. '
            .'A literal list here is the drift this test exists to catch.');
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --compact --filter=ExperimentBoundaryParityTest`
Expected: PASS, 3 tests.

- [ ] **Step 3: Verify the test can fail**

Temporarily change `Rule::in(Strategy::VERDICTS)` in `StoreVerdictRequest` to `Rule::in(['worked'])`, re-run, confirm it FAILS, then revert.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Experiments/ExperimentBoundaryParityTest.php
git commit -m "test(experiments): pin the web and MCP boundaries to the same vocabulary"
```

---

### Task 5: Notes and the action layer

**Files:**
- Create: `app/Http/Requests/StoreNoteRequest.php`, `app/Http/Requests/StoreActionRequest.php`
- Create: `app/Http/Controllers/NoteController.php`
- Modify: `app/Http/Controllers/ActionController.php` (add `store` and `destroy`)
- Modify: `routes/web.php`
- Test: `tests/Feature/Notes/LogNoteWebTest.php`, `tests/Feature/Actions/ActionLayerWebTest.php`

**Interfaces:**
- Consumes: `App\Actions\LogNote::handle(Intention $loop, string $body, ?CarbonInterface $notedAt = null): Note`; `App\Actions\CreateAction::handle(Intention $loop, AuthoredAction $authored): Action`; `App\Actions\ArchiveAction::handle(Action $action): Action`.
- Produces: routes `loops.notes.store`, `loops.actions.store`, `actions.destroy` — consumed by Tasks 8 and 9.

- [ ] **Step 1: Write the failing note test**

```php
<?php

namespace Tests\Feature\Notes;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogNoteWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_writes_a_note_on_their_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.notes.store', $loop), ['body' => 'Skipped because the kitchen was closed.'])
            ->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'intention_id' => $loop->id,
            'body' => 'Skipped because the kitchen was closed.',
        ]);
    }

    public function test_the_body_is_stored_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $body = "  two spaces before.  And after.  ";

        $this->actingAs($user)->post(route('loops.notes.store', $loop), ['body' => $body]);

        $this->assertSame($body, $loop->notes()->latest('id')->first()->body);
    }

    public function test_a_blank_note_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.notes.store', $loop), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $loop->notes()->count());
    }

    public function test_a_stranger_cannot_write_a_note(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();

        $this->actingAs($stranger)
            ->post(route('loops.notes.store', $loop), ['body' => 'Not mine.'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=LogNoteWebTest`
Expected: FAIL — `Route [loops.notes.store] not defined`.

- [ ] **Step 3: Write the note request and controller**

`app/Http/Requests/StoreNoteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * `body` is validated for presence but never transformed. The stored value
     * is the raw input, spacing and casing included.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Guard against a whitespace-only note without altering what gets stored:
        // this only affects the value the `min:1` rule sees.
        if (is_string($this->input('body')) && trim($this->input('body')) === '') {
            $this->merge(['body' => '']);
        }
    }
}
```

`app/Http/Controllers/NoteController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\LogNote;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Intention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Records an observation against a loop from the lab record.
 *
 * Notes already rendered on the record; until now the only writer was the
 * log-note MCP tool. There is deliberately no edit and no delete — a note you
 * wish you had not written is still what you thought at the time.
 */
class NoteController extends Controller
{
    public function store(StoreNoteRequest $request, Intention $intention, LogNote $logNote): RedirectResponse
    {
        Gate::authorize('update', $intention);

        // Verbatim: the raw input, not the trimmed value validation looked at.
        $logNote->handle($intention, (string) $request->input('body'));

        return back();
    }
}
```

- [ ] **Step 4: Write the failing action-layer test**

```php
<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionLayerWebTest extends TestCase
{
    use RefreshDatabase;

    private function loopWithActiveVersion(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        return $loop->refresh();
    }

    public function test_the_owner_adds_a_clock_action(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), [
                'title' => 'Second meal check-in',
                'kind' => 'clock',
                'time' => '19:00',
                'recurrence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('actions', [
            'intention_id' => $loop->id,
            'title' => 'Second meal check-in',
            'recurrence' => 'daily',
        ]);
    }

    public function test_the_owner_adds_an_anchored_action(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)->post(route('loops.actions.store', $loop), [
            'title' => 'Pause before the second helping',
            'kind' => 'anchored',
            'anchor' => 'after serving dinner',
        ])->assertRedirect();

        $this->assertDatabaseHas('actions', [
            'intention_id' => $loop->id,
            'schedule_kind' => 'anchored',
            'anchor' => 'after serving dinner',
        ]);
    }

    public function test_a_clock_action_without_a_time_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), ['title' => 'No time', 'kind' => 'clock'])
            ->assertSessionHasErrors('time');
    }

    public function test_adding_an_action_to_a_loop_with_no_active_version_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), [
                'title' => 'Orphan',
                'kind' => 'anchored',
                'anchor' => 'whenever',
            ])
            ->assertSessionHasErrors('title');
    }

    public function test_retiring_an_action_archives_it_and_keeps_its_history(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);
        $action = Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'status' => Action::STATUS_ACTIVE,
        ]);
        $occurrence = $action->occurrences()->create([
            'scheduled_for' => now()->subDay(),
            'fired_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->delete(route('actions.destroy', $action))
            ->assertRedirect();

        $this->assertSame(Action::STATUS_ARCHIVED, $action->refresh()->status);
        $this->assertDatabaseHas('actions', ['id' => $action->id]);
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    public function test_a_stranger_cannot_retire_an_action(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion(User::factory()->create());
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);

        $this->actingAs($stranger)
            ->delete(route('actions.destroy', $action))
            ->assertForbidden();

        $this->assertSame(Action::STATUS_ACTIVE, $action->refresh()->status);
    }
}
```

- [ ] **Step 5: Write the action request and extend the controller**

`app/Http/Requests/StoreActionRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * Mirrors RescheduleActionRequest's shape so the add and edit forms speak
     * the same language, plus the title an existing action already has.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }
}
```

Add to `app/Http/Controllers/ActionController.php`:

```php
    /**
     * Adds an action to the loop's current experiment.
     *
     * Without this the action layer was frozen between experiments: splitting
     * one action into two meant starting an experiment the user did not want.
     */
    public function store(StoreActionRequest $request, Intention $intention, CreateAction $createAction): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $kind = $request->string('kind')->toString();

        try {
            $createAction->handle($intention, new AuthoredAction(
                title: $request->string('title')->toString(),
                description: null,
                kind: $kind,
                time: $kind === 'clock' ? $request->input('time') : null,
                recurrence: $kind === 'clock' ? $request->input('recurrence', 'once') : null,
                anchor: $kind === 'anchored' ? $request->input('anchor') : null,
            ));
        } catch (StrategyTransitionException $e) {
            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Retires an action.
     *
     * DELETE is the verb for "retire this", but the write is an archive:
     * occurrences hang off an action and outcomes hang off occurrences, so a
     * real delete would cascade away the evidence this app exists to keep.
     */
    public function destroy(Action $action, ArchiveAction $archiveAction): RedirectResponse
    {
        Gate::authorize('update', $action);

        $archiveAction->handle($action);

        return back();
    }
```

with the imports it needs at the top of that file:

```php
use App\Actions\ArchiveAction;
use App\Actions\CreateAction;
use App\Http\Requests\StoreActionRequest;
use App\Models\Intention;
use App\Services\Authoring\AuthoredAction;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, below the experiments route:

```php
    // A note is an observation that is not an outcome. Append-only: no edit,
    // no delete.
    Route::post('loops/{intention}/notes', [NoteController::class, 'store'])
        ->name('loops.notes.store');

    // The action layer, editable between experiments. `destroy` archives —
    // see ActionController::destroy for why the verb and the write differ.
    Route::post('loops/{intention}/actions', [ActionController::class, 'store'])
        ->name('loops.actions.store');
    Route::delete('actions/{action}', [ActionController::class, 'destroy'])
        ->name('actions.destroy');
```

Add to the imports:

```php
use App\Http\Controllers\NoteController;
```

- [ ] **Step 7: Regenerate Wayfinder and run both suites**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=LogNoteWebTest
php artisan test --compact --filter=ActionLayerWebTest
```

Expected: PASS, 4 tests and 6 tests.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreNoteRequest.php app/Http/Requests/StoreActionRequest.php app/Http/Controllers/NoteController.php app/Http/Controllers/ActionController.php routes/web.php resources/js/routes resources/js/actions tests/Feature/Notes tests/Feature/Actions/ActionLayerWebTest.php
git commit -m "feat(loops): notes and the action layer become writable from the app"
```

---

### Task 6: The conclude-experiment form

**Files:**
- Create: `resources/js/patyourself/loops/conclude-experiment-form.tsx`
- Create: `resources/js/patyourself/loops/conclude-experiment-form.test.tsx`
- Modify: `resources/js/pages/loops/show.tsx`

**Interfaces:**
- Consumes: route `strategies.verdict.store` (Task 2) via the Wayfinder import `@/routes/strategies/verdict`.
- Produces: `<ConcludeExperimentForm strategyId={number} isUnderReview={boolean} />`.

`show.tsx` is already a long file. Every form in Tasks 6–9 lives in its own component under `resources/js/patyourself/loops/` and the page composes them, following how `strategy-timeline.tsx` is already factored.

- [ ] **Step 1: Write the failing component test**

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ConcludeExperimentForm } from './conclude-experiment-form';

describe('ConcludeExperimentForm', () => {
    it('offers all three verdicts', () => {
        render(<ConcludeExperimentForm strategyId={1} isUnderReview />);

        expect(screen.getByLabelText(/worked/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/did not hold/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/inconclusive/i)).toBeInTheDocument();
    });

    it('asks for a note only once the verdict is a failure', async () => {
        render(<ConcludeExperimentForm strategyId={1} isUnderReview />);

        expect(screen.queryByLabelText(/what the strategy did not do/i)).not.toBeInTheDocument();

        await userEvent.click(screen.getByLabelText(/did not hold/i));

        expect(screen.getByLabelText(/what the strategy did not do/i)).toBeInTheDocument();
    });

    it('posts to the verdict route for this strategy', () => {
        const { container } = render(<ConcludeExperimentForm strategyId={42} isUnderReview />);

        expect(container.querySelector('form')?.getAttribute('action')).toContain('/strategies/42/verdict');
    });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/loops/conclude-experiment-form.test.tsx`
Expected: FAIL — cannot resolve `./conclude-experiment-form`.

- [ ] **Step 3: Write the component**

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import verdict from '@/routes/strategies/verdict';

type Props = {
    strategyId: number;
    isUnderReview: boolean;
};

const VERDICTS = [
    { value: 'worked', label: 'It worked', hint: 'Keep running it. A version that worked stays active.' },
    { value: 'failed', label: 'It did not hold', hint: 'The strategy did not do what it was meant to.' },
    { value: 'inconclusive', label: 'Inconclusive', hint: 'Not enough happened to say. This is a real answer.' },
] as const;

/**
 * Concluding is not superseding: a version concluded as `worked` stays active
 * and keeps running. Starting the next one is a separate act.
 */
export function ConcludeExperimentForm({ strategyId, isUnderReview }: Props) {
    const [choice, setChoice] = useState<string>('');

    return (
        <Form {...verdict.store.form(strategyId)} className="space-y-4">
            {({ processing, errors }) => (
                <>
                    <fieldset className="space-y-2">
                        <legend className="ds-label">
                            {isUnderReview ? 'This experiment has reached its review date.' : 'Give this experiment a verdict.'}
                        </legend>

                        {VERDICTS.map((v) => (
                            <label key={v.value} className="flex gap-3 items-start">
                                <input
                                    type="radio"
                                    name="verdict"
                                    value={v.value}
                                    checked={choice === v.value}
                                    onChange={() => setChoice(v.value)}
                                    className="mt-1"
                                />
                                <span>
                                    <span className="block font-medium">{v.label}</span>
                                    <span className="block text-sm opacity-70">{v.hint}</span>
                                </span>
                            </label>
                        ))}
                        {errors.verdict && <p className="text-sm text-red-600">{errors.verdict}</p>}
                    </fieldset>

                    {choice === 'failed' && (
                        <div className="space-y-1">
                            <label htmlFor="verdict-note" className="ds-label">
                                What the strategy did not do
                            </label>
                            <textarea id="verdict-note" name="note" rows={3} className="w-full rounded border p-2" />
                            {errors.note && <p className="text-sm text-red-600">{errors.note}</p>}
                        </div>
                    )}

                    <button type="submit" disabled={processing || choice === ''} className="rounded px-3 py-2 border">
                        Record the verdict
                    </button>
                </>
            )}
        </Form>
    );
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npx vitest run resources/js/patyourself/loops/conclude-experiment-form.test.tsx`
Expected: PASS, 3 tests.

- [ ] **Step 5: Mount it on the lab record**

In `resources/js/pages/loops/show.tsx`, import the component and render it inside the current-experiment section, only when there is an active, unconcluded version:

```tsx
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
```

```tsx
{intention.active_strategy && !intention.active_strategy.verdict && (
    <ConcludeExperimentForm
        strategyId={intention.active_strategy.id}
        isUnderReview={intention.active_strategy.is_under_review}
    />
)}
```

- [ ] **Step 6: Run the page's existing tests**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx`
Expected: PASS. If they fail on a missing prop, update the fixtures — adding a prop to an Inertia page breaks that page's existing component tests, and updating them belongs in this task.

- [ ] **Step 7: Commit**

```bash
git add resources/js/patyourself/loops resources/js/pages/loops
git commit -m "feat(ui): the lab record can answer the review it raises"
```

---

### Task 7: The start-experiment form

**Files:**
- Create: `resources/js/patyourself/loops/start-experiment-form.tsx`
- Create: `resources/js/patyourself/loops/start-experiment-form.test.tsx`
- Modify: `resources/js/pages/loops/show.tsx`

**Interfaces:**
- Consumes: route `loops.experiments.store` (Task 3) via `@/routes/loops/experiments`.
- Produces: `<StartExperimentForm loopId={number} currentCadence={string | null} />` — `currentCadence` is a human-readable description of the active action's schedule, e.g. `"daily at 19:00"`, or null when the loop has no active action.

- [ ] **Step 1: Write the failing component test**

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { StartExperimentForm } from './start-experiment-form';

describe('StartExperimentForm', () => {
    it('names the current cadence in the keep option so the choice is legible', () => {
        render(<StartExperimentForm loopId={1} currentCadence="daily at 19:00" />);

        expect(screen.getByLabelText(/keep the current cadence \(daily at 19:00\)/i)).toBeInTheDocument();
    });

    it('hides the schedule fields until the cadence is being changed', async () => {
        render(<StartExperimentForm loopId={1} currentCadence="daily at 19:00" />);

        expect(screen.queryByLabelText(/what to do/i)).not.toBeInTheDocument();

        await userEvent.click(screen.getByLabelText(/set a new cadence/i));

        expect(screen.getByLabelText(/what to do/i)).toBeInTheDocument();
    });

    it('offers the four points of the chain and nothing else', () => {
        render(<StartExperimentForm loopId={1} currentCadence={null} />);

        const options = Array.from(
            screen.getByLabelText(/where in the chain/i).querySelectorAll('option'),
        ).map((o) => o.getAttribute('value'));

        expect(options).toEqual(['cue', 'craving', 'response', 'reward']);
    });

    it('does not present the review window as a countdown when left empty', () => {
        render(<StartExperimentForm loopId={1} currentCadence={null} />);

        expect(screen.getByText(/leave this empty to run it open-ended/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/loops/start-experiment-form.test.tsx`
Expected: FAIL — cannot resolve `./start-experiment-form`.

- [ ] **Step 3: Write the component**

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import experiments from '@/routes/loops/experiments';

type Props = {
    loopId: number;
    currentCadence: string | null;
};

const POINTS = ['cue', 'craving', 'response', 'reward'] as const;

/**
 * StartExperiment's `$revisedAction` is the least guessable part of its API:
 * passing null does not mean "no action", it means "inherit the prior cadence,
 * retitled from the new approach". So the form asks instead of defaulting, and
 * names the current cadence in the option so the choice is legible.
 */
export function StartExperimentForm({ loopId, currentCadence }: Props) {
    const [cadence, setCadence] = useState<'keep' | 'change'>('keep');
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');

    return (
        <Form {...experiments.store.form(loopId)} className="space-y-4">
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <label htmlFor="intervention_point" className="ds-label">
                            Where in the chain does this one intervene?
                        </label>
                        <select id="intervention_point" name="intervention_point" className="w-full rounded border p-2">
                            {POINTS.map((p) => (
                                <option key={p} value={p}>
                                    {p}
                                </option>
                            ))}
                        </select>
                        {errors.intervention_point && <p className="text-sm text-red-600">{errors.intervention_point}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="approach" className="ds-label">
                            The approach
                        </label>
                        <textarea id="approach" name="approach" rows={3} className="w-full rounded border p-2" />
                        {errors.approach && <p className="text-sm text-red-600">{errors.approach}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="rationale" className="ds-label">
                            The hypothesis — why this point, and why now
                        </label>
                        <textarea id="rationale" name="rationale" rows={2} className="w-full rounded border p-2" />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="supersedes_reason" className="ds-label">
                            Why the current version is being replaced
                        </label>
                        <textarea id="supersedes_reason" name="supersedes_reason" rows={2} className="w-full rounded border p-2" />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="review_after_days" className="ds-label">
                            Review it after (days)
                        </label>
                        <input
                            id="review_after_days"
                            name="review_after_days"
                            type="number"
                            min={0}
                            className="w-full rounded border p-2"
                        />
                        <p className="text-sm opacity-70">Leave this empty to run it open-ended.</p>
                        {errors.review_after_days && <p className="text-sm text-red-600">{errors.review_after_days}</p>}
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="ds-label">The action</legend>

                        <label className="flex gap-2 items-center">
                            <input
                                type="radio"
                                name="cadence"
                                value="keep"
                                checked={cadence === 'keep'}
                                onChange={() => setCadence('keep')}
                            />
                            <span>
                                Keep the current cadence
                                {currentCadence ? ` (${currentCadence})` : ''}
                            </span>
                        </label>

                        <label className="flex gap-2 items-center">
                            <input
                                type="radio"
                                name="cadence"
                                value="change"
                                checked={cadence === 'change'}
                                onChange={() => setCadence('change')}
                            />
                            <span>Set a new cadence</span>
                        </label>
                    </fieldset>

                    {cadence === 'change' && (
                        <div className="space-y-3 border-l pl-4">
                            <div className="space-y-1">
                                <label htmlFor="action_title" className="ds-label">
                                    What to do
                                </label>
                                <input id="action_title" name="action_title" className="w-full rounded border p-2" />
                                {errors.action_title && <p className="text-sm text-red-600">{errors.action_title}</p>}
                            </div>

                            <div className="space-y-1">
                                <label htmlFor="action_kind" className="ds-label">
                                    When
                                </label>
                                <select
                                    id="action_kind"
                                    name="action_kind"
                                    value={kind}
                                    onChange={(e) => setKind(e.target.value as 'clock' | 'anchored')}
                                    className="w-full rounded border p-2"
                                >
                                    <option value="clock">At a time</option>
                                    <option value="anchored">After something else</option>
                                </select>
                            </div>

                            {kind === 'clock' ? (
                                <div className="flex gap-3">
                                    <div className="space-y-1">
                                        <label htmlFor="action_time" className="ds-label">
                                            Time
                                        </label>
                                        <input id="action_time" name="action_time" type="time" className="rounded border p-2" />
                                        {errors.action_time && <p className="text-sm text-red-600">{errors.action_time}</p>}
                                    </div>
                                    <div className="space-y-1">
                                        <label htmlFor="action_recurrence" className="ds-label">
                                            How often
                                        </label>
                                        <select id="action_recurrence" name="action_recurrence" className="rounded border p-2">
                                            <option value="once">Once</option>
                                            <option value="daily">Daily</option>
                                            <option value="weekdays">Weekdays</option>
                                            <option value="weekly">Weekly</option>
                                        </select>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-1">
                                    <label htmlFor="action_anchor" className="ds-label">
                                        After what
                                    </label>
                                    <input id="action_anchor" name="action_anchor" className="w-full rounded border p-2" />
                                    {errors.action_anchor && <p className="text-sm text-red-600">{errors.action_anchor}</p>}
                                </div>
                            )}
                        </div>
                    )}

                    <button type="submit" disabled={processing} className="rounded px-3 py-2 border">
                        Start this experiment
                    </button>
                </>
            )}
        </Form>
    );
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npx vitest run resources/js/patyourself/loops/start-experiment-form.test.tsx`
Expected: PASS, 4 tests.

- [ ] **Step 5: Mount it on the lab record**

In `show.tsx`, below the experiments ladder, behind a disclosure so it does not compete with the record:

```tsx
import { StartExperimentForm } from '@/patyourself/loops/start-experiment-form';
```

```tsx
{intention.active_strategy && (
    <details>
        <summary className="cursor-pointer ds-label">Start the next experiment</summary>
        <StartExperimentForm loopId={intention.id} currentCadence={currentCadenceLabel} />
    </details>
)}
```

`currentCadenceLabel` is derived from `intention.active_action`, which the web controller does not currently load. Add the eager load in `IntentionController::show` — `$intention->load([... , 'activeAction'])` — so the label can be built; without it the option reads "Keep the current cadence" with nothing in the parentheses, which is exactly the illegible default this form exists to avoid.

- [ ] **Step 6: Run the page tests**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx`
Expected: PASS, with fixtures updated for the new prop.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/patyourself/loops resources/js/pages/loops app/Http/Controllers/IntentionController.php
git commit -m "feat(ui): the next experiment can be started from the lab record"
```

---

### Task 8: The note box and the action-layer controls

**Files:**
- Create: `resources/js/patyourself/loops/note-form.tsx`, `resources/js/patyourself/loops/note-form.test.tsx`
- Create: `resources/js/patyourself/loops/action-layer.tsx`, `resources/js/patyourself/loops/action-layer.test.tsx`
- Modify: `resources/js/pages/loops/show.tsx`

**Interfaces:**
- Consumes: routes `loops.notes.store`, `loops.actions.store`, `actions.destroy` (Task 5).
- Produces: `<NoteForm loopId={number} />` and `<ActionLayer loopId={number} actions={ActionSummary[]} />` where `ActionSummary = { id: number; title: string; cadence: string }`.

- [ ] **Step 1: Write the failing note-box test**

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { NoteForm } from './note-form';

describe('NoteForm', () => {
    it('posts to the loop it belongs to', () => {
        const { container } = render(<NoteForm loopId={7} />);

        expect(container.querySelector('form')?.getAttribute('action')).toContain('/loops/7/notes');
    });

    it('does not chase the user for a note', () => {
        render(<NoteForm loopId={7} />);

        expect(screen.getByPlaceholderText(/something you noticed/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/loops/note-form.test.tsx`
Expected: FAIL — cannot resolve `./note-form`.

- [ ] **Step 3: Write the note box**

```tsx
import { Form } from '@inertiajs/react';
import notes from '@/routes/loops/notes';

/**
 * An observation that is not an outcome. Stored verbatim; there is no edit and
 * no delete, because the record is append-only.
 */
export function NoteForm({ loopId }: { loopId: number }) {
    return (
        <Form {...notes.store.form(loopId)} resetOnSuccess className="space-y-2">
            {({ processing, errors }) => (
                <>
                    <label htmlFor="note-body" className="sr-only">
                        Add a note
                    </label>
                    <textarea
                        id="note-body"
                        name="body"
                        rows={2}
                        placeholder="Something you noticed"
                        className="w-full rounded border p-2"
                    />
                    {errors.body && <p className="text-sm text-red-600">{errors.body}</p>}
                    <button type="submit" disabled={processing} className="rounded px-3 py-2 border">
                        Add note
                    </button>
                </>
            )}
        </Form>
    );
}
```

- [ ] **Step 4: Write the failing action-layer test**

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ActionLayer } from './action-layer';

const actions = [{ id: 3, title: 'Weigh in', cadence: 'daily at 07:00' }];

describe('ActionLayer', () => {
    it('lists the loop’s live actions with their cadence', () => {
        render(<ActionLayer loopId={2} actions={actions} />);

        expect(screen.getByText('Weigh in')).toBeInTheDocument();
        expect(screen.getByText('daily at 07:00')).toBeInTheDocument();
    });

    it('says retire, never delete, and says the history is kept', () => {
        render(<ActionLayer loopId={2} actions={actions} />);

        expect(screen.getByRole('button', { name: /retire/i })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
        expect(screen.getByText(/everything it recorded is kept/i)).toBeInTheDocument();
    });

    it('posts a new action to the loop it belongs to', () => {
        const { container } = render(<ActionLayer loopId={2} actions={actions} />);

        const addForm = container.querySelector('form[action*="/loops/2/actions"]');
        expect(addForm).not.toBeNull();
    });
});
```

- [ ] **Step 5: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx`
Expected: FAIL — cannot resolve `./action-layer`.

- [ ] **Step 6: Write the action layer**

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import actionsRoutes from '@/routes/loops/actions';
import { destroy } from '@/routes/actions';

export type ActionSummary = {
    id: number;
    title: string;
    cadence: string;
};

type Props = {
    loopId: number;
    actions: ActionSummary[];
};

/**
 * The action layer between experiments: add one, retire one.
 *
 * Retiring archives. Occurrences hang off an action and outcomes hang off
 * occurrences, so the copy says "retire" and says the history is kept — a
 * button labelled "delete" would be describing a write that does not happen.
 */
export function ActionLayer({ loopId, actions }: Props) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');

    return (
        <div className="space-y-4">
            <ul className="space-y-2">
                {actions.map((action) => (
                    <li key={action.id} className="flex items-center justify-between gap-3">
                        <span>
                            <span className="block">{action.title}</span>
                            <span className="block text-sm opacity-70">{action.cadence}</span>
                        </span>
                        <Form {...destroy.form(action.id)}>
                            {({ processing }) => (
                                <button type="submit" disabled={processing} className="text-sm underline">
                                    Retire
                                </button>
                            )}
                        </Form>
                    </li>
                ))}
            </ul>

            <p className="text-sm opacity-70">
                Retiring an action stops it running. Everything it recorded is kept.
            </p>

            <details>
                <summary className="cursor-pointer ds-label">Add an action</summary>
                <Form {...actionsRoutes.store.form(loopId)} resetOnSuccess className="space-y-3 pt-3">
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1">
                                <label htmlFor="action-title" className="ds-label">
                                    What to do
                                </label>
                                <input id="action-title" name="title" className="w-full rounded border p-2" />
                                {errors.title && <p className="text-sm text-red-600">{errors.title}</p>}
                            </div>

                            <div className="space-y-1">
                                <label htmlFor="action-kind" className="ds-label">
                                    When
                                </label>
                                <select
                                    id="action-kind"
                                    name="kind"
                                    value={kind}
                                    onChange={(e) => setKind(e.target.value as 'clock' | 'anchored')}
                                    className="w-full rounded border p-2"
                                >
                                    <option value="clock">At a time</option>
                                    <option value="anchored">After something else</option>
                                </select>
                            </div>

                            {kind === 'clock' ? (
                                <div className="flex gap-3">
                                    <div className="space-y-1">
                                        <label htmlFor="action-time" className="ds-label">
                                            Time
                                        </label>
                                        <input id="action-time" name="time" type="time" className="rounded border p-2" />
                                        {errors.time && <p className="text-sm text-red-600">{errors.time}</p>}
                                    </div>
                                    <div className="space-y-1">
                                        <label htmlFor="action-recurrence" className="ds-label">
                                            How often
                                        </label>
                                        <select id="action-recurrence" name="recurrence" className="rounded border p-2">
                                            <option value="once">Once</option>
                                            <option value="daily">Daily</option>
                                            <option value="weekdays">Weekdays</option>
                                            <option value="weekly">Weekly</option>
                                        </select>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-1">
                                    <label htmlFor="action-anchor" className="ds-label">
                                        After what
                                    </label>
                                    <input id="action-anchor" name="anchor" className="w-full rounded border p-2" />
                                    {errors.anchor && <p className="text-sm text-red-600">{errors.anchor}</p>}
                                </div>
                            )}

                            <button type="submit" disabled={processing} className="rounded px-3 py-2 border">
                                Add
                            </button>
                        </>
                    )}
                </Form>
            </details>
        </div>
    );
}
```

- [ ] **Step 7: Run both component tests**

```bash
npx vitest run resources/js/patyourself/loops/note-form.test.tsx
npx vitest run resources/js/patyourself/loops/action-layer.test.tsx
```

Expected: PASS, 2 tests and 3 tests.

- [ ] **Step 8: Mount both on the lab record**

In `show.tsx`, render `<NoteForm loopId={intention.id} />` above the notes list, and `<ActionLayer loopId={intention.id} actions={actions} />` in the actions section. Add an `actions` prop to `IntentionController::show`:

```php
'actions' => $intention->actions()
    ->where('status', '!=', Action::STATUS_ARCHIVED)
    ->get()
    ->map(fn (Action $action): array => [
        'id' => $action->id,
        'title' => $action->title,
        'cadence' => $action->schedule_kind === 'anchored'
            ? $action->anchor
            : trim(($action->recurrence ?? 'once').' at '.$action->nextOccurrenceAt()?->timezone($timezone)->format('H:i')),
    ])->values()->all(),
```

- [ ] **Step 9: Run the whole suite**

```bash
php artisan test --compact
npx vitest run
```

Expected: all green.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/patyourself/loops resources/js/pages/loops app/Http/Controllers/IntentionController.php
git commit -m "feat(ui): notes and the action layer get their controls on the record"
```

---

### Task 9: Close the dashboard's dead-end

**Files:**
- Modify: `resources/js/pages/dashboard.tsx`
- Test: `resources/js/pages/dashboard.test.tsx`

**Interfaces:**
- Consumes: the `reviews` prop `NotebookController::index` already provides — `{ loop_id, loop_title, version, intervention_point, day_of_experiment, planned_days }`.
- Produces: nothing.

The dashboard already computes which experiments are due for review and renders them. Until now that was a statement with no answer. Each row gains a link to its lab record, where Task 6's form now lives.

- [ ] **Step 1: Write the failing test**

```tsx
it('links a review-due experiment to the record where it can be answered', () => {
    render(<Dashboard {...propsWithReview} />);

    const link = screen.getByRole('link', { name: /give it a verdict/i });
    expect(link.getAttribute('href')).toContain('/loops/1');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/pages/dashboard.test.tsx`
Expected: FAIL — no such link.

- [ ] **Step 3: Add the link**

In the review section of `dashboard.tsx`, per row:

```tsx
<Link href={show.url(review.loop_id)} className="text-sm underline">
    Give it a verdict
</Link>
```

The copy states a fact and offers a door. It does not chase — `planned_days: null` still renders as open-ended, never as a countdown.

- [ ] **Step 4: Run it to verify it passes**

Run: `npx vitest run resources/js/pages/dashboard.test.tsx`
Expected: PASS.

- [ ] **Step 5: Full suite and commit**

```bash
php artisan test --compact
npx vitest run
git add resources/js/pages/dashboard.tsx resources/js/pages/dashboard.test.tsx
git commit -m "feat(ui): the review the dashboard raises now has somewhere to go"
```

---

## Phase A self-review checklist

Before handing off:

- [ ] `php artisan test --compact` — all green
- [ ] `npx vitest run` — all green
- [ ] `vendor/bin/pint --dirty --format agent` — clean
- [ ] `npm run types:check` — clean
- [ ] `php artisan route:list --except-vendor` shows all five new routes with the expected names
- [ ] With the connector disconnected, an experiment can be concluded and a new one started entirely from the app. This is the phase's actual acceptance test.
