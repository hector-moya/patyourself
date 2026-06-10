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
        // side-effect: the closure fake registers the id before returning.
        // RespondToChat calls flush() BEFORE prompt(), so the collector is clear
        // before the closure runs — this correctly mirrors the real tool path.
        $intention = Intention::factory()
            ->for($user)
            ->has(Strategy::factory()->initial(), 'strategies')
            ->create(['title' => 'Read before bed']);

        Coach::fake(function () use ($intention): string {
            app(TurnCollector::class)->addIntention($intention->id);

            return 'Built you a loop for reading before bed.';
        });

        $response = $this->postJson('/chat', ['message' => 'I want to read more before bed']);

        $response->assertOk()
            ->assertJsonPath('message', 'Built you a loop for reading before bed.')
            ->assertJsonPath('cards.0.type', 'intention')
            ->assertJsonPath('cards.0.intention.id', $intention->id);
    }
}
