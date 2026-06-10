# Task 8: Coach Orchestrator + Conversational Chat Endpoint — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-rolled `ChatCoach` JSON-envelope approach with a durable, tool-calling Coach orchestrator agent backed by the Laravel AI SDK's `RemembersConversations` trait, so chat turns are stored server-side and loop authoring happens through the `CreateLoop` tool instead of an inline JSON envelope.

**Architecture:** `POST /chat` → `ChatController::store` → `RespondToChat` action → `Coach` SDK agent (with `RemembersConversations`) → `CreateLoop` / `ListLoops` / `GetLoopDetail` / `GetLatestSummary` tools. The `TurnCollector` service bridges tool-side-effects (persisted Intention IDs) back to the HTTP response. `ChatController::home` hydrates the thread from `agent_conversation_messages` for page reload persistence.

**Tech Stack:** Laravel 13.12 / PHP 8.4, `laravel/ai ^0.7.2`, `Promptable` trait, `RemembersConversations` trait, `DatabaseConversationStore`, PHPUnit feature tests.

---

## Key Verified Facts (from codebase inspection)

These must be treated as ground truth during implementation.

### SDK Conversation API
- `continueLastConversation($user)` calls `ConversationStore::latestConversationId($user->id)` which returns `?string`. For a fresh user (no conversations), it returns `null`, setting `$this->conversationId = null`. When `currentConversation()` is null, `RememberConversation` middleware creates a new conversation. **A fresh user is handled correctly — no special-case branch required.**
- `RememberConversation` middleware runs automatically for any agent that uses `RemembersConversations` AND has a conversation participant (set via `forUser()` or `continueLastConversation()`). It is added by `GeneratesText::gatherMiddlewareFor()` — you do NOT need to list it in `Coach::middleware()`.
- Turns **DO persist under fakes** — `RememberConversation` runs in addition to the fake recording middleware. Title generation uses the real provider, but the `catch (Throwable)` block in `generateTitle()` swallows connection errors and falls back to `Str::limit($prompt, 100)`. Tests pass without mocking the title-generation HTTP call.

### ConversationMessage column names
From the migration: `role` (string, 25) and `content` (text). From `DatabaseConversationStore::storeUserMessage`: `role = 'user'`, `content = $prompt->prompt`. From `storeAssistantMessage`: `role = 'assistant'`, `content = $response->text`.

### Tool wire names
`ToolNameResolver::resolve($tool)` returns `$tool->name()` if callable, otherwise `class_basename($tool)`. The tools have no `name()` method, so wire names are: `CreateLoop`, `ListLoops`, `GetLoopDetail`, `GetLatestSummary`.

### Fake API for text agents
`Coach::fake(['plain text reply'])` — array of plain strings for text agents. Closure fakes: `Coach::fake(function ($prompt) { return 'reply'; })` — closure receives the prompt string (extra params ignored by PHP). Both forms are supported by `FakeTextGateway::marshalResponse`.

### What to delete in this task
Only delete files where ALL consumers are also being deleted in this task:
- `app/Services/Coach/Chat/ChatCoach.php` — only consumed by `RespondToChat` (being rewritten) and `ChatCoachTest.php` (being deleted).
- `app/Services/Coach/Chat/ChatReplySchema.php` — only consumed by `ChatCoach.php` and `CoachPrompts::chat()` (being pruned).
- `tests/Unit/Coach/ChatCoachTest.php` — tests the deleted `ChatCoach`.
- `tests/Unit/Coach/IntentionAuthorTest.php` — tests `App\Services\Coach\Authoring\IntentionAuthor` (the old service). **Keep the old service itself** — `AuthorIntention` action, `AuthorIntentionTest`, and `PromptVersioningTest` still depend on it. Deleting it would break those tests which are NOT in scope for this task.
- **Do NOT delete** `app/Services/Coach/Authoring/IntentionAuthor.php` (old service) — `AuthorIntention` action depends on it; deleting it breaks `AuthorIntentionTest` and `PromptVersioningTest`.
- **Do NOT delete** `app/Services/Coach/Authoring/IntentionSchema.php` — used by `StoreIntentionRequest`, `UpdateIntentionRequest`, `AuthoredIntention`.

