# AI SDK Orchestrator + Specialist Agents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-rolled coach stack with `laravel/ai` (v0.7) agents — a Coach orchestrator with author/read tools plus Summarizer and Strategist specialists — migrating one capability at a time with the suite green throughout.

**Architecture:** Per-capability strangler. New code lives in `app/Ai/{Agents,Tools,Middleware}`. Specialists return structured data; the existing Actions (`AuthorIntention`, `ReviseStrategy`, `UpdateRollingSummary`) stay the only DB writers. Chat memory moves to SDK `agent_conversations` (one durable conversation per user via `RemembersConversations::continueLastConversation`). The cost guard becomes agent middleware writing the existing `coach_usages` ledger.

**Tech Stack:** Laravel 13.12 / PHP 8.4, `laravel/ai` ^0.7 (already in composer.json), Anthropic provider (`claude-sonnet-4-6`), PHPUnit + per-agent SDK fakes, Vitest for the frontend change.

**Spec:** `docs/superpowers/specs/2026-06-10-ai-sdk-agents-design.md`

**Verified SDK facts (v0.7.2, from vendor source — do not re-derive):**
- `Agent` contract: `instructions(): Stringable|string`; `Promptable` trait provides `prompt(string $prompt, ...): AgentResponse`.
- `AgentResponse` has `$text`, `$usage` (`promptTokens`, `completionTokens`), `$meta`; structured-output agents return responses readable as arrays (`$response['key']`) — Task 4 Step 2 verifies this against the fake before anything depends on it.
- Conversations: `RemembersConversations` trait → `forUser($user)`, `continue($conversationId, $as)`, `continueLastConversation($as)`, `currentConversation()`; persistence handled by the SDK's `RememberConversation` middleware; models `Laravel\Ai\Models\{Conversation, ConversationMessage}`; user-side trait `Laravel\Ai\Concerns\HasConversations`.
- Middleware: plain class, `handle(AgentPrompt $prompt, Closure $next)`, `$prompt->agent`, `$prompt->prompt`; after-hooks via `$next($prompt)->then(fn ($response) => ...)`.
- Attributes: `#[Provider(Lab::Anthropic)] #[Model('...')] #[Temperature(0.7)] #[MaxTokens(1024)] #[MaxSteps(N)]` from `Laravel\Ai\Attributes\*`, `Laravel\Ai\Enums\Lab`.
- Tools: implement `Laravel\Ai\Contracts\Tool` — `description()`, `handle(Laravel\Ai\Tools\Request $request)` (array access), `schema(JsonSchema $schema): array`. Sub-agents: `CanActAsTool` → `name()`, `description()`.
- Fakes: `AgentClass::fake(Closure|array $responses)`, `AgentClass::assertPrompted(Closure|string)`; conversation tables migration ships with the package (`2026_01_11_000001_create_agent_conversations_table`).

---

### Task 1: SDK config, conversations migration, User trait

**Files:**
- Create: `config/ai.php` (publish)
- Modify: `app/Models/User.php`, `.env.example`
- Test: `tests/Feature/Ai/SdkInstallTest.php`

- [ ] **Step 1: Publish config + run package migrations**

```bash
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

Expected: `config/ai.php` created; `agent_conversations` + `agent_conversation_messages` tables migrate. Confirm Anthropic provider entry in `config/ai.php` reads `env('ANTHROPIC_API_KEY')` (already in `.env`/`.env.example`); if the published config names differ, align env var names in the config file, not in `.env`.

- [ ] **Step 2: Write the failing install test**

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class SdkInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_tables_migrate_and_users_have_conversations(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Coach',
        ]);

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertTrue($user->conversations()->whereKey($conversation->id)->exists());
    }
}
```

