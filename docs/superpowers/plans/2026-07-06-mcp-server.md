# PatYourSelf MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose a `laravel/mcp` server at `/mcp`, protected by Passport OAuth 2.1, so claude.ai custom connectors can read a user's habit loops and log action outcomes.

**Architecture:** In-app MCP server (`app/Mcp/`) registered in `routes/ai.php` behind a new Passport `auth:api` guard. Five thin tools wrap existing Actions/services — `LogAction` is the only write path. Sanctum keeps the existing `/api/*` routes; Passport is added alongside it.

**Tech Stack:** PHP 8.4, Laravel 13, laravel/mcp v0, laravel/passport (latest, v13.x), Sanctum v4 (existing), PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-07-06-mcp-server-design.md`

## Global Constraints

- Every change programmatically tested; run affected tests with `php artisan test --compact --filter=...` (PHPUnit classes, never Pest).
- All writes to the DB go through `app/Actions` classes — tools never write directly.
- No LLM calls inside any MCP tool.
- Use model constants, never string literals: `Intention::STATUSES`, `Action::OPEN_STATUSES`, `ActionLog::OUTCOMES`, etc.
- Explicit return types and parameter type hints on all methods; curly braces on all control structures; PHPDoc over inline comments.
- `reason` is required when outcome is `failed`, max 2000 chars (mirrors `LogActionRequest`).
- Unknown or other-user IDs return uniform `Response::error('Not found.')` — never reveal existence.
- Run `vendor/bin/pint --dirty --format agent` before every commit.
- Existing Sanctum API (`routes/api.php`) and web auth must keep working — the full existing suite must stay green.
- Work happens in the worktree at `.claude/worktrees/mcp-server` on branch `worktree-mcp-server`.

---

### Task 1: Install laravel/mcp + laravel/passport, wire Passport alongside Sanctum

Package installation can't be test-driven; the acceptance gate is the reflection check in Step 4 plus the full existing suite staying green (Step 9).

**Files:**
- Modify: `composer.json` (via composer require)
- Modify: `app/Models/User.php`
- Modify: `config/auth.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create (published): `resources/views/mcp/authorize.blade.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `auth:api` guard backed by Passport; `App\Models\User` usable by both Sanctum and Passport guards; `view('mcp.authorize')` registered as Passport's authorization view. Later tasks rely on `Passport::actingAs($user, ['mcp:use'])` working in tests.

- [ ] **Step 1: Install dependencies**

```bash
composer install --no-interaction   # fresh worktree has no vendor/
composer require laravel/mcp laravel/passport --no-interaction
```

Expected: both packages resolve and install without conflicts.

- [ ] **Step 2: Run Passport migrations**

```bash
php artisan migrate --no-interaction
```

Expected: `oauth_*` migrations run (`oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_device_codes`).

If no oauth migrations ran, publish then migrate:

```bash
php artisan vendor:publish --tag=passport-migrations --no-interaction
php artisan migrate --no-interaction
```

- [ ] **Step 3: Generate local Passport keys**

```bash
php artisan passport:keys --no-interaction
```

Expected: `Encryption keys generated successfully.` (Keys land in `storage/` which is gitignored — tests don't need them because `Passport::actingAs` bypasses crypto.)

- [ ] **Step 4: Compute the exact trait-method overlap**

Sanctum's and Passport's traits are both named `HasApiTokens` and overlap on several methods. Compute the exact intersection:

```bash
php -r 'require "vendor/autoload.php";
$names = fn (string $trait): array => array_map(fn ($m) => $m->getName(), (new ReflectionClass($trait))->getMethods());
$overlap = array_values(array_intersect($names(Laravel\Sanctum\HasApiTokens::class), $names(Laravel\Passport\HasApiTokens::class)));
sort($overlap); print_r($overlap);'
```

Expected (approximately — Sanctum v4 declares `tokens`, `tokenCan`, `tokenCant`, `createToken`, `generateTokenString`, `currentAccessToken`, `withAccessToken`):

```
Array ( [0] => createToken [1] => currentAccessToken [2] => tokenCan [3] => tokens [4] => withAccessToken )
```

The `insteadof` list in Step 5 must cover **exactly** this printed intersection — add/remove lines to match it.

- [ ] **Step 5: Resolve the trait conflict in the User model**

Modify `app/Models/User.php`. Sanctum wins every conflicting method (the existing `/api/auth/token` flow depends on Sanctum's `createToken`); Passport's variants get `oauth`-prefixed aliases. Both traits' `withAccessToken`/`currentAccessToken` just set/read `$this->accessToken`, and Sanctum's `tokenCan` calls `->can()` which Passport's `AccessToken` also implements, so the Passport guard works with Sanctum's implementations winning.

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Ai\Concerns\HasConversations;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens as HasOAuthTokens;
use Laravel\Sanctum\HasApiTokens as HasSanctumTokens;

#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasConversations, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Sanctum powers the existing mobile-API tokens, Passport powers the MCP
     * OAuth guard. Both traits share method names; Sanctum's implementations
     * win (the /api/auth/token flow depends on them) and remain compatible
     * with Passport's guard, which only sets/reads the accessToken property
     * and calls can() on the token object.
     */
    use HasOAuthTokens, HasSanctumTokens {
        HasSanctumTokens::tokens insteadof HasOAuthTokens;
        HasSanctumTokens::tokenCan insteadof HasOAuthTokens;
        HasSanctumTokens::createToken insteadof HasOAuthTokens;
        HasSanctumTokens::currentAccessToken insteadof HasOAuthTokens;
        HasSanctumTokens::withAccessToken insteadof HasOAuthTokens;
        HasOAuthTokens::tokens as oauthTokens;
        HasOAuthTokens::createToken as createOAuthToken;
    }

    // ... casts(), intentions(), actionLogs(), summaries() unchanged ...
}
```

