<?php

namespace Tests\Feature\Notifications;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function firedAction(): Action
    {
        $intention = Intention::factory()
            ->for(User::factory())
            ->create(['title' => 'Meditate daily', 'status' => Intention::STATUS_ACTIVE]);

        return Action::factory()->for($intention)->create([
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['fired_at' => '2026-06-15T07:00:00+00:00'],
        ]);
    }

    public function test_it_uses_only_the_database_channel(): void
    {
        $notification = new ActionDueNotification($this->firedAction());

        $this->assertSame(['database'], $notification->via(new User));
    }

    public function test_to_array_carries_the_inbox_payload(): void
    {
        $action = $this->firedAction();

        $payload = (new ActionDueNotification($action))->toArray(new User);

        $this->assertSame([
            'action_id' => $action->id,
            'intention_id' => $action->intention_id,
            'title' => 'Meditate daily',
            'fired_at' => '2026-06-15T07:00:00+00:00',
        ], $payload);
    }
}