Run: `php artisan test tests/Feature/Ai/SdkInstallTest.php` — Expected: FAIL (`conversations()` undefined on User).
(If the Conversation model's id is not a string uuid in this SDK version, drop the `'id'` key and let the model default it — assert on the returned instance instead.)

- [ ] **Step 3: Add the trait to User**

In `app/Models/User.php`, add `use Laravel\Ai\Concerns\HasConversations;` to the imports and `use HasConversations;` inside the class beside the existing traits.

- [ ] **Step 4: Run test — PASS. Full suite green.**

Run: `php artisan test` — Expected: all green (201+).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: install laravel/ai (config, conversations, User trait)"
```

---

### Task 2: GuardCoachUsage middleware (ports the cost guard)

**Files:**
- Create: `app/Ai/Middleware/GuardCoachUsage.php`
- Test: `tests/Feature/Ai/GuardCoachUsageTest.php`

The existing `CoachUsageGuard` (`app/Services/Coach/Usage/CoachUsageGuard.php`) stays untouched until Task 10 — the middleware reuses it. Same semantics as `GuardedCoachService`: no authenticated user → pass through unmetered.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Middleware\GuardCoachUsage;
use App\Models\User;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class GuardCoachUsageTest extends TestCase
{
    use RefreshDatabase;

    private function respond(int $prompt = 80, int $completion = 20): callable
    {
        // Stub $next: returns a real AgentResponse carrying usage.
        return fn ($p) => new AgentResponse(
            invocationId: 'inv_1',
            text: 'ok',
            usage: new Usage(promptTokens: $prompt, completionTokens: $completion),
            meta: new \Laravel\Ai\Responses\Data\Meta(model: 'claude-sonnet-4-6'),
        );
    }

    private function middleware(int $budget): GuardCoachUsage
    {
        config()->set('services.coach.daily_token_budget', $budget);

        return $this->app->make(GuardCoachUsage::class);
    }

    private function prompt(): object
    {
        // The middleware only reads the agent's class name for `purpose`.
        return new class
        {
            public object $agent;

            public function __construct()
            {
                $this->agent = new class {};
            }
        };
    }

    public function test_records_usage_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->middleware(200000)->handle($this->prompt(), $this->respond());

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'total_tokens' => 100,
        ]);
    }

    public function test_rejects_an_over_budget_user_before_calling_next(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        (new CoachUsageGuard(100))->record($user, new \App\Services\Coach\Data\CoachResponse(
            content: '{}', model: 'fake', promptTokens: 100, completionTokens: 0,
        ), 'chat');

        $called = false;

        $this->expectException(CoachQuotaException::class);

        try {
            $this->middleware(100)->handle($this->prompt(), function () use (&$called) {
                $called = true;
            });
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_passes_through_unmetered_with_no_authenticated_user(): void
    {
        $response = $this->middleware(100)->handle($this->prompt(), $this->respond());

        $this->assertSame('ok', $response->text);
        $this->assertDatabaseCount('coach_usages', 0);
    }
}
```

Note: if `AgentResponse`/`Meta` constructor signatures differ at runtime, adapt the stub to the real constructor (check `vendor/laravel/ai/src/Responses/AgentResponse.php` and `.../Data/Meta.php`) — the assertions stay the same.

- [ ] **Step 2: Run — FAIL (class not found)**

Run: `php artisan test tests/Feature/Ai/GuardCoachUsageTest.php`

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Middleware;

use App\Models\User;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Usage\CoachUsageGuard;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The cost guard as agent middleware: checks the authenticated user's rolling
 * token budget before the call and records the tokens spent after. Attached to
 * every agent, so each LLM call in a turn — orchestrator and specialists alike —
 * is metered into the coach_usages ledger. No authenticated user (console,
 * queued jobs) passes through unmetered, matching the old GuardedCoachService.
 */
class GuardCoachUsage
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(object $prompt, Closure $next)
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return $next($prompt);
        }

        $guard = $this->guard();
        $guard->ensureWithinBudget($user);

        $response = $next($prompt);
        $purpose = class_basename($prompt->agent);

        $guard->record($user, new CoachResponse(
            content: '',
            model: $response->meta->model ?? 'unknown',
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        ), strtolower($purpose));

        return $response;
    }

    private function guard(): CoachUsageGuard
    {
        return new CoachUsageGuard(
            (int) config('services.coach.daily_token_budget', 0),
        );
    }
}
```

(`CoachUsageGuard::record()` takes a `CoachResponse` today; we construct a thin one rather than change the guard's signature while the old stack still uses it. Task 10 simplifies `record()` to take ints and deletes `CoachResponse`. If `$response->meta->model` doesn't exist on the real `Meta`, read the model another way or store `'unknown'` — the token counts are the point.)

- [ ] **Step 4: Run — PASS. Full suite green. Commit**

```bash
php artisan test
git add -A && git commit -m "feat: port cost guard to AI SDK agent middleware"
```

---

### Task 3: TurnCollector (request-scoped authored-loop collector)

**Files:**
- Create: `app/Ai/TurnCollector.php`
- Test: `tests/Feature/Ai/TurnCollectorTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\TurnCollector;
use Tests\TestCase;

class TurnCollectorTest extends TestCase
{
    public function test_collects_intention_ids_and_is_a_singleton(): void
    {
        $collector = $this->app->make(TurnCollector::class);
        $collector->addIntention(7);
        $collector->addIntention(9);

        $this->assertSame([7, 9], $this->app->make(TurnCollector::class)->intentionIds());
    }

    public function test_flush_empties_the_collector(): void
    {
        $collector = $this->app->make(TurnCollector::class);
        $collector->addIntention(7);
        $collector->flush();

        $this->assertSame([], $collector->intentionIds());
    }
}
```

Run: `php artisan test tests/Feature/Ai/TurnCollectorTest.php` — FAIL.

- [ ] **Step 2: Implement + register as scoped singleton**

```php
<?php

namespace App\Ai;

/**
 * Request-scoped collector for data the coach's tools create mid-turn. The
 * CreateLoop tool registers each authored Intention id here; after the turn the
 * ChatController drains it to build the cards payload. Scoped (not singleton)
 * so queued/octane requests never leak ids across turns.
 */
class TurnCollector
{
    /** @var list<int> */
    private array $intentionIds = [];

    public function addIntention(int $id): void
    {
        $this->intentionIds[] = $id;
    }

    /** @return list<int> */
    public function intentionIds(): array
    {
        return $this->intentionIds;
    }

    public function flush(): void
    {
        $this->intentionIds = [];
    }
}
```

In `app/Providers/AppServiceProvider.php` `register()`, add:

```php
$this->app->scoped(\App\Ai\TurnCollector::class);
```

- [ ] **Step 3: Run — PASS. Commit**

```bash
php artisan test tests/Feature/Ai/TurnCollectorTest.php && git add -A && git commit -m "feat: request-scoped turn collector for tool-authored cards"
```

---

### Task 4: Summarizer agent + UpdateRollingSummary swap

**Files:**
- Create: `app/Ai/Agents/Summarizer.php`
- Modify: `app/Actions/UpdateRollingSummary.php`
- Test: `tests/Feature/Ai/SummarizerTest.php`; Modify: `tests/Feature/UpdateRollingSummaryTest.php`
- Delete: `app/Services/Coach/Summary/RollingSummaryService.php`, `app/Services/Coach/Summary/PatternSummarySchema.php`, `tests/Unit/Coach/RollingSummaryServiceTest.php`

Port the system prompt verbatim from `CoachPrompts::rollingSummary()` (see `app/Services/Coach/Prompts/CoachPrompts.php`) into `instructions()`; carry its version string into `PROMPT_VERSION`. `UpdateRollingSummary` currently builds the events/prior-summary prompt and calls `RollingSummaryService` — keep its prompt-building, swap the transport.

- [ ] **Step 1: Failing agent test (also the structured-output spike)**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Summarizer;
use Tests\TestCase;

class SummarizerTest extends TestCase
{
    public function test_returns_structured_content_and_patterns(): void
    {
        Summarizer::fake([
            json_encode(['content' => 'Mornings keep failing.', 'patterns' => ['fails_on_mornings']]),
        ]);

        $response = (new Summarizer)->prompt('Summarize these events: ...');

        // The structured-output spike: array access on the response.
        $this->assertSame('Mornings keep failing.', $response['content']);
        $this->assertSame(['fails_on_mornings'], $response['patterns']);
    }

    public function test_carries_a_prompt_version(): void
    {
        $this->assertNotSame('', Summarizer::PROMPT_VERSION);
    }
}
```

