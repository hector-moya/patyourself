<?php

namespace Tests\Feature\Console;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FireDueActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fires_due_actions(): void
    {
        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => 'daily',
        ]);

        $this->artisan('actions:fire')->assertSuccessful();

        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }
}
