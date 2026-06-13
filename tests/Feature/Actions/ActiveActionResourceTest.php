<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
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
}