Run: FAIL (class missing). **If array access fails once implemented (Step 3), this is the spike tripping:** check how `vendor/laravel/ai` exposes structured output on the response (look at `Responses/TextResponse.php` for `ArrayAccess`/`structured`/`json` members) and adjust accessors here and in every later task to the real API. Record the finding in the commit message.

- [ ] **Step 2: Implement the agent**

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Folds a loop's new action-log events (plus the prior summary) into a rolling
 * pattern summary — the app's pattern detection, no ML. Returns structured
 * data only; UpdateRollingSummary persists it.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.3)]
#[MaxTokens(1024)]
class Summarizer implements Agent, HasStructuredOutput, HasMiddleware
{
    use Promptable;

    public const PROMPT_VERSION = '<copy version from CoachPrompts::rollingSummary()>';

    public function instructions(): string
    {
        return <<<'PROMPT'
        <copy the system prompt verbatim from CoachPrompts::rollingSummary()>
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->max(4000)->required(),
            'patterns' => $schema->array()->items($schema->string())->max(12),
        ];
    }

    public function middleware(): array
    {
        return [\App\Ai\Middleware\GuardCoachUsage::class];
    }
}
```

(The two `<copy …>` markers are executor instructions with an exact source: open `app/Services/Coach/Prompts/CoachPrompts.php`, take the `rollingSummary()` prompt text and version literally.)

- [ ] **Step 3: Run agent test — PASS (or spike adaptation, see Step 1)**

- [ ] **Step 4: Swap UpdateRollingSummary**

In `app/Actions/UpdateRollingSummary.php`: replace the `RollingSummaryService` constructor dependency with none (agents are static-faked); where it called the service, build the same user-prompt string it builds today and call:

```php
$response = (new \App\Ai\Agents\Summarizer)->prompt($userPrompt);

$summaryContent = (string) $response['content'];
$patterns = (array) ($response['patterns'] ?? []);
```

Persist exactly as today (same Summary::create fields, window bounds, events_count), storing `Summarizer::PROMPT_VERSION` wherever the old prompt version went in metadata. Keep returning `null` when there are no new events (that logic is before the LLM call — untouched).

- [ ] **Step 5: Update UpdateRollingSummaryTest fakes**

In `tests/Feature/UpdateRollingSummaryTest.php`: replace `FakeCoachService` setup with `Summarizer::fake([...])` returning the same JSON payloads the old fake pushed. Keep every assertion. The "request carries events/prior summary" assertions move to `Summarizer::assertPrompted(fn ($p) => str_contains($p->prompt, '...'))`.

- [ ] **Step 6: Delete the old service + schema + its unit test, run full suite**

```bash
git rm app/Services/Coach/Summary/RollingSummaryService.php app/Services/Coach/Summary/PatternSummarySchema.php tests/Unit/Coach/RollingSummaryServiceTest.php
php artisan test
```

Expected: green. (`SummaryException`/`AuthoredSummary` may now be unused — delete them too if nothing references them: `grep -rn "AuthoredSummary\|SummaryException" app tests`.)

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint --dirty && php artisan test && git add -A && git commit -m "feat: Summarizer agent replaces RollingSummaryService"
```

---

### Task 5: Strategist agent + ReviseStrategy swap

**Files:**
- Create: `app/Ai/Agents/Strategist.php`
- Modify: `app/Actions/ReviseStrategy.php`
- Test: `tests/Feature/Ai/StrategistTest.php`; Modify: `tests/Feature/ReviseStrategyTest.php`
- Delete: `app/Services/Coach/Strategy/StrategyReviser.php`, `app/Services/Coach/Strategy/StrategyRevisionSchema.php`, `tests/Unit/Coach/StrategyReviserTest.php`

Mirror of Task 4. Mode-specific framing: `CoachPrompts::strategyRevision($mode)` builds different prompts for `stack` vs `restrategize` — port BOTH framings into `instructions()` as shared charter, and put the mode-specific text into the user prompt built by `ReviseStrategy` (it already assembles the failure-reason/loop context prompt).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Strategist;
use Tests\TestCase;

class StrategistTest extends TestCase
{
    public function test_returns_a_structured_strategy_revision(): void
    {
        Strategist::fake([
            json_encode([
                'intervention_point' => 'response',
                'approach' => 'Read a single page, no more',
                'rationale' => 'Shrink the response',
            ]),
        ]);

        $response = (new Strategist)->prompt('The user failed because: too tired.');

        $this->assertSame('response', $response['intervention_point']);
        $this->assertSame('Read a single page, no more', $response['approach']);
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

namespace App\Ai\Agents;

use App\Models\Strategy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Authors the next strategy version for a loop: restrategize after a failure
 * (shift the intervention point using the user-stated reason) or stack a
 * slightly harder version on success. Returns structured data; ReviseStrategy
 * persists the new version append-only.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.5)]