Adjust the `insteadof`/`as` lines to the exact intersection printed in Step 4 (e.g. include `tokenCant`/`generateTokenString` only if they appeared). If `Laravel\Passport\Contracts\OAuthenticatable` does not exist in the installed Passport version, drop the interface — the guard only needs the trait.

- [ ] **Step 6: Add the Passport `api` guard**

Modify `config/auth.php` guards block:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

- [ ] **Step 7: Publish and register the MCP authorization view**

```bash
php artisan vendor:publish --tag=mcp-views --no-interaction
```

Expected: creates `resources/views/mcp/authorize.blade.php`.

In `app/Providers/AppServiceProvider.php`, add to `boot()` (keep existing calls) and import `Laravel\Passport\Passport`:

```php
public function boot(): void
{
    $this->configureDefaults();
    $this->configureRateLimiting();

    Passport::authorizationView(
        fn (array $parameters) => view('mcp.authorize', $parameters),
    );
}
```

- [ ] **Step 8: Sanity-check routes**

```bash
php artisan route:list --path=oauth --except-vendor=0 2>/dev/null | head -20
```

Expected: Passport routes present (`oauth/token`, `oauth/authorize`, ...).

- [ ] **Step 9: Full existing suite still green**

```bash
php artisan test --compact
```

Expected: PASS — zero failures. Pay attention to `tests/Feature/Api/AuthTokenTest.php` (Sanctum `createToken` still resolves to Sanctum's implementation).

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): install laravel/mcp + passport, wire OAuth guard alongside Sanctum"
```

---

### Task 2: PatYourSelfServer, routes/ai.php, OAuth discovery + endpoint auth tests

**Files:**
- Create: `app/Mcp/Servers/PatYourSelfServer.php`
- Create: `routes/ai.php` (published, then edited)
- Test: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `auth:api` guard from Task 1.
- Produces: `App\Mcp\Servers\PatYourSelfServer` (extends `Laravel\Mcp\Server`) with an empty `protected array $tools = []` that Tasks 3–7 append tool class-strings to; `/mcp` HTTP endpoint; OAuth discovery routes. Test pattern `Passport::actingAs($user, ['mcp:use'])` for HTTP-level tests and `PatYourSelfServer::actingAs($user)->tool(...)` for tool unit tests.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/McpEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The MCP endpoint's front door: OAuth discovery metadata for claude.ai's
 * dynamic client registration, and Passport-guarded access to /mcp itself.
 */
class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_oauth_discovery_metadata(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
            ]);
    }

    public function test_publishes_protected_resource_metadata(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    }

    public function test_guests_cannot_reach_the_mcp_endpoint(): void
    {
        $this->postJson('/mcp', $this->initializePayload())->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_initialize(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('PatYourSelf', $response->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ];
    }
}
```

