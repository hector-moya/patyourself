# MCP `create-loop` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Claude Desktop author a habit loop over MCP and persist it as a **paused** loop the user activates in the app.

**Architecture:** A new `create-loop` MCP tool accepts the fully structured loop from the client, builds the existing `AuthoredIntention` DTO, and persists through `AuthorIntention` — the same writer the in-app coach uses — with no server-side LLM call. `AuthorIntention` gains an optional status so the MCP path can create `paused`. The app gets an Activate button, and activation re-anchors stale pending actions.

**Tech Stack:** Laravel 13, PHP 8.4, laravel/mcp v0.8, PHPUnit 12, React 19 + Inertia v3, vitest.

## Global Constraints

- Tool names are pinned with `#[Name]`. Never rely on the class-basename default — `Str::kebab(class_basename())` would produce `create-loop-tool`.
- No LLM call may occur inside an MCP tool. The client is the intelligence.
- Tools resolve the user via `$request->user()` only. Never accept a user id as an argument.
- `vendor/bin/pint --dirty --format agent` before every commit.
- Run tests with `php artisan test --compact`.
- Spec: `docs/superpowers/specs/2026-08-24-mcp-create-loop-design.md`.

---

### Task 1: `AuthorIntention` accepts an explicit status

**Files:**
- Modify: `app/Actions/AuthorIntention.php:35` (`handle`), `:66` (`persist`)
- Test: `tests/Feature/AuthorIntentionTest.php`

**Interfaces:**
- Produces: `AuthorIntention::handle(User $user, string $goal, array $context = [], ?AuthoredIntention $authored = null, string $status = Intention::STATUS_ACTIVE): Intention`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/AuthorIntentionTest.php`:

```php
public function test_persists_the_requested_status(): void
{
    $user = User::factory()->create();

    $intention = app(AuthorIntention::class)->handle(
        $user,
        'read more',
        [],
        $this->authored(),
        Intention::STATUS_PAUSED,
    );

    $this->assertSame(Intention::STATUS_PAUSED, $intention->status);
}

public function test_defaults_to_active_when_no_status_is_given(): void
{
    $user = User::factory()->create();

    $intention = app(AuthorIntention::class)->handle($user, 'read more', [], $this->authored());

    $this->assertSame(Intention::STATUS_ACTIVE, $intention->status);
}
```

Add this helper to the same class if one does not already exist (check first — the file may already build an `AuthoredIntention`; reuse that helper instead of adding a duplicate):

```php
private function authored(): AuthoredIntention
{
    return AuthoredIntention::fromStructured([
        'title' => 'Read before bed',
        'type' => Intention::TYPE_BUILD,
        'cue' => 'Phone goes on the charger',
        'craving' => 'Wind down',
        'response' => 'Read ten pages',
        'reward' => 'Calmer sleep',
        'strategy' => [
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the book on the pillow',
        ],
    ], 'test-model', 'test@1');
}
```

Imports needed: `App\Actions\AuthorIntention`, `App\Models\Intention`, `App\Models\Strategy`, `App\Models\User`, `App\Services\Coach\Authoring\AuthoredIntention`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_persists_the_requested_status`
Expected: FAIL — `handle()` takes 4 arguments, or the status comes back `active`.

- [ ] **Step 3: Thread the status through**

In `app/Actions/AuthorIntention.php`, change the signature:

```php
public function handle(
    User $user,
    string $goal,
    array $context = [],
    ?AuthoredIntention $authored = null,
    string $status = Intention::STATUS_ACTIVE,
): Intention {
```

and the return line inside it:

```php
return DB::transaction(fn (): Intention => $this->persist($user, $authored, $status));
```

Change `persist`'s signature and the status it writes:

```php
private function persist(User $user, AuthoredIntention $authored, string $status): Intention
{
    $intention = Intention::create([
        'user_id' => $user->id,
        'title' => $authored->title,
        'description' => $authored->description,
        'type' => $authored->type,
        'status' => $status,
```

Leave the rest of `persist` untouched.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AuthorIntentionTest.php`
Expected: PASS, including the pre-existing tests — the default keeps every current caller behaving identically.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/AuthorIntention.php tests/Feature/AuthorIntentionTest.php
git commit -m "feat(loops): let AuthorIntention persist an explicit status"
```

---

### Task 2: The `create-loop` tool

**Files:**
- Create: `app/Mcp/Tools/CreateLoopTool.php`
- Test: `tests/Feature/Mcp/CreateLoopToolTest.php`

