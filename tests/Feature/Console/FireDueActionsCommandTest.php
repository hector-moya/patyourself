<?php

namespace Tests\Feature\Console;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FireDueActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture below is "due a minute ago, today". Off a live clock that
     * silently becomes "due a minute ago, *yesterday*" for the first minute of
     * every UTC day, and a stale occasion is deliberately not fired — so the
     * test would fail nightly for reasons that have nothing to do with the
     * command.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_fires_due_occurrences(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => Carbon::parse('2026-08-24 11:59:00'),
            'recurrence' => 'daily',
        ]);

        $this->artisan('actions:fire')->assertSuccessful();

        $this->assertNotNull($action->occurrences()->first()->fired_at);
    }
}