(`assertStringContainsString` instead of `assertJsonPath` because the streamable-HTTP transport may answer as `text/event-stream` rather than plain JSON.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/McpEndpointTest.php`
Expected: FAIL — 404s (routes don't exist yet).

- [ ] **Step 3: Publish routes and create the server**

```bash
php artisan vendor:publish --tag=ai-routes --no-interaction
php artisan make:mcp-server PatYourSelfServer --no-interaction
```

Replace the contents of `routes/ai.php`:

```php
<?php

use App\Mcp\Servers\PatYourSelfServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth 2.1 discovery + dynamic client registration — what lets claude.ai
// register itself as a client and walk the user through authorization.
Mcp::oauthRoutes();

Mcp::web('/mcp', PatYourSelfServer::class)
    ->middleware('auth:api');
```

Replace the contents of `app/Mcp/Servers/PatYourSelfServer.php`:

```php
<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('PatYourSelf')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
PatYourSelf is a habit-coaching app. A "loop" (intention) models a habit as a
cue -> craving -> response -> reward chain, worked via versioned strategies:
each strategy version intervenes at one point of that chain, and failures
(with the user's stated reason) drive a revision to a new version — history is
never rewritten. Concrete to-dos are "actions"; logging an outcome
(completed / failed / skipped) is the core daily interaction, and a failure
must carry the user's reason.

Use list-loops / get-loop to see what the user is working on, today-actions to
see what is due, log-action-outcome to check things off, and loop-progress for
streaks and completion rates. Always ask the user for their reason before
logging a failed outcome.
TEXT)]
class PatYourSelfServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/McpEndpointTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): PatYourSelf MCP server behind Passport OAuth at /mcp"
```

---

### Task 3: ListLoopsTool

**Files:**
- Create: `app/Mcp/Tools/ListLoopsTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (register tool)
- Test: `tests/Feature/Mcp/ListLoopsToolTest.php`

**Interfaces:**
- Consumes: `PatYourSelfServer::actingAs($user)->tool(...)` test pattern from Task 2.
- Produces: MCP tool `list-loops` — optional `status` arg (`active|paused|archived|completed|all`, default `active`); JSON array of `{id, title, type, status, active_strategy_version}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/ListLoopsToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\ListLoopsTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListLoopsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_loops_with_their_strategy_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'title' => 'Meditate every morning',
            'status' => Intention::STATUS_ACTIVE,
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(ListLoopsTool::class);

        $response->assertOk()
            ->assertSee('Meditate every morning')
            ->assertSee('"active_strategy_version":2');
    }

    public function test_excludes_paused_loops_by_default(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'Paused loop',
            'status' => Intention::STATUS_PAUSED,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(ListLoopsTool::class)
            ->assertOk()
            ->assertDontSee('Paused loop');
    }

    public function test_status_all_returns_every_loop(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'Paused loop',
            'status' => Intention::STATUS_PAUSED,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(ListLoopsTool::class, ['status' => 'all'])
            ->assertOk()
            ->assertSee('Paused loop');
    }

    public function test_never_lists_another_users_loops(): void
    {
        Intention::factory()->create(['title' => 'Someone elses loop']);

        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(ListLoopsTool::class)
            ->assertOk()
            ->assertDontSee('Someone elses loop');
    }

    public function test_rejects_an_unknown_status(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(ListLoopsTool::class, ['status' => 'exploded'])
            ->assertHasErrors();
    }
}
```

Note: if `assertSee('"active_strategy_version":2')` fails on escaping, check the raw payload with `$response->dump()` and match the actual encoding (e.g. spaces after colons); keep the assertion on the exact serialized fragment.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/ListLoopsToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\ListLoopsTool" not found`.

- [ ] **Step 3: Implement the tool**

```bash
php artisan make:mcp-tool ListLoopsTool --no-interaction
```

Replace contents of `app/Mcp/Tools/ListLoopsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the user\'s habit loops (intentions), newest first. Defaults to active loops; pass status "all" to include paused, archived and completed loops.')]
class ListLoopsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([...Intention::STATUSES, 'all'])],
        ]);

        $status = $validated['status'] ?? Intention::STATUS_ACTIVE;

        $loops = $request->user()->intentions()
            ->with('activeStrategy')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        return Response::json($loops->map(fn (Intention $loop): array => [
            'id' => $loop->id,
            'title' => $loop->title,
            'type' => $loop->type,
            'status' => $loop->status,
            'active_strategy_version' => $loop->activeStrategy?->version,
        ])->values()->all());
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum([...Intention::STATUSES, 'all'])
                ->description('Filter by loop status. Omit for active loops only.'),
        ];
    }
}
```

