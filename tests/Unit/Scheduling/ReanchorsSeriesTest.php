<?php

namespace Tests\Unit\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\ReanchorsSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReanchorsSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_unlogged_future_occurrences_and_keeps_logged_ones(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            // schedule_kind lives in metadata, not a column — see ActionFactory.
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(3),
        ]);

        $logged = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $logged->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'completed',
            'logged_at' => now()->subDay(),
        ]);
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDays(2)]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London');

        $this->assertDatabaseHas('occurrences', ['id' => $logged->id]);
        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_it_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'anchored'],
            'recurrence' => null,
            'series_started_at' => null,
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London');

        $this->assertNull($action->refresh()->series_started_at);
    }
}