**Interfaces:**
- Consumes: `AuthorIntention::handle(..., string $status)` from Task 1.
- Produces: `App\Mcp\Tools\CreateLoopTool`, advertised as `create-loop`, returning JSON keys `loop_id`, `title`, `status`, `next_step`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/CreateLoopToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\CreateLoopTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class CreateLoopToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function arguments(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Read before bed',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Phone goes on the charger',
            'craving' => 'Wind down',
            'response' => 'Read ten pages',
            'reward' => 'Calmer sleep',
            'strategy' => [
                'intervention_point' => Strategy::POINT_CUE,
                'approach' => 'Put the book on the pillow',
                'rationale' => 'Makes the cue impossible to miss',
            ],
        ], $overrides);
    }

    public function test_creates_the_loop_paused_with_its_first_strategy(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(
            ['loop_id', 'title', 'status', 'next_step'],
            array_keys($payload),
        );
        $this->assertSame(Intention::STATUS_PAUSED, $payload['status']);
        $this->assertSame('Read before bed', $payload['title']);

        $intention = Intention::findOrFail($payload['loop_id']);

        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame(Intention::STATUS_PAUSED, $intention->status);
        $this->assertSame('Phone goes on the charger', $intention->cue);

        $strategy = $intention->strategies()->sole();

        $this->assertSame(1, $strategy->version);
        $this->assertSame(Strategy::POINT_CUE, $strategy->intervention_point);
    }

    public function test_records_the_client_as_the_author(): void
    {
        $user = User::factory()->create();

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);

        $this->assertSame(CreateLoopTool::AUTHORED_BY, $intention->metadata['authored_by']);
    }

    public function test_creates_the_optional_first_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => [
                    'title' => 'Read ten pages',
                    'kind' => 'clock',
                    'time' => '21:30',
                    'recurrence' => 'daily',
                ],
            ]));

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);
        $action = $intention->actions()->sole();

        $this->assertSame('Read ten pages', $action->title);
        $this->assertSame('daily', $action->recurrence);
        $this->assertSame(Action::STATUS_PENDING, $action->status);
    }

    public function test_creates_no_action_when_the_block_is_absent(): void
    {
        $user = User::factory()->create();

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);

        $this->assertSame(0, $intention->actions()->count());
    }

    public function test_the_loop_belongs_only_to_the_calling_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        PatYourSelfServer::actingAs($owner)
            ->tool(CreateLoopTool::class, $this->arguments())
            ->assertOk();

        $this->assertSame(0, $stranger->intentions()->count());
    }

    public function test_rejects_an_unknown_type(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments(['type' => 'sideways']))
            ->assertHasErrors();
    }

    public function test_rejects_an_unknown_intervention_point(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'strategy' => ['intervention_point' => 'vibes', 'approach' => 'x'],
            ]))
            ->assertHasErrors();
    }

    public function test_rejects_a_clock_action_without_a_time(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => ['title' => 'Read', 'kind' => 'clock', 'recurrence' => 'daily'],
            ]))
            ->assertHasErrors();
    }

    public function test_rejects_an_anchored_action_without_an_anchor(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => ['title' => 'Read', 'kind' => 'anchored'],
            ]))
            ->assertHasErrors();
    }

    public function test_returns_a_tool_error_when_the_strategy_block_is_structurally_invalid(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'strategy' => [
                    'intervention_point' => Strategy::POINT_CUE,
                    'approach' => '   ',
                ],
            ]))
            ->assertHasErrors();
    }

    public function test_prompts_no_agent(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments())
            ->assertOk();

        \Laravel\Ai\Facades\Ai::assertAgentNeverPrompted(\App\Ai\Agents\IntentionAuthor::class);
    }
}
```

Note on `test_prompts_no_agent`: the base `Tests\TestCase` already fakes every agent and calls `Http::preventStrayRequests()`, so a stray LLM call would fail loudly anyway. This test states the intent explicitly. If the `Ai` facade assertion name differs in this version, check `vendor/laravel/ai/src/Concerns/InteractsWithFakeAgents.php` — the method is `assertAgentNeverPrompted(string $agent)`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/CreateLoopToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\CreateLoopTool" not found`.

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/CreateLoopTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Actions\AuthorIntention;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-loop')]
#[Description('Create a new habit loop from a cue -> craving -> response -> reward chain the user has agreed to. The loop is created PAUSED so the user reviews and activates it in the app. Ask the user about their real cue, craving, response and reward before calling this — do not invent them.')]
class CreateLoopTool extends Tool
{
    /** Provenance stamped onto the loop's metadata, distinguishing MCP-authored loops from IntentionAuthor ones. */
    public const AUTHORED_BY = 'mcp-client';

    public const PROMPT_VERSION = 'mcp@1';

