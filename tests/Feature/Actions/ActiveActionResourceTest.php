<?php

namespace Tests\Feature\Actions;

use App\Http\Resources\IntentionResource;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveActionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_show_embeds_the_action_schedule(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => now()->addDay(),
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->actingAs($user)
            ->getJson("/api/intentions/{$intention->id}")
            ->assertOk()
            ->assertJsonPath('data.active_action.recurrence', 'daily')
            ->assertJsonPath('data.active_action.schedule_kind', 'clock');
    }

    public function test_next_occurrence_at_is_the_earliest_unlogged_slot_from_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 09:00:00')]);
        $next = Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertTrue($next->scheduled_for->equalTo($action->nextOccurrenceAt()));
    }

    public function test_next_occurrence_at_is_null_when_nothing_is_left_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 09:00:00')]);

        // The grid stops at the end of today, so "nothing left" is the honest
        // answer — reaching into tomorrow would invent a row that does not exist.
        $this->assertNull($action->nextOccurrenceAt());
    }

    public function test_the_resource_exposes_next_occurrence_at(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => 'daily',
        ]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $payload = (new IntentionResource($loop->load('activeAction')))->toArray(request());

        $this->assertArrayNotHasKey('scheduled_for', $payload['active_action']);
        $this->assertNotNull($payload['active_action']['next_occurrence_at']);
    }
}
