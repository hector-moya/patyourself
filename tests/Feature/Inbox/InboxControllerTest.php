<?php

namespace Tests\Feature\Inbox;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notify(User $user, string $title = 'Meditate'): Action
    {
        $intention = Intention::factory()->for($user)->create([
            'title' => $title,
            'status' => Intention::STATUS_ACTIVE,
        ]);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['fired_at' => '2026-06-15T07:00:00+00:00'],
        ]);
        $user->notify(new ActionDueNotification($action));

        return $action;
    }

    public function test_index_lists_only_the_users_own_notifications(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify(User::factory()->create()); // another user's

        $this->actingAs($user)
            ->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox')
                ->has('notifications', 1)
                ->where('notifications.0.title', 'Meditate')
            );
    }

    public function test_index_lists_newest_first(): void
    {
        $user = User::factory()->create();
        $this->travelTo(now()->subMinute());
        $this->notify($user, 'Older');
        $this->travelBack();
        $this->notify($user, 'Newer');

        $this->actingAs($user)
            ->get('/inbox')
            ->assertInertia(fn (Assert $page) => $page->where('notifications.0.title', 'Newer'));
    }

    public function test_mark_read_marks_a_single_notification_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $id = $user->notifications()->first()->id;

        $this->actingAs($user)->patch("/inbox/{$id}/read")->assertRedirect();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_mark_read_404s_for_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $this->notify($owner);
        $id = $owner->notifications()->first()->id;

        $this->actingAs(User::factory()->create())
            ->patch("/inbox/{$id}/read")
            ->assertNotFound();

        $this->assertCount(1, $owner->fresh()->unreadNotifications);
    }

    public function test_mark_all_read_marks_every_notification_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);

        $this->actingAs($user)->patch('/inbox/read-all')->assertRedirect();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_guests_cannot_reach_the_inbox(): void
    {
        $this->get('/inbox')->assertRedirect();
        $this->patch('/inbox/read-all')->assertRedirect();
    }
}