    private const KINDS = ['clock', 'anchored'];

    private const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];

    /**
     * The client authors the structure; this only validates and persists, through
     * the same AuthorIntention writer the in-app coach uses. Passing an authored
     * DTO means AuthorIntention never reaches its LLM branch, so this tool makes
     * no model call and costs nothing against the coach budget.
     */
    public function handle(Request $request, AuthorIntention $author): Response
    {
        $kind = data_get($request->get('action'), 'kind');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'string', Rule::in([Intention::TYPE_BUILD, Intention::TYPE_BREAK])],
            'cue' => ['required', 'string', 'max:1000'],
            'craving' => ['required', 'string', 'max:1000'],
            'response' => ['required', 'string', 'max:1000'],
            'reward' => ['required', 'string', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'strategy' => ['required', 'array'],
            'strategy.intervention_point' => ['required', 'string', Rule::in([
                Strategy::POINT_CUE,
                Strategy::POINT_CRAVING,
                Strategy::POINT_RESPONSE,
                Strategy::POINT_REWARD,
            ])],
            'strategy.approach' => ['required', 'string', 'max:1000'],
            'strategy.rationale' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'array'],
            'action.title' => ['required_with:action', 'string', 'max:255'],
            'action.description' => ['nullable', 'string', 'max:1000'],
            'action.kind' => ['required_with:action', 'string', Rule::in(self::KINDS)],
            'action.time' => [Rule::requiredIf(fn (): bool => $kind === 'clock'), 'nullable', 'date_format:H:i'],
            'action.recurrence' => [Rule::requiredIf(fn (): bool => $kind === 'clock'), 'nullable', 'string', Rule::in(self::RECURRENCES)],
            'action.anchor' => [Rule::requiredIf(fn (): bool => $kind === 'anchored'), 'nullable', 'string', 'max:255'],
        ]);

        // Validation above covers the same ground, but fromStructured is the DTO's
        // own guard and throws rather than returning errors. Convert it to a tool
        // error so a structural mismatch reads as "fix your arguments", not a 500.
        try {
            $authored = AuthoredIntention::fromStructured(
                $validated,
                self::AUTHORED_BY,
                self::PROMPT_VERSION,
            );
        } catch (CoachException) {
            return Response::error('That loop is missing required structure. Provide title, type, cue, craving, response, reward and a strategy.');
        }

        $intention = $author->handle(
            $request->user(),
            $validated['title'],
            [],
            $authored,
            Intention::STATUS_PAUSED,
        );

        return Response::json([
            'loop_id' => $intention->id,
            'title' => $intention->title,
            'status' => $intention->status,
            'next_step' => 'Created paused. Tell the user to open PatYourSelf and activate it.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Short name for the loop, in the user\'s own words.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional longer framing of the habit.'),
            'type' => $schema->string()
                ->enum([Intention::TYPE_BUILD, Intention::TYPE_BREAK])
                ->description('build to form a habit, break to stop one.')
                ->required(),
            'cue' => $schema->string()
                ->description('What triggers the behaviour. The user\'s real cue, not an invented one.')
                ->required(),
            'craving' => $schema->string()
                ->description('The underlying want the cue provokes.')
                ->required(),
            'response' => $schema->string()
                ->description('The behaviour itself.')
                ->required(),
            'reward' => $schema->string()
                ->description('What the user gets from it.')
                ->required(),
            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional free-form tags.'),
            'strategy' => $schema->object([
                'intervention_point' => $schema->string()
                    ->enum([
                        Strategy::POINT_CUE,
                        Strategy::POINT_CRAVING,
                        Strategy::POINT_RESPONSE,
                        Strategy::POINT_REWARD,
                    ])
                    ->description('Which point of the chain this first strategy acts on.')
                    ->required(),
                'approach' => $schema->string()
                    ->description('The concrete intervention.')
                    ->required(),
                'rationale' => $schema->string()
                    ->description('Why this point and this approach.'),
            ])->description('The first strategy version.')->required(),
            'action' => $schema->object([
                'title' => $schema->string()->description('The concrete to-do.')->required(),
                'description' => $schema->string(),
                'kind' => $schema->string()
                    ->enum(self::KINDS)
                    ->description('clock for a scheduled time, anchored to hang off another routine.')
                    ->required(),
                'time' => $schema->string()->description('HH:MM local time. Required when kind is clock.'),
                'recurrence' => $schema->string()
                    ->enum(self::RECURRENCES)
                    ->description('Required when kind is clock.'),
                'anchor' => $schema->string()->description('The routine to hang off. Required when kind is anchored.'),
            ])->description('Optional first action. Omit it and the user schedules one in the app.'),
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Mcp/CreateLoopToolTest.php`
Expected: PASS (11 tests).

If `test_creates_the_optional_first_action` fails on `recurrence`, check `Recurrence::tryFromToken()` in `app/Services/Scheduling/` for the exact accepted token strings and align `self::RECURRENCES`.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp/Tools/CreateLoopTool.php tests/Feature/Mcp/CreateLoopToolTest.php
git commit -m "feat(mcp): create-loop tool"
```

---

### Task 3: Advertise the tool and tell Claude how to use it

**Files:**
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`
- Test: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `App\Mcp\Tools\CreateLoopTool` from Task 2.
- Produces: six advertised tools, `create-loop` last.

- [ ] **Step 1: Update the failing assertion**

In `tests/Feature/Mcp/McpEndpointTest.php`, rename the test and extend the expected list:

```php
public function test_advertises_all_six_tools_under_their_documented_names(): void
{
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $response = $this->toolsList();

    $response->assertOk();

    $this->assertSame(
        ['list-loops', 'get-loop', 'today-actions', 'log-action-outcome', 'loop-progress', 'create-loop'],
        array_column($response->json('result.tools'), 'name'),
    );
}
```

Leave `test_every_advertised_tool_name_appears_in_the_server_instructions` alone — it will now also require `create-loop` to appear in the instructions, which Step 3 adds.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Mcp/McpEndpointTest.php`
Expected: FAIL twice — the advertised list has five names, and `create-loop` is not in the instructions.

- [ ] **Step 3: Register the tool and extend the instructions**

In `app/Mcp/Servers/PatYourSelfServer.php`, add the import `use App\Mcp\Tools\CreateLoopTool;` and append to `$tools`:

```php
    protected array $tools = [
        ListLoopsTool::class,
        GetLoopTool::class,
        TodayActionsTool::class,
        LogActionOutcomeTool::class,
        LoopProgressTool::class,
        CreateLoopTool::class,
    ];
```

Append this paragraph to the `#[Instructions]` heredoc, after the existing final paragraph:

```
Use create-loop when the user wants to start a new habit. Ask them for their
real cue, craving, response and reward and get their agreement on the wording —
do not invent the chain for them, because the loop only works if it describes
their actual behaviour. New loops are created paused; tell the user to open the
app to review and activate.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: PASS — all MCP tests including the six-name assertion.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp/Servers/PatYourSelfServer.php tests/Feature/Mcp/McpEndpointTest.php
git commit -m "feat(mcp): advertise create-loop and document it in the server instructions"
```

---

### Task 4: Re-anchor pending actions on activation

**Files:**
- Modify: `app/Actions/UpdateIntention.php`
- Test: `tests/Feature/UpdateIntentionTest.php` (create if absent)

**Interfaces:**
- Produces: `UpdateIntention::handle()` re-anchors pending clock actions when status moves `paused` -> `active`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Actions\UpdateIntention;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdateIntentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_paused_loop_reanchors_a_stale_clock_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => Carbon::now()->subDays(3)->setTime(21, 30),
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertTrue($action->fresh()->scheduled_for->isFuture());
    }

    public function test_activating_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => null,
            'scheduled_for' => null,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertNull($action->fresh()->scheduled_for);
    }

    public function test_a_plain_title_edit_does_not_touch_schedules(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $stale = Carbon::now()->subDays(3)->setTime(21, 30);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => $stale,
        ]);

        app(UpdateIntention::class)->handle($intention, ['title' => 'Renamed']);

        $this->assertTrue($action->fresh()->scheduled_for->equalTo($stale));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/UpdateIntentionTest.php`
Expected: FAIL on the first test — `scheduled_for` is still three days in the past.

- [ ] **Step 3: Re-anchor on the transition**

Rewrite `app/Actions/UpdateIntention.php`:

```php
<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;