Register in `app/Mcp/Servers/PatYourSelfServer.php`:

```php
use App\Mcp\Tools\ListLoopsTool;

    protected array $tools = [
        ListLoopsTool::class,
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/ListLoopsToolTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): list-loops tool"
```

---

### Task 4: GetLoopTool

**Files:**
- Create: `app/Mcp/Tools/GetLoopTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (register tool)
- Test: `tests/Feature/Mcp/GetLoopToolTest.php`

**Interfaces:**
- Consumes: patterns from Tasks 2–3.
- Produces: MCP tool `get-loop` — required `intention_id` (integer); JSON object `{id, title, description, type, status, loop: {cue, craving, response, reward}, active_strategy_version, strategies: [{version, status, intervention_point, approach, rationale, change_reason, superseded_reason}]}`. Uniform `Not found.` error for unknown/foreign IDs.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/GetLoopToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\GetLoopTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetLoopToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_loop_with_its_strategy_timeline(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'title' => 'Read before bed',
            'cue' => 'After brushing teeth',
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'approach' => 'Book on the pillow',
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'approach' => 'Phone charges outside the bedroom',
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(GetLoopTool::class, ['intention_id' => $loop->id])
            ->assertOk()
            ->assertSee('Read before bed')
            ->assertSee('After brushing teeth')
            ->assertSee('Book on the pillow')
            ->assertSee('Phone charges outside the bedroom');
    }

    public function test_rejects_an_unknown_loop(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class, ['intention_id' => 999999])
            ->assertHasErrors(['Not found.']);
    }

    public function test_rejects_another_users_loop_identically(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class)
            ->assertHasErrors();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/GetLoopToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\GetLoopTool" not found`.

- [ ] **Step 3: Implement the tool**

```bash
php artisan make:mcp-tool GetLoopTool --no-interaction
```

Replace contents of `app/Mcp/Tools/GetLoopTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get one habit loop in full: the cue -> craving -> response -> reward chain plus the versioned strategy timeline, including why each version was superseded.')]
class GetLoopTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
        ]);

        $loop = $request->user()->intentions()
            ->with('activeStrategy')
            ->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $strategies = $loop->strategies()->orderedByVersion()->get();

        return Response::json([
            'id' => $loop->id,
            'title' => $loop->title,
            'description' => $loop->description,
            'type' => $loop->type,
            'status' => $loop->status,
            'loop' => [
                'cue' => $loop->cue,
                'craving' => $loop->craving,
                'response' => $loop->response,
                'reward' => $loop->reward,
            ],
            'active_strategy_version' => $loop->activeStrategy?->version,
            'strategies' => $strategies->map(fn (Strategy $strategy): array => [
                'version' => $strategy->version,
                'status' => $strategy->status,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'rationale' => $strategy->rationale,
                'change_reason' => $strategy->change_reason,
                'superseded_reason' => $strategy->superseded_reason,
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intention_id' => $schema->integer()
                ->description('The loop id, as returned by list-loops.')
                ->required(),
        ];
    }
}
```

Register `GetLoopTool::class` in `PatYourSelfServer::$tools` (after `ListLoopsTool::class`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/GetLoopToolTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): get-loop tool with strategy timeline"
```

---

### Task 5: TodayActionsTool

**Files:**
- Create: `app/Mcp/Tools/TodayActionsTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (register tool)
- Test: `tests/Feature/Mcp/TodayActionsToolTest.php`