#[MaxTokens(1024)]
class Strategist implements Agent, HasStructuredOutput, HasMiddleware
{
    use Promptable;

    public const PROMPT_VERSION = '<copy from CoachPrompts::strategyRevision()>';

    public function instructions(): string
    {
        return <<<'PROMPT'
        <copy charter + both mode framings verbatim from CoachPrompts::strategyRevision(); the user prompt states which mode applies>
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intervention_point' => $schema->string()
                ->enum([Strategy::POINT_CUE, Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE, Strategy::POINT_REWARD])
                ->required(),
            'approach' => $schema->string()->max(2000)->required(),
            'rationale' => $schema->string()->max(2000),
        ];
    }

    public function middleware(): array
    {
        return [\App\Ai\Middleware\GuardCoachUsage::class];
    }
}
```

(Check the real `Strategy::POINT_*` constant names in `app/Models/Strategy.php` — `POINT_CUE`, `POINT_CRAVING`, `POINT_RESPONSE`, `POINT_REWARD` per the existing schema's `INTERVENTION_POINTS`.)

- [ ] **Step 3: Swap ReviseStrategy** — same pattern as Task 4 Step 4: keep its mode/prompt assembly and transition guard (`only an active strategy can transition`), call `(new Strategist)->prompt($userPrompt)`, map `$response['intervention_point'|'approach'|'rationale']` into the same Strategy::create it does today, with `Strategist::PROMPT_VERSION` in metadata.

- [ ] **Step 4: Update ReviseStrategyTest** — swap `FakeCoachService` pushes for `Strategist::fake([...])` with the same JSON; keep all four test assertions (new version + history kept, stack, preauthored-without-LLM-call — that path must NOT prompt the agent: assert with `Strategist::assertNeverPrompted()` — and transition guard).

- [ ] **Step 5: Delete old, full suite, Pint, commit**

```bash
git rm app/Services/Coach/Strategy/StrategyReviser.php app/Services/Coach/Strategy/StrategyRevisionSchema.php tests/Unit/Coach/StrategyReviserTest.php
./vendor/bin/pint --dirty && php artisan test
git add -A && git commit -m "feat: Strategist agent replaces StrategyReviser"
```

(`BehavioralChain` + `StrategyTransitionException` stay — `ReviseStrategy` still uses them; `tests/Unit/Coach/BehavioralChainTest.php` stays.)

---

### Task 6: IntentionAuthor sub-agent + CreateLoop tool

**Files:**
- Create: `app/Ai/Agents/IntentionAuthor.php`, `app/Ai/Tools/CreateLoop.php`
- Test: `tests/Feature/Ai/CreateLoopTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\IntentionAuthor;
use App\Ai\Tools\CreateLoop;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class CreateLoopTest extends TestCase
{
    use RefreshDatabase;

    private function authoredPayload(): array
    {
        return [
            'title' => 'Read before bed',
            'description' => 'Swap scrolling for pages.',
            'type' => 'build',
            'cue' => 'Phone on charger at 10pm',
            'craving' => 'Wind down',
            'response' => 'Read a chapter',
            'reward' => 'Calmer sleep',
            'confidence' => 0.8,
            'strategy' => [
                'intervention_point' => 'cue',
                'approach' => 'Leave the book on the pillow.',
                'rationale' => 'Make the cue obvious.',
            ],
        ];
    }

    public function test_authors_persists_and_registers_the_loop(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        IntentionAuthor::fake([json_encode($this->authoredPayload())]);

        $tool = $this->app->make(CreateLoop::class);
        $result = (string) $tool->handle(new ToolRequest(['goal' => 'I want to read more before bed']));

        $intention = Intention::sole();
        $this->assertSame('Read before bed', $intention->title);
        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame(1, $intention->strategies()->count());
        $this->assertSame([$intention->id], $this->app->make(TurnCollector::class)->intentionIds());
        $this->assertStringContainsString('Read before bed', $result);
    }
}
```

(If `Laravel\Ai\Tools\Request` cannot be constructed from an array directly, check its constructor in `vendor/laravel/ai/src/Tools/Request.php` and construct accordingly.)

- [ ] **Step 2: Implement IntentionAuthor**

```php
<?php

namespace App\Ai\Agents;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Authors a complete habit loop (cue → craving → response → reward, plus an
 * initial strategy) from a user's goal as structured JSON. Pure authoring — it
 * never touches the database; the CreateLoop tool persists through the
 * AuthorIntention action.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.7)]
#[MaxTokens(1024)]
class IntentionAuthor implements Agent, HasStructuredOutput, HasMiddleware
{
    use Promptable;

    public const PROMPT_VERSION = '<copy from CoachPrompts::intentionAuthoring()>';

    public function instructions(): string
    {
        return <<<'PROMPT'
        <copy verbatim from CoachPrompts::intentionAuthoring()>
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->required(),
            'description' => $schema->string()->max(2000),
            'type' => $schema->string()->enum([Intention::TYPE_BUILD, Intention::TYPE_BREAK])->required(),
            'cue' => $schema->string()->max(2000)->required(),
            'craving' => $schema->string()->max(2000)->required(),
            'response' => $schema->string()->max(2000)->required(),
            'reward' => $schema->string()->max(2000)->required(),
            'confidence' => $schema->number()->min(0)->max(1),
            'strategy' => $schema->object(fn ($schema) => [
                'intervention_point' => $schema->string()
                    ->enum([Strategy::POINT_CUE, Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE, Strategy::POINT_REWARD])
                    ->required(),
                'approach' => $schema->string()->max(2000)->required(),
                'rationale' => $schema->string()->max(2000),
            ]),
        ];
    }

    public function middleware(): array
    {
        return [\App\Ai\Middleware\GuardCoachUsage::class];
    }
}
```

- [ ] **Step 3: Implement CreateLoop**

```php
<?php

