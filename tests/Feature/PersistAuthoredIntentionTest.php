<?php

namespace Tests\Feature;

use App\Actions\PersistAuthoredIntention;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PersistAuthoredIntentionTest extends TestCase
{
    use RefreshDatabase;

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
        $authored = AuthoredIntention::fromStructured($this->validPayload(), 'test-model', 'test@1');
        $user = User::factory()->create();

        $intention = app(PersistAuthoredIntention::class)->handle($user, $authored);

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
        $this->assertSame('test@1', $intention->activeStrategy->metadata['prompt_version']);

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
        $authored = AuthoredIntention::fromStructured($payload, 'test-model', 'test@1');
        $user = User::factory()->create();

        $intention = app(PersistAuthoredIntention::class)->handle($user, $authored);

        $this->assertSame(0, $intention->strategies()->count());
    }

    public function test_the_dto_guard_rejects_an_incomplete_payload(): void
    {
        // Missing required fields → fromStructured throws before a
        // PersistAuthoredIntention DTO can even be built.
        $this->expectException(CoachException::class);

        AuthoredIntention::fromStructured(['title' => 'Only a title'], 'test-model', 'test@1');
    }

    public function test_persists_a_scheduled_action_bound_to_the_strategy(): void
    {
        $authored = AuthoredIntention::fromStructured($this->validPayload(), 'test-model', 'test@1');
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(PersistAuthoredIntention::class)->handle($user, $authored);

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
        $authored = AuthoredIntention::fromStructured($payload, 'test-model', 'test@1');
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(PersistAuthoredIntention::class)->handle($user, $authored);

        $action = $intention->actions()->first();
        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->recurrence);
        $this->assertSame('after morning coffee', $action->metadata['anchor']);
    }

    public function test_transaction_rolls_back_when_action_persistence_fails(): void
    {
        $authored = AuthoredIntention::fromStructured($this->validPayload(), 'test-model', 'test@1');
        $user = User::factory()->create(['timezone' => 'UTC']);

        // Force a failure partway through the transaction, after the Intention
        // and Strategy rows would already have been created, to prove
        // PersistAuthoredIntention's DB::transaction wrapper leaves no
        // orphaned rows behind.
        Action::creating(function (): void {
            throw new RuntimeException('boom');
        });

        try {
            try {
                app(PersistAuthoredIntention::class)->handle($user, $authored);
                $this->fail('Expected RuntimeException to be thrown.');
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame(0, Intention::count());
            $this->assertSame(0, Strategy::count());
        } finally {
            Action::flushEventListeners();
        }
    }

    public function test_persists_the_requested_status(): void
    {
        $user = User::factory()->create();

        $intention = app(PersistAuthoredIntention::class)->handle(
            $user,
            $this->authored(),
            Intention::STATUS_PAUSED,
        );

        $this->assertSame(Intention::STATUS_PAUSED, $intention->status);
    }

    public function test_defaults_to_active_when_no_status_is_given(): void
    {
        $user = User::factory()->create();

        $intention = app(PersistAuthoredIntention::class)->handle($user, $this->authored());

        $this->assertSame(Intention::STATUS_ACTIVE, $intention->status);
    }
}