**Interfaces:**
- Consumes: patterns from Tasks 2–3. Domain facts: `Action::OPEN_STATUSES` = pending|active; the TriggerEngine flips due pending actions to `active`; `scheduled_for` may be null (anchored/cue-based actions); `User.timezone` is nullable.
- Produces: MCP tool `today-actions` — no args; JSON array of `{id, loop_id, loop_title, title, description, status, due, scheduled_for, recurrence}` where `due` is `"due_now"` for fired (active) actions and `"upcoming"` for pending ones. Only open actions on **active** loops, scheduled today-or-earlier in the user's timezone, or unscheduled (anchored).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/TodayActionsToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\TodayActionsTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayActionsToolTest extends TestCase
{
    use RefreshDatabase;

    private function loopFor(User $user, string $status = Intention::STATUS_ACTIVE): Intention
    {
        return Intention::factory()->for($user)->create(['status' => $status]);
    }

    public function test_lists_fired_and_due_today_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopFor($user);

        Action::factory()->for($loop)->create([
            'title' => 'Fired earlier today',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHours(2),
        ]);
        Action::factory()->for($loop)->create([
            'title' => 'Later today',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->endOfDay()->subMinutes(10),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('Fired earlier today')
            ->assertSee('due_now')
            ->assertSee('Later today')
            ->assertSee('upcoming');
    }

    public function test_includes_unscheduled_anchored_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Anchored to brushing teeth',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('Anchored to brushing teeth');
    }

    public function test_excludes_tomorrows_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Tomorrow only',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->addDay()->startOfDay()->addHours(9),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Tomorrow only');
    }

    public function test_excludes_actions_on_paused_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user, Intention::STATUS_PAUSED))->create([
            'title' => 'Paused loop action',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHour(),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Paused loop action');
    }

    public function test_excludes_other_users_actions(): void
    {
        $foreignLoop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($foreignLoop)->create([
            'title' => 'Not yours',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHour(),
        ]);

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Not yours');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/TodayActionsToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\TodayActionsTool" not found`.

- [ ] **Step 3: Implement the tool**

```bash
php artisan make:mcp-tool TodayActionsTool --no-interaction
```

Replace contents of `app/Mcp/Tools/TodayActionsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Action;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the actions the user should act on today: fired ("due_now"), scheduled later today ("upcoming"), plus unscheduled cue-anchored ones. Only actions on active loops.')]
class TodayActionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');
        $endOfToday = Date::now($timezone)->endOfDay()->utc();

        $actions = Action::query()
            ->whereIn('status', Action::OPEN_STATUSES)
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $endOfToday))
            ->with('intention:id,title')
            ->orderBy('scheduled_for')
            ->get();

        return Response::json($actions->map(fn (Action $action): array => [
            'id' => $action->id,
            'loop_id' => $action->intention_id,
            'loop_title' => $action->intention->title,
            'title' => $action->title,
            'description' => $action->description,
            'status' => $action->status,
            'due' => $action->status === Action::STATUS_ACTIVE ? 'due_now' : 'upcoming',
            'scheduled_for' => $action->scheduled_for?->timezone($timezone)->toIso8601String(),
            'recurrence' => $action->recurrence,
        ])->values()->all());
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
```

Register `TodayActionsTool::class` in `PatYourSelfServer::$tools`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/TodayActionsToolTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): today-actions tool"
```

---

### Task 6: LogActionOutcomeTool (the only write)

**Files:**
- Create: `app/Mcp/Tools/LogActionOutcomeTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (register tool)
- Test: `tests/Feature/Mcp/LogActionOutcomeToolTest.php`

**Interfaces:**
- Consumes: `App\Actions\LogAction::handle(User $user, Action $action, array $data): ActionLog` — the shared write path (recurrence roll-forward, cue-answered marking, `ActionLogged` event). Validation mirrors `App\Http\Requests\LogActionRequest`. Ownership rule mirrors `ActionPolicy::log` (`$action->intention->user_id === $user->id`), enforced structurally via the `whereHas` scope.
- Produces: MCP tool `log-action-outcome` — args `action_id` (int, required), `outcome` (`completed|failed|skipped`, required), `reason` (string, required iff failed, max 2000); JSON `{log_id, outcome, reason, logged_at, action_status}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/LogActionOutcomeToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LogActionOutcomeTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogActionOutcomeToolTest extends TestCase
{
    use RefreshDatabase;

    private function oneOffAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'scheduled_for' => null,
            ]);
    }

    public function test_logs_a_completion_and_closes_the_one_off_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertOk()
            ->assertSee(ActionLog::OUTCOME_COMPLETED);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_a_failure_requires_a_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
            ])
            ->assertHasErrors();

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }

    public function test_logs_a_failure_with_its_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Friends came over unexpectedly',
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Friends came over unexpectedly',
        ]);
    }

    public function test_completing_a_recurring_action_rolls_it_forward(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => 'daily',
                'scheduled_for' => now()->subMinutes(5),
            ]);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertOk();

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
    }

    public function test_cannot_log_another_users_action(): void
    {
        $action = $this->oneOffAction(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => 'exploded',
            ])
            ->assertHasErrors();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/LogActionOutcomeToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\LogActionOutcomeTool" not found`.

- [ ] **Step 3: Implement the tool**

```bash
php artisan make:mcp-tool LogActionOutcomeTool --no-interaction
```

Replace contents of `app/Mcp/Tools/LogActionOutcomeTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Record the outcome of an action: completed, failed, or skipped. A failed outcome MUST include the user\'s stated reason — ask them why before calling this. Recurring actions automatically roll forward to their next occurrence.')]
class LogActionOutcomeTool extends Tool
{
    /**
     * The one write this server performs; it goes through the shared LogAction
     * so every invariant (immutable log, recurrence roll-forward, cue-answered
     * marking) holds — the same path the web and mobile API use.
     */
    public function handle(Request $request, LogAction $log): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
            'outcome' => ['required', 'string', Rule::in(ActionLog::OUTCOMES)],
            'reason' => [
                Rule::requiredIf(fn () => $request->get('outcome') === ActionLog::OUTCOME_FAILED),
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $action = Action::query()
            ->whereKey($validated['action_id'])
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id))
            ->first();

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        $entry = $log->handle($request->user(), $action, Arr::only($validated, ['outcome', 'reason']));

        return Response::json([
            'log_id' => $entry->id,
            'outcome' => $entry->outcome,
            'reason' => $entry->reason,
            'logged_at' => $entry->logged_at?->toIso8601String(),
            'action_status' => $action->fresh()->status,
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_id' => $schema->integer()
                ->description('The action id, as returned by today-actions.')
                ->required(),
            'outcome' => $schema->string()
                ->enum(ActionLog::OUTCOMES)
                ->description('completed, failed, or skipped.')
                ->required(),
            'reason' => $schema->string()
                ->description('The user\'s stated reason. Required when the outcome is failed.'),
        ];
    }
}
```

Register `LogActionOutcomeTool::class` in `PatYourSelfServer::$tools`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/LogActionOutcomeToolTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): log-action-outcome tool through shared LogAction"
```

---

### Task 7: LoopProgressTool

**Files:**
- Create: `app/Mcp/Tools/LoopProgressTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (register tool)
- Test: `tests/Feature/Mcp/LoopProgressToolTest.php`