namespace App\Ai\Tools;

use App\Actions\AuthorIntention;
use App\Ai\Agents\IntentionAuthor;
use App\Ai\TurnCollector;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Authoring\AuthoredStrategy;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The coach's loop-authoring tool. Prompts the IntentionAuthor specialist for a
 * structured loop, persists it through the AuthorIntention action (the only DB
 * writer), and registers the new id with the TurnCollector so the controller
 * can return the card. Returns a short confirmation the coach can speak to.
 */
class CreateLoop implements Tool
{
    public function __construct(
        private readonly AuthorIntention $author,
        private readonly TurnCollector $collector,
        private readonly AuthFactory $auth,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a habit loop for the user from a goal they have described. '
            .'Use when the user wants to start building or breaking a habit.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->auth->guard()->user();
        $goal = (string) $request['goal'];

        $data = (new IntentionAuthor)->prompt($goal);

        $strategy = isset($data['strategy']) ? new AuthoredStrategy(
            interventionPoint: $data['strategy']['intervention_point'],
            approach: $data['strategy']['approach'],
            rationale: $data['strategy']['rationale'] ?? null,
        ) : null;

        $authored = new AuthoredIntention(
            title: $data['title'],
            description: $data['description'] ?? null,
            type: $data['type'],
            cue: $data['cue'],
            craving: $data['craving'],
            response: $data['response'],
            reward: $data['reward'],
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            tags: [],
            strategy: $strategy,
            model: 'claude-sonnet-4-6',
            promptVersion: IntentionAuthor::PROMPT_VERSION,
        );

        $intention = $this->author->handle($user, $goal, [], $authored);
        $this->collector->addIntention($intention->id);

        return "Created the loop \"{$intention->title}\" (id {$intention->id}). It is now visible to the user as a card.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required(),
        ];
    }
}
```

(Check `AuthoredStrategy`'s real constructor in `app/Services/Coach/Authoring/AuthoredStrategy.php` and match parameter names. `AuthoredIntention`'s constructor is verified: title, description, type, cue, craving, response, reward, confidence, tags, strategy, model, promptVersion.)

- [ ] **Step 4: Run — PASS. Pint. Commit**

```bash
./vendor/bin/pint --dirty && php artisan test tests/Feature/Ai/ && git add -A && git commit -m "feat: IntentionAuthor sub-agent + CreateLoop tool"
```

---

### Task 7: Read-only data tools

**Files:**
- Create: `app/Ai/Tools/ListLoops.php`, `app/Ai/Tools/GetLoopDetail.php`, `app/Ai/Tools/GetLatestSummary.php`
- Test: `tests/Feature/Ai/ReadToolsTest.php`

All three: constructor-inject `AuthFactory`, resolve the user, query ONLY their data, return compact JSON strings (the model reads them). Ownership is non-negotiable — a loop id belonging to another user returns "not found".

- [ ] **Step 1: Failing tests**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\GetLatestSummary;
use App\Ai\Tools\GetLoopDetail;
use App\Ai\Tools\ListLoops;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class ReadToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_loops_returns_only_the_users_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'Mine']);
        Intention::factory()->create(['title' => 'Theirs']);
        $this->actingAs($user);

        $result = (string) $this->app->make(ListLoops::class)->handle(new ToolRequest([]));

        $this->assertStringContainsString('Mine', $result);
        $this->assertStringNotContainsString('Theirs', $result);
    }

    public function test_loop_detail_includes_anatomy_strategy_and_recent_logs(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create(['cue' => 'Alarm at 6am']);
        $intention->strategies()->create([
            'version' => 1, 'status' => 'active', 'intervention_point' => 'cue',
            'approach' => 'Shoes by the door', 'change_reason' => 'initial',
        ]);
        $action = Action::factory()->for($intention)->create();
        ActionLog::factory()->for($action)->for($user)->failed('overslept')->create();
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertStringContainsString('Alarm at 6am', $result);
        $this->assertStringContainsString('Shoes by the door', $result);
        $this->assertStringContainsString('overslept', $result);
    }

    public function test_loop_detail_refuses_another_users_loop(): void
    {
        $other = Intention::factory()->create();
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => $other->id]));

        $this->assertStringContainsString('not found', strtolower($result));
    }

    public function test_latest_summary_returns_the_loops_newest_summary(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Summary::factory()->for($intention)->for($user)->create(['content' => 'Mornings fail.']);
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertStringContainsString('Mornings fail.', $result);
    }
}
```

(Check `SummaryFactory` defaults — it may need `'scope' => Summary::SCOPE_INTENTION` explicitly; the factory definition defaults to intention scope already.)

