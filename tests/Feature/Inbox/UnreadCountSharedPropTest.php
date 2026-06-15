<?php

namespace Tests\Feature\Inbox;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnreadCountSharedPropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notifyUser(User $user): void
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($intention)->create(['status' => Action::STATUS_ACTIVE]);
        $user->notify(new ActionDueNotification($action));
    }

    public function test_it_shares_the_authenticated_users_unread_count(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 1));
    }

    public function test_reading_the_notification_drops_the_shared_count(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);
        $user->unreadNotifications->markAsRead();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 0));
    }

    public function test_a_guest_sees_a_zero_count(): void
    {
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 0));
    }
}