**Interfaces:**
- Consumes: `App\Services\Progress\LoopProgress::forLoop(Intention $loop): array` — returns `{streak: {outcome, length}, completion_rate, totals: {completed, failed, skipped}, recent, last_logged_at}`; caller must eager-load `activeStrategy` and `actionLogs`.
- Produces: MCP tool `loop-progress` — required `intention_id` (int); JSON `{loop_id, title, ...LoopProgress fields}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/LoopProgressToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LoopProgressTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopProgressToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_totals_and_completion_rate(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['title' => 'Meditate']);
        $action = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_COMPLETED,
            'recurrence' => null,
            'scheduled_for' => null,
        ]);

        ActionLog::factory()->count(3)->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Too tired',
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(LoopProgressTool::class, ['intention_id' => $loop->id])
            ->assertOk()
            ->assertSee('Meditate')
            ->assertSee('"completed":3')
            ->assertSee('"failed":1');
    }

    public function test_rejects_another_users_loop(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class)
            ->assertHasErrors();
    }
}
```

Note: if `ActionLog::factory()->for($action)->for($user)` trips over factory defaults, check `database/factories/ActionLogFactory.php` for required attributes and set them explicitly. The JSON-fragment assertions (`"completed":3`) may need the same encoding check as Task 3.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/LoopProgressToolTest.php`
Expected: FAIL — `Class "App\Mcp\Tools\LoopProgressTool" not found`.

- [ ] **Step 3: Implement the tool**

```bash
php artisan make:mcp-tool LoopProgressTool --no-interaction
```

Replace contents of `app/Mcp/Tools/LoopProgressTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Progress for one habit loop: current streak on the active strategy, lifetime completion rate and totals, and the recent outcome strip.')]
class LoopProgressTool extends Tool
{
    public function handle(Request $request, LoopProgress $progress): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
        ]);

        $loop = $request->user()->intentions()
            ->with(['activeStrategy', 'actionLogs'])
            ->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        return Response::json([
            'loop_id' => $loop->id,
            'title' => $loop->title,
            ...$progress->forLoop($loop),
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intention_id' => $schema->integer()
                ->description('The loop id, as returned by list-loops.')
                ->required(),
        ];
    }
}
```

Register `LoopProgressTool::class` in `PatYourSelfServer::$tools`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/LoopProgressToolTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): loop-progress tool"
```

