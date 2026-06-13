<?php

namespace Tests\Feature;

use App\Actions\AuthorIntention;
use App\Ai\Agents\IntentionAuthor;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorIntentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Morning walk',
            'description' => 'A short walk to start the day with momentum.',
            'type' => 'build',
            'cue' => 'Coffee finishes brewing',
            'craving' => 'Feel awake and clear-headed',
            'response' => 'Take a 15-minute walk around the block',
            'reward' => 'Energy and a sense of momentum',
            'confidence' => 0.78,
            'tags' => ['energy', 'morning'],
            'strategy' => [
                'intervention_point' => 'cue',
                'approach' => 'Put walking shoes by the coffee machine the night before.',
                'rationale' => 'Pairing the new habit to an existing cue makes it automatic.',
            ],
            'action' => [
                'title' => 'Put walking shoes by the coffee machine',
                'description' => 'A visible cue the night before.',
                'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
            ],
        ];
    }

    public function test_persists_intention_and_initial_strategy(): void
    {
        IntentionAuthor::fake([$this->validPayload()]);
        $user = User::factory()->create();

        $intention = app(AuthorIntention::class)->handle($user, 'I want more energy in the mornings');

        $this->assertTrue($intention->exists);
        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame('Morning walk', $intention->title);
        $this->assertSame(Intention::TYPE_BUILD, $intention->type);
        $this->assertSame(Intention::STATUS_ACTIVE, $intention->status);
        $this->assertSame('Coffee finishes brewing', $intention->cue);

        // AI-authored extras land in metadata, attributed to the model.
        $this->assertNotEmpty($intention->metadata['authored_by']);
        $this->assertSame(0.78, $intention->metadata['confidence']);
        $this->assertSame(['energy', 'morning'], $intention->metadata['tags']);

        $strategy = $intention->activeStrategy;
        $this->assertNotNull($strategy);
        $this->assertSame(1, $strategy->version);
        $this->assertSame(Strategy::STATUS_ACTIVE, $strategy->status);
        $this->assertSame(Strategy::POINT_CUE, $strategy->intervention_point);
        $this->assertSame(Strategy::REASON_INITIAL, $strategy->change_reason);
        $this->assertNull($strategy->parent_strategy_id);
    }

    public function test_intention_without_strategy_creates_no_strategy(): void
    {
        $payload = $this->validPayload();
        unset($payload['strategy']);
        IntentionAuthor::fake([$payload]);
        $user = User::factory()->create();

        $intention = app(AuthorIntention::class)->handle($user, 'goal');

        $this->assertSame(0, $intention->strategies()->count());
    }

    public function test_invalid_schema_writes_nothing(): void
    {
        // Missing required fields → fromStructured throws CoachException.
        IntentionAuthor::fake([['title' => 'Only a title']]);
        $user = User::factory()->create();

        $this->expectException(CoachException::class);

        app(AuthorIntention::class)->handle($user, 'goal');

        $this->assertSame(0, Intention::count());
        $this->assertSame(0, Strategy::count());
    }

    public function test_authors_a_scheduled_action_bound_to_the_strategy(): void
    {
        IntentionAuthor::fake([$this->validPayload()]);
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(AuthorIntention::class)->handle($user, 'I want more energy');

        $action = $intention->actions()->first();
        $this->assertNotNull($action);
        $this->assertSame('Put walking shoes by the coffee machine', $action->title);
        $this->assertSame($intention->activeStrategy->id, $action->strategy_id);
        $this->assertSame(Action::STATUS_PENDING, $action->status);
        $this->assertSame('daily', $action->recurrence);
        $this->assertNotNull($action->scheduled_for);
        $this->assertSame('07:00', $action->scheduled_for->utc()->format('H:i'));
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_anchored_action_has_no_schedule(): void
    {
        $payload = $this->validPayload();
        $payload['action'] = [
            'title' => 'Do ten push-ups',
            'schedule' => ['kind' => 'anchored', 'anchor' => 'after morning coffee'],
        ];
        IntentionAuthor::fake([$payload]);
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(AuthorIntention::class)->handle($user, 'goal');

        $action = $intention->actions()->first();
        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->recurrence);
        $this->assertSame('after morning coffee', $action->metadata['anchor']);
    }
}