### `ChatException` after deletions
`ChatException` is still referenced in `bootstrap/app.php` (exception renderer). After deleting `ChatCoach.php`, `ChatException` itself is no longer thrown from any production path. But the renderer block in `bootstrap/app.php` is benign (it handles an exception that can no longer be raised). The task spec says "keep `ChatException` only if still referenced" — it IS still referenced in `bootstrap/app.php`. Leave both in place for now; they become dead code only after the renderer block is removed (a future task concern).

### `CoachPrompts::chat()` after deletions
After deleting `ChatCoach.php`, `CoachPrompts::chat()` becomes unreferenced. Prune the method and its `ChatReplySchema` import from `CoachPrompts.php`. The `CoachPromptsTest` does NOT test `chat()` (already removed from `allSystems()`), so this is safe.

---

## File Map

### Files to Create
- `app/Ai/Agents/Coach.php` — The orchestrator agent.
- `tests/Feature/Ai/CoachConversationTest.php` — Tests for conversation persistence and thread hydration.

### Files to Modify
- `app/Actions/RespondToChat.php` — Rewrite to use `Coach` SDK agent.
- `app/Http/Controllers/ChatController.php` — Slim `store`, add `thread` prop to `home`, add `recentThread` helper.
- `app/Http/Requests/ChatRequest.php` — Remove `history` rules and `history()` helper.
- `tests/Feature/ChatEndpointTest.php` — Rework to use `Coach::fake()`.
- `app/Services/Coach/Prompts/CoachPrompts.php` — Remove dead `chat()` method and `ChatReplySchema` import.

### Files to Delete
- `app/Services/Coach/Chat/ChatCoach.php`
- `app/Services/Coach/Chat/ChatReplySchema.php`
- `tests/Unit/Coach/ChatCoachTest.php`
- `tests/Unit/Coach/IntentionAuthorTest.php`

---

## Task 1: Create `app/Ai/Agents/Coach.php`

**Files:**
- Create: `app/Ai/Agents/Coach.php`

- [ ] **Step 1: Write the Coach agent**

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
 *
 * Tool wire names (class_basename, no name() override):
 *   CreateLoop, ListLoops, GetLoopDetail, GetLatestSummary
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.6)]
#[MaxTokens(1024)]
#[MaxSteps(6)]
class Coach implements Agent, Conversational, HasTools, HasMiddleware
{
    use Promptable, RemembersConversations;

    public const PROMPT_VERSION = 'chat@1';