---

### Task 8: Full verification, tools/list end-to-end, ship

**Files:**
- Modify: `tests/Feature/Mcp/McpEndpointTest.php` (add tools/list test)

**Interfaces:**
- Consumes: everything above. Final `PatYourSelfServer::$tools` order: `ListLoopsTool`, `GetLoopTool`, `TodayActionsTool`, `LogActionOutcomeTool`, `LoopProgressTool`.
- Produces: shippable branch.

- [ ] **Step 1: Add an end-to-end tools/list test**

Add to `tests/Feature/Mcp/McpEndpointTest.php`:

```php
    public function test_advertises_all_five_tools_over_http(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);

        $response->assertOk();

        foreach (['list-loops', 'get-loop', 'today-actions', 'log-action-outcome', 'loop-progress'] as $tool) {
            $this->assertStringContainsString($tool, $response->getContent());
        }
    }
```

Note: `tools/list` may require an initialized session depending on the transport implementation. If this returns an error about initialization, check how `vendor/laravel/mcp` tests drive the web transport (look at `vendor/laravel/mcp/tests`) and mirror that (e.g. send `initialize` first and reuse the returned `Mcp-Session-Id` header).

- [ ] **Step 2: Run the new test**

Run: `php artisan test --compact --filter=test_advertises_all_five_tools_over_http`
Expected: PASS. If tool names serialize differently (e.g. `list_loops`), update the expected names to the actual serialization — then also update the server `#[Instructions]` text to match.

- [ ] **Step 3: Run the entire suite**

Run: `php artisan test --compact`
Expected: PASS — zero failures across the whole suite.

- [ ] **Step 4: Inspector smoke test (manual, best-effort)**

```bash
php artisan route:list --path=mcp
php artisan route:list --path=.well-known --except-vendor=0
```

Expected: `POST /mcp`, discovery routes, `POST oauth/register` all present.

- [ ] **Step 5: Format, commit, push, draft PR**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(mcp): advertise all five tools; full-suite verification"
git push -u origin worktree-mcp-server
gh pr create --draft --title "MCP server: claude.ai connector (Passport OAuth + 5 tools)" --body "$(cat <<'EOF'
Implements docs/superpowers/specs/2026-07-06-mcp-server-design.md.

- laravel/mcp server at POST /mcp behind a new Passport `auth:api` guard (Sanctum untouched)
- Mcp::oauthRoutes(): OAuth 2.1 discovery + dynamic client registration for claude.ai custom connectors
- Tools: list-loops, get-loop, today-actions, log-action-outcome (sole write, via shared LogAction), loop-progress
- Uniform "Not found." for unknown/foreign ids; reason required on failed outcomes
- Feature tests per tool incl. cross-user denial and recurring roll-forward

Deploy steps (Forge) are listed at the bottom of docs/superpowers/plans/2026-07-06-mcp-server.md.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Post-merge deployment checklist (Forge — manual, not part of this plan)

1. Deploy branch; `composer install` runs on the server.
2. `php artisan migrate --force` (Passport tables).
3. `php artisan passport:keys --force` (or set `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars).
4. Confirm `https://<domain>/.well-known/oauth-authorization-server` returns metadata.
5. In claude.ai → Settings → Connectors → Add custom connector → URL `https://<domain>/mcp`.
6. Complete the OAuth approval screen; ask Claude "what loops am I working on?" to verify.
7. If the connector gets a permanent 403 after authorization, the client didn't request the
   `mcp:use` scope (now enforced by `CheckToken` on `/mcp`) — set
   `Passport::defaultScopes(['mcp:use'])` in `AppServiceProvider::boot()` as the fallback.
