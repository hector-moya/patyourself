<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Coach;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Tests\TestCase;

/**
 * Verified SDK behaviour:
 * - RememberConversation middleware runs even under Coach::fake() because
 *   GeneratesText::gatherMiddlewareFor() adds it independently whenever the
 *   agent uses RemembersConversations AND has a conversation participant.
 * - Title generation is DISABLED via config('ai.conversations.generate_title')
 *   = false (see config/ai.php).  Without this flag the middleware would make
 *   a real provider HTTP call on every new conversation — outside the cost
 *   guard — and under Coach::fake() it would also consume the next queued fake
 *   response, corrupting turn-text accounting in multi-turn tests.
 */
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
        // With generate_title disabled (config/ai.php), each Coach::fake() response
        // maps 1-to-1 to a coach turn.  Turn 1 consumes 'First reply'; turn 2
        // consumes 'Second reply'.  No fake responses are silently eaten by title
        // generation.
        $user = User::factory()->create();
        $this->actingAs($user);

        Coach::fake(['First reply', 'Second reply']);

        $turn1 = $this->postJson('/chat', ['message' => 'hello'])->assertOk();
        $turn2 = $this->postJson('/chat', ['message' => 'and then?'])->assertOk();

        // Pin the reply texts so any fake-accounting regression is immediately
        // visible as a wrong response value, not a silent row-count mismatch.
        $turn1->assertJson(['message' => 'First reply']);
        $turn2->assertJson(['message' => 'Second reply']);

        $this->assertSame(
            1,
            $user->conversations()->count(),
            'Both turns must land in a single durable conversation.'
        );

        $conversation = $user->conversations()->first();
        $this->assertSame(
            4,
            $conversation->messages()->count(),
            'Expected 2 user messages + 2 assistant messages = 4 rows.'
        );
    }

    public function test_dashboard_hydrates_the_thread_from_the_stored_conversation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Coach::fake(['Reply one']);
        $this->postJson('/chat', ['message' => 'hello'])->assertOk();

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

    public function test_recentthread_maps_stored_messages_to_frontend_shape(): void
    {
        // This test verifies recentThread() directly by creating Conversation
        // and ConversationMessage rows via the SDK models, independent of
        // whether Coach::fake() triggers persistence.
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'Test',
        ]);

        ConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => Coach::class,
            'role' => 'user',
            'content' => 'What should I work on?',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);

        ConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => Coach::class,
            'role' => 'assistant',
            'content' => 'Let us start with your mornings.',
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
                ->where('thread.0.text', 'What should I work on?')
                ->where('thread.1.role', 'coach')
                ->where('thread.1.text', 'Let us start with your mornings.')
            );
    }

    public function test_recentthread_order_is_stable_when_messages_share_the_same_created_at(): void
    {
        // Regression guard for same-second created_at ties.
        //
        // The SDK persists user + assistant rows in a single turn; both can
        // share the same second-precision timestamp.  Ordering by created_at
        // alone produces undefined order; ordering by id (UUIDv7) is stable
        // because the id encodes time at millisecond precision.
        //
        // Strategy: give the USER message a *smaller* uuid7 id (earlier in
        // sort order) but insert the ASSISTANT message into the DB first,
        // and set IDENTICAL created_at on both.  A created_at-only sort
        // would return assistant first (DB insertion order when timestamps
        // tie).  An id-based sort must return user first.
        $user = User::factory()->create();
        $this->actingAs($user);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'title' => 'Order test',
        ]);

        // Both messages share the exact same created_at timestamp.
        $sameTimestamp = now()->setMicro(0);

        // User message gets the EARLIER (smaller) uuid7 id.
        $userMessageId = (string) Str::uuid7($sameTimestamp->clone()->subMilliseconds(1));
        // Assistant message gets the LATER (larger) uuid7 id.
        $assistantMessageId = (string) Str::uuid7($sameTimestamp);

        // Insert ASSISTANT first so DB row order would put it before user
        // when sorting purely by created_at with no tiebreaker.
        ConversationMessage::create([
            'id' => $assistantMessageId,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => Coach::class,
            'role' => 'assistant',
            'content' => 'Same-second coach reply',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $sameTimestamp,
            'updated_at' => $sameTimestamp,
        ]);

        // Insert USER second — its smaller uuid7 id means it sorts BEFORE the
        // assistant when ordering by id, despite being inserted later.
        ConversationMessage::create([
            'id' => $userMessageId,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => Coach::class,
            'role' => 'user',
            'content' => 'Same-second user message',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $sameTimestamp,
            'updated_at' => $sameTimestamp,
        ]);

        // id-based ordering must yield user → coach, not the DB insertion order.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coach')
                ->has('thread', 2)
                ->where('thread.0.role', 'user')
                ->where('thread.0.text', 'Same-second user message')
                ->where('thread.1.role', 'coach')
                ->where('thread.1.text', 'Same-second coach reply')
            );
    }

    public function test_fresh_user_gets_an_empty_thread(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coach')
                ->where('thread', [])
            );
    }
}