/**
 * Updates an existing loop from validated input. Only keys present in the
 * payload are touched, so partial (PATCH-style) edits leave the rest intact.
 * The only place the manual update flow writes to the database.
 */
final readonly class UpdateIntention
{
    /**
     * @param  array<string, mixed>  $data  Validated subset of loop fields.
     */
    public function handle(Intention $intention, array $data): Intention
    {
        $fields = array_intersect_key($data, array_flip([
            'title',
            'description',
            'type',
            'status',
            'cue',
            'craving',
            'response',
            'reward',
        ]));

        $wasPaused = $intention->status === Intention::STATUS_PAUSED;

        $intention->update($fields);

        if ($wasPaused && $intention->status === Intention::STATUS_ACTIVE) {
            $this->reanchorPendingActions($intention);
        }

        return $intention;
    }

    /**
     * A loop can sit paused for days before the user activates it, leaving any
     * clock action scheduled in the past — it would fire the moment the loop went
     * live. Push each one to its next real occurrence. Anchored actions carry no
     * clock time and are left alone.
     */
    private function reanchorPendingActions(Intention $intention): void
    {
        $timezone = $intention->user->timezone ?? (string) config('app.timezone');
        $schedule = new Schedule;
        $now = CarbonImmutable::now();

        $intention->actions()
            ->where('status', Action::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->get()
            ->each(function (Action $action) use ($schedule, $now, $timezone): void {
                $localTime = $action->scheduled_for->setTimezone($timezone)->format('H:i');

                $action->update([
                    'scheduled_for' => $schedule->firstOccurrence(
                        $now,
                        $localTime,
                        Recurrence::tryFromToken($action->recurrence),
                        $timezone,
                    ),
                ]);
            });
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/UpdateIntentionTest.php`
Expected: PASS (3 tests).

Then run the loop suites that touch this action to catch regressions:
Run: `php artisan test --compact tests/Feature/Api tests/Feature/Scheduling`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/UpdateIntention.php tests/Feature/UpdateIntentionTest.php
git commit -m "feat(loops): re-anchor pending actions when a paused loop is activated"
```

---

### Task 5: The Activate button

**Files:**
- Modify: `resources/js/pages/intentions/show.tsx`
- Test: `resources/js/pages/intentions/show.test.tsx` (create if absent)

**Interfaces:**
- Consumes: the existing `PATCH intentions/{intention}` route (`intentions.update`) and Task 4's re-anchoring.

- [ ] **Step 1: Write the failing test**

Create `resources/js/pages/intentions/show.test.tsx`:

```tsx
import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { IntentionData } from '@/patyourself/types';

const page = { url: '/intentions/1', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import LoopShow from './show';

function intention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Read before bed',
        type: 'build',
        status: 'active',
        cue: 'Phone on the charger',
        craving: 'Wind down',
        response: 'Read ten pages',
        reward: 'Calmer sleep',
        description: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: null,
        ...overrides,
    };
}

describe('LoopShow', () => {
    it('offers to activate a paused loop', () => {
        render(<LoopShow intention={intention({ status: 'paused' })} strategies={[]} />);

        expect(screen.getByRole('button', { name: /activate/i })).toBeInTheDocument();
    });

    it('does not offer activation for an active loop', () => {
        render(<LoopShow intention={intention({ status: 'active' })} strategies={[]} />);

        expect(screen.queryByRole('button', { name: /activate/i })).not.toBeInTheDocument();
    });
});
```

These are every field on `IntentionData` (`resources/js/patyourself/types.ts:42`), so the fixture needs no cast.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test -- show.test`
Expected: FAIL — no button matching /activate/i.

- [ ] **Step 3: Add the button**

In `resources/js/pages/intentions/show.tsx`, import the Wayfinder action and the `Form` component:

```tsx
import { Form, Link } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
```

Then render it directly after the badges section:

```tsx
{intention.status === 'paused' && (
    <Form {...update.form(intention.id)} className="flex flex-col gap-2">
        <input type="hidden" name="status" value="active" />
        <button
            type="submit"
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
        >
            Activate loop
        </button>
        <p className="text-xs text-muted-foreground">
            Claude drafted this loop. Activating it starts its schedule and notifications.
        </p>
    </Form>
)}
```

If `@/actions/App/Http/Controllers/IntentionController` does not export `update`, run `php artisan wayfinder:generate` and check the generated file for the correct export name.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test -- show.test`
Expected: PASS (2 tests).

Then: `npm run types:check` and `npm run lint:check`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/intentions/show.tsx resources/js/pages/intentions/show.test.tsx
git commit -m "feat(ui): activate a paused loop from its detail page"
```

---

### Task 6: Full verification

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test --compact`
Expected: PASS. Baseline before this plan is 366; this plan adds roughly 18 tests.

- [ ] **Step 2: Run the frontend checks**

Run: `npm run test && npm run types:check && npm run lint:check`
Expected: clean.

- [ ] **Step 3: Confirm the advertised tool names over HTTP**

Run: `php artisan test --compact --filter=test_advertises_all_six_tools_under_their_documented_names`
Expected: PASS.

- [ ] **Step 4: Push and open a PR**

```bash
vendor/bin/pint --dirty --format agent
git push -u origin worktree-mcp-create-loop
gh pr create --title "feat(mcp): create-loop — Claude Desktop authors, the user activates" --body "Implements docs/superpowers/specs/2026-08-24-mcp-create-loop-design.md"
```