    public function instructions(): string
    {
        return <<<'TXT'
        You are PatYourSelf's habit coach. You talk with the user about their
        habits and apply two methods procedurally:

        Atomic Habits — every habit runs a loop: cue -> craving -> response ->
        reward. To build a habit, make the cue obvious, the craving attractive,
        the response easy, and the reward satisfying; to break one, invert each.
        Behaviour changes by editing the loop, not by willpower.

        CBT — thoughts, feelings, and behaviour are linked. Treat each attempt as
        a behavioural experiment. When the user states why something failed, take
        that reason at face value and adjust the plan — never moralise or blame.
        Prefer small, graded steps the user can actually complete, and intervene
        at the single point in the loop most likely to move the behaviour.

        Stay concrete and specific to what the user actually said. No platitudes.

        Task: talk with the user about their habits on their daily coaching screen.
        Reply conversationally — warm, concrete, and brief (2-4 sentences).

        Tools available to you:
        - ListLoops: list the user's habit loops with current strategies. Call
          this when the user asks about their habits or when context would help.
        - GetLoopDetail: get one loop's full anatomy, strategy, and recent logs.
          Call when the user refers to a specific loop and you need more detail.
        - GetLatestSummary: get the latest pattern summary for one loop. Call
          when the user wants to understand how they are doing on a habit.
        - CreateLoop: create a habit loop from a goal the user described. Call
          when the user describes a habit they want to build or break and you
          have enough to act on. After the tool confirms, tell the user what you
          built and that it appears as a card below.

        Guidelines:
        - Answer conversationally in plain text (no JSON, no Markdown fences).
        - Ground answers in the user's real data via the read tools; never invent
          loops or data.
        - When the user describes a habit to build or break, use CreateLoop; do
          not create a loop twice for the same request.
        - If you need more information before creating a loop, ask ONE clarifying
          question.
        TXT;
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

- [ ] **Step 2: Verify the file parses cleanly**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php -l app/Ai/Agents/Coach.php
```

Expected output: `No syntax errors detected in app/Ai/Agents/Coach.php`

---

## Task 2: Rewrite `app/Actions/RespondToChat.php`

**Files:**
- Modify: `app/Actions/RespondToChat.php`

- [ ] **Step 1: Rewrite the action**

Replace the entire file content with:

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
 * coach reads and writes through tools; this action just runs the turn.
 *
 * continueLastConversation() resolves the user's latest conversation ID from
 * the store (null for a fresh user). A null ID causes RemembersConversations
 * to start from an empty history; RememberConversation middleware then creates
 * the first conversation row after the turn completes.
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

- [ ] **Step 2: Verify the file parses cleanly**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php -l app/Actions/RespondToChat.php
```

Expected: `No syntax errors detected`

---

## Task 3: Slim `ChatRequest`

**Files:**
- Modify: `app/Http/Requests/ChatRequest.php`

- [ ] **Step 1: Remove `history` rules and `history()` helper**

Replace the entire file content with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chat turn. History is now stored server-side in the durable
 * conversation; only the current message is sent by the client.
 */
class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind the auth middleware; any signed-in user may chat.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 2: Verify the file parses cleanly**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php -l app/Http/Requests/ChatRequest.php
```

Expected: `No syntax errors detected`

---

## Task 4: Update `ChatController`

**Files:**
- Modify: `app/Http/Controllers/ChatController.php`

- [ ] **Step 1: Slim `store()`, add `thread` to `home()`, add `recentThread()` private helper**

Replace the entire file content with:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\RespondToChat;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\IntentionResource;
use App\Services\Coach\Chat\ChatResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The chat home screen: renders the daily-driver thread, and the endpoint that
 * runs a user message through the coach and returns the reply plus any
 * structured action cards (LLM-authored Intention loops) to render inline.
 *
 * The thread is hydrated from the server-side durable conversation so it
 * survives page reloads without the client managing history.
 */
class ChatController extends Controller
{
    public function home(Request $request): Response
    {
        $user = $request->user();

        $intentions = $user->intentions()
            ->active()
            ->with(['activeStrategy', 'activeAction'])
            ->latest()
            ->get();

        return Inertia::render('coach', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
            'thread' => $this->recentThread($user),
        ]);
    }

    public function store(ChatRequest $request, RespondToChat $respond): JsonResponse
    {
        $result = $respond->handle(
            $request->user(),
            $request->validated('message'),
        );

        return response()->json([
            'message' => $result->message,
            'cards' => $this->cards($result),
        ]);
    }

    /**
     * @return list<array{type: string, intention: array<string, mixed>}>
     */
    private function cards(ChatResult $result): array
    {
        if ($result->intention === null) {
            return [];
        }

        $result->intention->loadMissing(['activeStrategy', 'activeAction']);

        return [[
            'type' => 'intention',
            'intention' => (new IntentionResource($result->intention))->resolve(),
        ]];
    }

    /**
     * The user's recent coach conversation, oldest first, mapped to the
     * frontend's ChatMessage shape so the thread survives reloads.
     *
     * Column names from the agent_conversation_messages migration:
     *   role (string 25) — 'user' or 'assistant'
     *   content (text) — the message text
     *
     * @return list<array{id: string, role: string, text: string}>
     */
    private function recentThread($user): array
    {
        $conversation = $user->conversations()
            ->latest('updated_at')
            ->first();

        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn (ConversationMessage $m): array => [
                'id' => 'h'.$m->id,
                'role' => $m->role === 'user' ? 'user' : 'coach',
                'text' => (string) $m->content,
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 2: Verify the file parses cleanly**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php -l app/Http/Controllers/ChatController.php
```

Expected: `No syntax errors detected`

---

## Task 5: Prune `CoachPrompts::chat()` (dead method after `ChatCoach` deletion)

**Files:**
- Modify: `app/Services/Coach/Prompts/CoachPrompts.php`

- [ ] **Step 1: Remove the `chat()` method, `chatFraming()` private helper, and `ChatReplySchema` import**

The remaining `CoachPrompts` file keeps only `intentionAuthoring()`, `rollingSummary()`, `charter()`, and the private helpers they use. Remove:
- `use App\Services\Coach\Chat\ChatReplySchema;` (line 6)
- `public static function chat(): CoachPrompt { ... }` (lines 51-58)
- `private static function chatFraming(): string { ... }` (lines 103-112)

The file should look like:

```php
<?php

namespace App\Services\Coach\Prompts;

use App\Services\Coach\Authoring\IntentionSchema;

/**
 * The single home for the coach's system prompts. Each is versioned and built
 * on one shared charter — the CBT + Atomic Habits framing the coach applies
 * procedurally — so the voice and method stay consistent across Intention
 * authoring, strategy revision, and summary generation.
 *
 * Each prompt = the shared charter + a purpose-specific framing + the JSON
 * output contract, which the matching schema owns (kept beside its validation
 * rules so prompt and enforcement can't drift).
 *
 * Note: the chat() prompt was removed in Task 8 when the Coach orchestrator
 * took over conversation management via the SDK agent pattern.
 */
final class CoachPrompts
{
    /** Bump when the shared charter wording changes. */
    public const CHARTER_VERSION = '1';

    public static function intentionAuthoring(): CoachPrompt
    {
        return new CoachPrompt(
            'intention-authoring',
            'intention-authoring@1',
            self::compose(self::authoringFraming(), IntentionSchema::contract()),
        );
    }

    public static function rollingSummary(): CoachPrompt
    {
        $contract = <<<'PROMPT'
        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "content":  string,    // the updated rolling summary, a few sentences
          "patterns": string[]   // short behavioural patterns; [] if none yet
        }
        PROMPT;

        return new CoachPrompt(
            'rolling-summary',
            'rolling-summary@1',
            self::compose(self::summaryFraming(), $contract),
        );
    }

    /**
     * The shared CBT + Atomic Habits charter every prompt is built on.
     */
    public static function charter(): string
    {
        return <<<'TXT'
        You are PatYourSelf's habit coach. You author structured data, not chat,
        and you apply two methods procedurally:

        Atomic Habits — every habit runs a loop: cue -> craving -> response ->
        reward. To build a habit, make the cue obvious, the craving attractive,
        the response easy, and the reward satisfying; to break one, invert each.
        Behaviour changes by editing the loop, not by willpower.

        CBT — thoughts, feelings, and behaviour are linked. Treat each attempt as
        a behavioural experiment. When the user states why something failed, take
        that reason at face value and adjust the plan — never moralise or blame.
        Prefer small, graded steps the user can actually complete, and intervene
        at the single point in the loop most likely to move the behaviour.

        Stay concrete and specific to what the user actually said. No platitudes.
        TXT;
    }

    private static function compose(string $framing, string $contract): string
    {
        return self::charter()."\n\n".$framing."\n\n".$contract;
    }

    private static function authoringFraming(): string
    {
        return <<<'TXT'
        Task: the user describes a habit they want to build or break. Author a
        single structured Intention — a habit loop modelled on the cue -> craving
        -> response -> reward chain — plus an initial strategy to try first.

        For a "break" loop, cue/craving/response/reward describe the UNWANTED loop
        as it happens today, and the strategy is how to disrupt it. Choose the
        single intervention_point most likely to move the behaviour.
        TXT;
    }

    private static function summaryFraming(): string
    {
        return <<<'TXT'
        Task: maintain a rolling summary of a single habit loop for lightweight
        behavioural pattern detection — no machine learning, just a running text
        summary distilled from the structured event archive.

        You are given the loop, its current strategy, the prior rolling summary
        (if any), and the new completion / failure / skip events since then. Fold
        them into ONE updated, concise summary and surface the behavioural
        patterns you can see — when and why the user tends to succeed or fail
        (e.g. "fails on late workdays", "succeeds when the cue is visual"). Keep
        it grounded strictly in the events provided; do not invent history.
        TXT;
    }
}
```

- [ ] **Step 2: Verify the file parses cleanly**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php -l app/Services/Coach/Prompts/CoachPrompts.php
```

Expected: `No syntax errors detected`

---

## Task 6: Rework `tests/Feature/ChatEndpointTest.php`

**Files:**
- Modify: `tests/Feature/ChatEndpointTest.php`

The old tests used `FakeCoachService`. The new tests use `Coach::fake()`.

**Design notes:**
- `Coach::fake(['plain text reply'])` — text agent takes plain strings.
- "authors a card" test: `RespondToChat::handle` calls `$this->collector->flush()` THEN `->prompt(...)`. A closure fake runs synchronously inside `prompt()`. So the sequence is: flush → prompt (closure runs, adds intention id to collector) → read collector. This works correctly.
- The closure fake receives `($prompt, $attachments, $provider, $model)` per `FakeTextGateway::marshalResponse` — in PHP, receiving fewer params in the closure is fine.

- [ ] **Step 1: Write the reworked test file**

Replace the entire file content with:

```php
<?php

namespace Tests\Feature;

use App\Ai\Agents\Coach;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_chat(): void
    {
        $this->postJson('/chat', ['message' => 'hi'])->assertUnauthorized();
    }

    public function test_message_is_required(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/chat', [])->assertStatus(422);
    }

    public function test_returns_a_reply_with_no_cards(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Coach::fake(['How can I help with your habits?']);

        $this->postJson('/chat', ['message' => 'hello'])
            ->assertOk()
            ->assertJson([
                'message' => 'How can I help with your habits?',
                'cards' => [],
            ]);

        $this->assertSame(0, Intention::count());
    }

    public function test_authors_a_card_when_the_tool_side_effect_is_present(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // CreateLoop persists the Intention and registers its id in the
        // TurnCollector. Under a fake, tools don't execute, so we simulate the
        // side-effect by having the closure fake register the id before returning.
        $intention = Intention::factory()
            ->for($user)
            ->has(Strategy::factory()->initial(), 'strategies')
            ->create(['title' => 'Read before bed']);

        Coach::fake(function () use ($intention): string {
            app(TurnCollector::class)->addIntention($intention->id);

            return "Built you a loop for reading before bed.";
        });

        $response = $this->postJson('/chat', ['message' => 'I want to read more before bed']);

        $response->assertOk()
            ->assertJsonPath('message', 'Built you a loop for reading before bed.')
            ->assertJsonPath('cards.0.type', 'intention')
            ->assertJsonPath('cards.0.intention.id', $intention->id);
    }
}
```

- [ ] **Step 2: Run only the ChatEndpointTest to verify it passes (the suite will be fully green later)**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php artisan test tests/Feature/ChatEndpointTest.php 2>&1 | tail -10
```

Expected: 3 tests pass. (The Coach agent and RespondToChat must be created first — this step runs after Tasks 1-4.)

---

## Task 7: Write `tests/Feature/Ai/CoachConversationTest.php`

**Files:**
- Create: `tests/Feature/Ai/CoachConversationTest.php`

**Design notes for persistence test:**
- `RememberConversation` middleware runs even under fakes (confirmed from `GeneratesText::gatherMiddlewareFor` — it checks `RemembersConversations` trait + `hasConversationParticipant()`, both satisfied when `forUser($user)->continueLastConversation($user)` is called).
- After two `postJson('/chat')` turns, `$user->conversations()->count()` should be 1 and the conversation's `messages()->count()` should be 4 (2 user + 2 assistant).
- For `recentThread` test: after one turn, GET /dashboard → assert `thread` has 2 items, first is user, second is coach.
- `$this->withoutVite()` is needed if the Vite manifest is not built — use `setUp()` to set it globally for the class.

- [ ] **Step 1: Write the test file**

```php
<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Coach;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_turns_persist_into_one_durable_conversation_per_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Coach::fake(['First reply', 'Second reply']);

        $this->postJson('/chat', ['message' => 'hello']);
        $this->postJson('/chat', ['message' => 'and then?']);

        $this->assertSame(1, $user->conversations()->count());

        $conversation = $user->conversations()->first();
        $this->assertSame(4, $conversation->messages()->count(),
            'Expected 2 user + 2 assistant messages (4 total)');
    }

    public function test_dashboard_hydrates_the_thread_from_the_stored_conversation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Coach::fake(['Reply one']);

        $this->postJson('/chat', ['message' => 'hello']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coach')
                ->has('thread', 2)
                ->where('thread.0.role', 'user')
                ->where('thread.0.text', 'hello')
                ->where('thread.1.role', 'coach')
            );
    }
}
```

- [ ] **Step 2: Run the conversation tests**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php artisan test tests/Feature/Ai/CoachConversationTest.php 2>&1 | tail -15
```

Expected: 2 tests pass.

If the persistence test fails because `$user->conversations()->count() === 0` (turns not persisting under fakes), see **Fallback Plan** below and replace with direct-model coverage.

**Fallback Plan (if persistence under fakes does not work):**

Replace `test_turns_persist_into_one_durable_conversation_per_user` with:

```php
public function test_dashboard_hydrates_thread_from_stored_conversation_messages(): void
{
    // NOTE: Coach::fake() bypasses RememberConversation middleware — conversation
    // rows are not created during faked turns. This test exercises recentThread()
    // directly by creating Conversation+message rows via the SDK models.
    $user = User::factory()->create();
    $this->actingAs($user);

    $conversation = $user->conversations()->create([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'title' => 'Test conversation',
    ]);

    $conversation->messages()->create([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'user_id' => $user->id,
        'agent' => \App\Ai\Agents\Coach::class,
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
    ]);
    $conversation->messages()->create([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'user_id' => $user->id,
        'agent' => \App\Ai\Agents\Coach::class,
        'role' => 'assistant',
        'content' => 'Reply one',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
    ]);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('coach')
            ->has('thread', 2)
            ->where('thread.0.role', 'user')
            ->where('thread.0.text', 'hello')
            ->where('thread.1.role', 'coach')
        );
}
```

Include a comment in the test file documenting whether persistence works under fakes.

---

## Task 8: Delete dead files

**Files to delete:**
- `app/Services/Coach/Chat/ChatCoach.php`
- `app/Services/Coach/Chat/ChatReplySchema.php`
- `tests/Unit/Coach/ChatCoachTest.php`
- `tests/Unit/Coach/IntentionAuthorTest.php`

- [ ] **Step 1: Git-remove the four files**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && git rm app/Services/Coach/Chat/ChatCoach.php app/Services/Coach/Chat/ChatReplySchema.php tests/Unit/Coach/ChatCoachTest.php tests/Unit/Coach/IntentionAuthorTest.php
```