- [ ] **Step 2: Implement** — `ListLoops` (no params; user's loops: id, title, type, status, active strategy approach, one line each), `GetLoopDetail` (param `intention_id` integer required; anatomy + active strategy + last 10 logs with outcomes/reasons), `GetLatestSummary` (param `intention_id`; latest intention-scoped summary content + patterns from metadata). Each `handle()` resolves `$user = $this->auth->guard()->user();`, scopes every query `->where('user_id', $user->id)` (or `whereHas('intention', …)`), returns `json_encode(...)` of a compact array, and `'Loop not found.'` when the id misses. Descriptions: `'List the user's habit loops with their current strategies.'`, `'Get one loop's full anatomy, active strategy, and recent outcome logs.'`, `'Get the latest rolling pattern summary for one loop.'`. Schemas: `[]` for ListLoops; `['intention_id' => $schema->integer()->required()]` for the other two.

- [ ] **Step 3: Run — PASS. Pint. Commit**

```bash
./vendor/bin/pint --dirty && php artisan test tests/Feature/Ai/ReadToolsTest.php && git add -A && git commit -m "feat: read-only coach data tools (loops, detail, summary)"
```

---

### Task 8: Coach orchestrator + conversational chat endpoint

**Files:**
- Create: `app/Ai/Agents/Coach.php`
- Modify: `app/Actions/RespondToChat.php`, `app/Http/Controllers/ChatController.php`, `app/Http/Requests/ChatRequest.php`
- Test: Modify `tests/Feature/ChatEndpointTest.php`; create `tests/Feature/Ai/CoachConversationTest.php`
- Delete: `app/Services/Coach/Chat/ChatCoach.php`, `app/Services/Coach/Chat/ChatReplySchema.php`, `app/Services/Coach/Authoring/IntentionAuthor.php`, `app/Services/Coach/Authoring/IntentionSchema.php`, `tests/Unit/Coach/ChatCoachTest.php`, `tests/Unit/Coach/IntentionAuthorTest.php`

- [ ] **Step 1: Implement the Coach agent**

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\GuardCoachUsage;
use App\Ai\Tools\CreateLoop;
use App\Ai\Tools\GetLatestSummary;
use App\Ai\Tools\GetLoopDetail;
use App\Ai\Tools\ListLoops;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The coach orchestrator. Owns every chat turn inside the user's durable
 * conversation: reads the user's loops/summaries through read-only tools and
 * delegates loop authoring to the IntentionAuthor specialist via CreateLoop.
 * Conversation history is stored server-side by the SDK.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.6)]
#[MaxTokens(1024)]
#[MaxSteps(6)]
class Coach implements Agent, Conversational, HasTools, HasMiddleware
{
    use Promptable, RemembersConversations;

    public const PROMPT_VERSION = '<copy from CoachPrompts::chat()>';

    public function instructions(): string
    {
        return <<<'PROMPT'
        <copy verbatim from CoachPrompts::chat() / CoachPrompts::charter(), amended:
        the "respond with reply + optional intention JSON envelope" section is
        REPLACED with tool guidance — answer conversationally; use list_loops /
        get_loop_detail / get_latest_summary to ground answers in the user's real
        data; use the create-loop tool when the user describes a habit to start
        or stop; never invent loops or data.>
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            app(CreateLoop::class),
            app(ListLoops::class),
            app(GetLoopDetail::class),
            app(GetLatestSummary::class),
        ];
    }

    public function middleware(): array
    {
        return [GuardCoachUsage::class];
    }
}
```

- [ ] **Step 2: Rewrite RespondToChat**

```php
<?php

namespace App\Actions;

use App\Ai\Agents\Coach;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\User;
use App\Services\Coach\Chat\ChatResult;

/**
 * Handles one chat turn end to end: prompts the Coach orchestrator inside the
 * user's durable conversation and collects any loops its tools authored. The
 * coach reads/writes through tools; this action just runs the turn.
 */
final readonly class RespondToChat
{
    public function __construct(private TurnCollector $collector) {}

    public function handle(User $user, string $message): ChatResult
    {
        $this->collector->flush();

        $response = (new Coach)
            ->forUser($user)
            ->continueLastConversation($user)
            ->prompt($message);

        $intention = Intention::query()
            ->whereIn('id', $this->collector->intentionIds())
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        return new ChatResult($response->text, $intention);
    }
}
```

(`ChatResult` is a tiny DTO `{string $message, ?Intention $intention}` — it survives; only `ChatCoach` dies. `continueLastConversation($user)` continues the most recent conversation or starts one — verify against `vendor/laravel/ai/src/Concerns/RemembersConversations.php:38`; if a fresh user errors instead of starting a conversation, fall back to: `$id = $user->conversations()->latest()->value('id'); $agent = (new Coach)->forUser($user); $id ? $agent->continue($id, $user) : null;`.)

- [ ] **Step 3: Slim ChatRequest** — delete the `history` rules and the `history()` helper; keep `message` required|string|max:2000. Update `ChatController::store` to `$respond->handle($request->user(), $request->validated('message'))`. The `cards()` builder is unchanged (still renders `IntentionResource` from `$result->intention`).

- [ ] **Step 4: Hydration props** — in `ChatController::home`, add to the Inertia props:

```php
'thread' => $this->recentThread($request->user()),
```

```php
/**
 * The user's recent coach conversation, oldest first, mapped to the
 * frontend's ChatMessage shape so the thread survives reloads.
 *
 * @return list<array{id: string, role: string, text: string}>
 */
