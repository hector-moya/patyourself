<?php

namespace Tests\Feature\Console;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FireDueActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fires_due_occurrences(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subMinute(),
            'recurrence' => 'daily',
        ]);

        $this->artisan('actions:fire')->assertSuccessful();

        $this->assertNotNull($action->occurrences()->first()->fired_at);
    }
}
