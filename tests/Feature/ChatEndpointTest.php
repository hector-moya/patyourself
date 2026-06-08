<?php

namespace Tests\Feature;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\FakeCoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatEndpointTest extends TestCase
{
    use RefreshDatabase;

    private FakeCoachService $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = new FakeCoachService;
        $this->app->instance(CoachService::class, $this->coach);
    }

    /** @return array<string, mixed> */
    private function intentionPayload(): array
    {
        return [
            'title' => 'Read before bed',
            'description' => 'Swap scrolling for a few pages.',
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
        $this->actingAs(User::factory()->create());
        $this->coach->pushJson(['reply' => 'How can I help with your habits?']);

        $this->postJson('/chat', ['message' => 'hello'])
            ->assertOk()
            ->assertJson([
                'message' => 'How can I help with your habits?',
                'cards' => [],
            ]);

        $this->assertSame(0, Intention::count());
    }

    public function test_authors_persists_and_returns_an_intention_card(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->coach->pushJson([
            'reply' => "Let's build that.",
            'intention' => $this->intentionPayload(),
        ]);

        $response = $this->postJson('/chat', ['message' => 'I want to read more before bed']);

        $response->assertOk()
            ->assertJsonPath('message', "Let's build that.")
            ->assertJsonPath('cards.0.type', 'intention')
            ->assertJsonPath('cards.0.intention.title', 'Read before bed');

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'title' => 'Read before bed',
            'type' => Intention::TYPE_BUILD,
        ]);

        $intention = Intention::sole();
        $this->assertSame(1, $intention->strategies()->where('status', Strategy::STATUS_ACTIVE)->count());
    }

    public function test_invalid_intention_card_is_dropped_but_reply_returned(): void
    {
        $this->actingAs(User::factory()->create());
        $this->coach->pushJson([
            'reply' => 'Got it.',
            'intention' => ['title' => 'incomplete'],
        ]);

        $this->postJson('/chat', ['message' => 'something'])
            ->assertOk()
            ->assertJson(['message' => 'Got it.', 'cards' => []]);

        $this->assertSame(0, Intention::count());
    }
}