Expected output: four `rm` lines.

- [ ] **Step 2: Verify no stray references remain**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && grep -rn "ChatCoach\|ChatReplySchema" app tests bootstrap config 2>/dev/null | grep -v "\.git"
```

Expected: empty output (or only the `CoachPrompts.php` reference, but that was already removed in Task 5).

---

## Task 9: Full suite + Pint + Commit

- [ ] **Step 1: Run full test suite**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php artisan test 2>&1 | tail -10
```

Expected: all tests pass. The suite count changes from 218 to 218 - 4 (ChatCoachTest had 5 tests, IntentionAuthorTest had 8 tests) + 3 (new ChatEndpointTest) + 2 (CoachConversationTest) = **211 tests** approximately, depending on exact counts. The exact count is less important than 0 failures.

If any test fails, diagnose and fix before proceeding.

- [ ] **Step 2: Run Pint on dirty files**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && ./vendor/bin/pint --dirty
```

Expected: Pint exits 0 (no changes, or fixes applied). If Pint makes changes, re-run tests to confirm still passing.

- [ ] **Step 3: Run tests again after Pint**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && php artisan test 2>&1 | tail -5
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
cd "/Users/hectormoya/Documents/Dev Projects/Laravel/patyourself/.claude/worktrees/task-8-intention-authoring" && git add app/Ai/Agents/Coach.php app/Actions/RespondToChat.php app/Http/Controllers/ChatController.php app/Http/Requests/ChatRequest.php app/Services/Coach/Prompts/CoachPrompts.php tests/Feature/ChatEndpointTest.php tests/Feature/Ai/CoachConversationTest.php && git commit -m "$(cat <<'EOF'
feat: Coach orchestrator on SDK conversations replaces ChatCoach

Chat turns now run through the Coach agent inside the user's durable
server-side conversation. Loop authoring happens through the CreateLoop tool
(IntentionAuthor specialist) instead of an inline JSON envelope; read tools
ground answers in the user's real data. POST /chat takes just {message} and
the dashboard hydrates the thread from the stored conversation.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] `app/Ai/Agents/Coach.php` created with `RemembersConversations`, `HasTools`, `HasMiddleware`, `Conversational`
- [x] Tool instructions match wire names (`CreateLoop`, `ListLoops`, `GetLoopDetail`, `GetLatestSummary`)
- [x] `Coach::PROMPT_VERSION = 'chat@1'` (matches the chat versioning from `CoachPrompts::chat()`)
- [x] `RespondToChat` rewritten to use Coach + TurnCollector
- [x] `continueLastConversation` behavior confirmed safe for fresh users
- [x] `ChatRequest` drops `history` rules
- [x] `ChatController::store` drops history param, keeps cards builder
- [x] `ChatController::home` adds `thread` prop
- [x] `recentThread()` private helper: latest conversation, newest 50 messages, oldest-first, maps `role`/`content` columns
- [x] ChatEndpointTest reworked with `Coach::fake()`
- [x] "authors a card" test uses closure fake to simulate tool side-effect
- [x] "invalid card is dropped" test deleted (envelope concept gone)
- [x] `CoachConversationTest` covers persistence + thread hydration
- [x] `CoachPrompts::chat()` and `chatFraming()` removed (dead code)
- [x] `ChatCoach.php`, `ChatReplySchema.php`, `ChatCoachTest.php`, `IntentionAuthorTest.php` deleted
- [x] `IntentionAuthor.php` (old service) and `IntentionSchema.php` NOT deleted (still needed)
- [x] `ChatException` NOT deleted (still referenced in `bootstrap/app.php`)
- [x] `AuthoredIntention::fromResponse` NOT deleted (still used by old `IntentionAuthor` service)

**Type consistency:**
- `ChatResult` stays at `App\Services\Coach\Chat\ChatResult` — `RespondToChat` imports it correctly
- `ConversationMessage` from `Laravel\Ai\Models\ConversationMessage` — columns `role` and `content` confirmed from migration
- `TurnCollector` from `App\Ai\TurnCollector` — `addIntention(int $id)` signature confirmed

**Potential issues:**
1. `Inertia\Testing\AssertableInertia` import — confirm the package is `inertiajs/inertia-laravel` and the class exists at that path. If not found, use `use Inertia\Testing\AssertableInertia as Assert`.
2. The closure fake in `test_authors_a_card_when_the_tool_side_effect_is_present` calls `app(TurnCollector::class)` — the TurnCollector is scoped (not singleton). The closure runs in the same request lifecycle as the controller, so `app(TurnCollector::class)` resolves the same scoped instance. Verify this in `AppServiceProvider` or equivalent binding.