private function recentThread(\Illuminate\Http\Request $request): array
{
    $conversation = $request->user()->conversations()->latest()->first();

    if ($conversation === null) {
        return [];
    }

    return $conversation->messages()
        ->oldest()
        ->limit(50)
        ->get()
        ->map(fn ($m) => [
            'id' => 'h'.$m->id,
            'role' => $m->role === 'user' ? 'user' : 'coach',
            'text' => (string) $m->content,
        ])
        ->values()
        ->all();
}
```

(Verify the message model's column names — `role`/`content` — in `vendor/laravel/ai/src/Models/ConversationMessage.php` and the package migration; adjust the two property reads if they differ.)

- [ ] **Step 5: Update ChatEndpointTest + new conversation test**

`ChatEndpointTest`: setUp swaps `FakeCoachService` for `Coach::fake([...])` returning plain text replies (cards now come from the CreateLoop tool, not the reply envelope — the "authors and persists an intention card" test changes: fake the Coach with a closure that runs the real CreateLoop? No — faked agents don't run tools. Instead that test moves DOWN a level: it now lives in `CreateLoopTest` (Task 6, already covering author→persist→card-id). Here, simulate the tool effect: fake Coach text reply, manually `app(TurnCollector::class)->addIntention($intention->id)` after creating a loop via factory, POST /chat, assert the card rides the response. That locks the controller's collector→cards path without real tool execution.)

New `tests/Feature/Ai/CoachConversationTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Coach;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_turns_persist_into_one_durable_conversation_per_user(): void
    {
        $user = User::factory()->create();
        Coach::fake(['First reply', 'Second reply']);

        $this->actingAs($user);
        $this->postJson('/chat', ['message' => 'hello'])->assertOk();
        $this->postJson('/chat', ['message' => 'again'])->assertOk();

        $this->assertSame(1, $user->conversations()->count());
        // 2 user turns + 2 assistant turns persisted server-side.
        $this->assertSame(4, $user->conversations()->first()->messages()->count());
    }

    public function test_dashboard_hydrates_the_thread(): void
    {
        $user = User::factory()->create();
        Coach::fake(['Reply one']);
        $this->actingAs($user);
        $this->postJson('/chat', ['message' => 'hello'])->assertOk();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('coach')
                ->has('thread', 2)
                ->where('thread.0.role', 'user')
                ->where('thread.0.text', 'hello')
                ->where('thread.1.role', 'coach')
            );
    }
}
```

(Add `$this->withoutVite()` in setUp if these hit ViteManifest errors. **If `Coach::fake()` turns out to bypass the `RememberConversation` persistence middleware, the first test cannot pass with a fake** — detect this immediately; if so, mark conversation-persistence assertions as requiring the real pipeline, drop to asserting the controller path only, and verify persistence manually via `php artisan coach:ping` after Task 10. Note the finding in the commit.)

- [ ] **Step 6: Delete old chat/authoring services + their unit tests; full suite; Pint; commit**

```bash
git rm app/Services/Coach/Chat/ChatCoach.php app/Services/Coach/Chat/ChatReplySchema.php \
       app/Services/Coach/Authoring/IntentionAuthor.php app/Services/Coach/Authoring/IntentionSchema.php \
       tests/Unit/Coach/ChatCoachTest.php tests/Unit/Coach/IntentionAuthorTest.php
./vendor/bin/pint --dirty && php artisan test
git add -A && git commit -m "feat: Coach orchestrator on SDK conversations replaces ChatCoach"
```

(`ChatException` may now be unreferenced — `grep -rn 'ChatException' app tests bootstrap` and delete from `bootstrap/app.php` renderers + the class if dead. `IntentionAuthoringException` likewise — `AuthoredIntention::fromResponse` may still use it; `fromResponse` itself may now be dead — if `grep -rn 'fromResponse' app tests` only hits the class, delete the method and the import.)

---

### Task 9: Frontend — slim chat client + thread hydration

**Files:**
- Modify: `resources/js/patyourself/chat/coach-client.ts`, `resources/js/patyourself/chat/chat-home.tsx`, `resources/js/pages/coach.tsx`, `resources/js/patyourself/chat/chat-home.test.tsx`
- Test: Vitest (existing file)

- [ ] **Step 1: Failing tests** — in `chat-home.test.tsx`:

```tsx
it('seeds from server-provided thread history instead of a greeting', () => {
    const thread = [
        { id: 'h1', role: 'user' as const, text: 'hello' },
        { id: 'h2', role: 'coach' as const, text: 'Hi — how did the run go?' },
    ];
    const { result } = renderHook(() =>
        useChatThread([makeIntention()], fakeClient(), thread),
    );

    const texts = result.current.messages.map((m) =>
        m.role === 'card' ? '[card]' : m.text,
    );
    expect(texts).toContain('hello');
    expect(texts).toContain('Hi — how did the run go?');
    // No synthetic greeting when real history exists.
    expect(texts.join(' ')).not.toMatch(/loops going/i);
});

