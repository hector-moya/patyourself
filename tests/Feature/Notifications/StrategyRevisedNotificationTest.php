<?php

namespace Tests\Feature\Notifications;

use App\Models\Intention;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyRevisedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(): Strategy
    {
        $intention = Intention::factory()->create(['title' => 'Morning run']);

        return Strategy::factory()->stacked()->for($intention)->create([
            'approach' => 'Run 20 minutes after coffee.',
        ]);
    }

    public function test_via_uses_the_database_channel(): void
    {
        $notification = new StrategyRevisedNotification($this->strategy());

        $this->assertSame(['database'], $notification->via(new \stdClass));
    }

    public function test_to_array_payload_describes_the_revision(): void
    {
        $strategy = $this->strategy();

        $payload = (new StrategyRevisedNotification($strategy))->toArray(new \stdClass);

        $this->assertSame('strategy_revised', $payload['type']);
        $this->assertSame($strategy->intention_id, $payload['intention_id']);
        $this->assertSame($strategy->id, $payload['strategy_id']);
        $this->assertSame(Strategy::REASON_STACKED_ON_SUCCESS, $payload['change_reason']);
        $this->assertSame('Morning run', $payload['title']);
        $this->assertSame('Run 20 minutes after coffee.', $payload['approach']);
    }
}
