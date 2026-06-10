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
 * - Title generation (generateTitle) makes an HTTP call to the real provider
 *   but wraps the call in catch (Throwable), so a missing API key in tests
 *   falls back silently to Str::limit($prompt, 100). Conversations persist.
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
        $user = User::factory()->create();
        $this->actingAs($user);

        Coach::fake(['First reply', 'Second reply']);

        $this->postJson('/chat', ['message' => 'hello'])->assertOk();
        $this->postJson('/chat', ['message' => 'and then?'])->assertOk();

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