it('sends only the message — no history payload', async () => {
    const send = vi.fn<CoachClient['sendMessage']>(async () => ({
        message: 'ok',
        cards: [],
    }));
    const { result } = renderHook(() =>
        useChatThread([], fakeClient({ sendMessage: send })),
    );

    act(() => result.current.send('morning'));

    await waitFor(() => expect(send).toHaveBeenCalledWith('morning'));
});
```

Run: `npx vitest run resources/js/patyourself/chat/chat-home.test.tsx` — FAIL (hook has no third param; sendMessage still takes history).

- [ ] **Step 2: Implement**

`coach-client.ts`: `sendMessage(message: string): Promise<CoachReply>` — drop the `history` param and `CoachTurn` export; `post('/chat', { message })`.

`chat-home.tsx`:
- `useChatThread(initialIntentions, client = httpCoachClient, initialThread: ThreadMessage[] = [])` where `ThreadMessage = { id: string; role: 'user' | 'coach'; text: string }`.
- `seedThread`: if `initialThread.length > 0` → `[...initialThread mapped to ChatMessage, ...cards]` (cards still appended so loops show); else current greeting+cards behaviour.
- Delete `toHistory()` and the `historyRef` (no longer sent); `converse` calls `client.sendMessage(trimmed)`.
- Existing "sends prior turns as history" test: DELETE it (contract gone) — replaced by the new "sends only the message" test.

`coach.tsx`: add `thread?: { id: string; role: 'user' | 'coach'; text: string }[]` to `CoachProps`, pass `useChatThread(intentions, undefined, thread ?? [])`. (Keep default client by passing `undefined` — or reorder params to `(intentions, thread, client)`; pick reorder if `undefined` reads badly, and update tests accordingly.)

- [ ] **Step 3: Run vitest + eslint + prettier + tsc — all green**

```bash
npx vitest run && npx eslint resources/js/patyourself/chat/ resources/js/pages/coach.tsx && npx prettier --check resources/js/patyourself/chat/ resources/js/pages/coach.tsx && npm run types:check 2>&1 | grep -E 'chat|coach' || echo CLEAN
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: chat thread hydrates from server conversation; client sends message only"
```

---

### Task 10: Teardown — delete the hand-rolled stack

**Files:**
- Delete: `app/Services/Coach/Contracts/CoachService.php`, `app/Services/Coach/CoachManager.php`, `app/Services/Coach/Drivers/AnthropicCoachService.php`, `app/Services/Coach/GuardedCoachService.php`, `app/Services/Coach/FakeCoachService.php`, `app/Services/Coach/Data/{CoachRequest,CoachResponse,Message,Role}.php`, `app/Services/Coach/Prompts/{CoachPrompts,CoachPrompt}.php`, `app/Services/Coach/Exceptions/CoachException.php` → **keep** (CoachQuotaException extends it; quota rendering uses it), plus dead tests: `tests/Feature/Coach/{AnthropicCoachServiceTest,AnthropicRetryTest,CoachManagerTest,GuardedCoachServiceTest}.php`, `tests/Unit/Coach/{CoachRequestTest,CoachResponseTest,CoachPromptsTest,FakeCoachServiceTest}.php`, `tests/Feature/PromptVersioningTest.php` (version assertions live in agent tests now — port any uncovered assertion into the agent tests first)
- Modify: `app/Providers/AppServiceProvider.php` (drop CoachService/CoachManager bindings), `app/Console/Commands/CoachPing.php`, `app/Services/Coach/Usage/CoachUsageGuard.php` (`record()` signature: take `string $model, int $promptTokens, int $completionTokens` instead of `CoachResponse`), `app/Ai/Middleware/GuardCoachUsage.php` (call the new signature), `tests/Feature/Coach/{CoachUsageGuardTest,CoachHardeningTest}.php` (new signature + `Coach::fake()` instead of binding fakes)

- [ ] **Step 1: Inventory references before deleting**

```bash
grep -rn "CoachService\|CoachManager\|FakeCoachService\|CoachRequest\|CoachResponse\|CoachPrompts" app tests bootstrap config routes --include='*.php' | grep -v 'Ai/\|CoachQuotaException\|CoachUsageGuard'
```

Every hit must be migrated or deleted by this task. Anything unexpected → stop and resolve before `git rm`.

- [ ] **Step 2: Simplify CoachUsageGuard::record signature; update middleware + its tests.**

- [ ] **Step 3: Port CoachPing** to prompt an `AnonymousAgent` or a minimal `Coach` smoke (`(new Coach)->prompt('ping')` without conversation) — keep the command name `coach:ping`.

- [ ] **Step 4: Rewrite CoachHardeningTest** — same four behaviours (meter, 429 over budget, 503 on failure, throttle) via `Coach::fake()`; the 503 test fakes a throwing closure: `Coach::fake(fn () => throw new \App\Services\Coach\Exceptions\CoachException('down'))`.

- [ ] **Step 5: Delete files (`git rm`), drop bindings, full verify**

```bash
./vendor/bin/pint --dirty && php artisan test && npx vitest run && composer run lint:check
php artisan route:cache && php artisan route:clear   # deploy readiness intact
```

Expected: everything green; old `Services/Coach` reduced to `{Authoring DTOs, Chat/ChatResult, Strategy/BehavioralChain+exceptions, Summary leftovers if any, Usage, Exceptions}`.

- [ ] **Step 6: Update `.env.example`** — remove `COACH_DRIVER`, `COACH_MAX_TOKENS`, `COACH_TEMPERATURE`, `COACH_TIMEOUT`, `COACH_RETRIES` (now per-agent attributes / SDK config); keep `ANTHROPIC_*`, `COACH_DAILY_TOKEN_BUDGET`, `COACH_RATE_PER_MINUTE`. Mirror in `.env.production.example` + adjust `config/services.php` coach block to just budget + rate. Update `docs/DEPLOY-FORGE.md` env section.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "refactor: retire hand-rolled coach stack; AI SDK agents own all LLM calls"
```

---

## Self-review notes

- **Spec coverage:** decisions 1–3 → Tasks 4/5/8 (strangler order), 8/9 (conversations + hydration), 6/7/8 (tool surface). Cost guard → Task 2 + 10. Teardown table → Tasks 4/5/8/10. CoachPing → Task 10. Risks: structured-output spike (Task 4 Step 1), fake×middleware persistence risk (Task 8 Step 5), usage shape (Task 2 note).
- **Known executor checkpoints (intentional, with exact sources):** prompt text copies from `CoachPrompts` (Tasks 4/5/6/8), SDK constructor shapes for `AgentResponse`/`Meta`/`ToolRequest` (Tasks 2/6), `ConversationMessage` columns (Task 8 Step 4), `continueLastConversation` fresh-user behaviour (Task 8 Step 2).
- **Type consistency:** `TurnCollector.addIntention(int)/intentionIds()/flush()` used identically in Tasks 3/6/8; `CoachUsageGuard` old signature in Tasks 2–9, simplified only in Task 10; `LogOutcome`/frontend types untouched.
