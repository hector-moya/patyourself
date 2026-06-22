<?php

namespace Tests\Feature\Progress;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_sees_metrics_journey_and_narrative(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE, 'title' => 'Morning walk']);
        $v1 = Strategy::factory()->for($loop)->superseded('kept missing it')->create(['version' => 1]);
        $v2 = Strategy::factory()->for($loop)->restrategized()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'parent_strategy_id' => $v1->id,
        ]);
        $action = Action::factory()->for($loop)->for($v2)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => 'You complete most mornings.',
        ]);

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/show')
                ->where('intention.title', 'Morning walk')
                ->where('intention.completion_rate', 100)
                ->where('intention.streak.length', 2)
                ->has('strategies', 2)
                ->where('strategies.0.version', 1) // ordered oldest-first
                ->where('strategies.1.version', 2)
                ->where('summary', 'You complete most mornings.')
            );
    }

    public function test_summary_is_null_when_absent(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertInertia(fn (Assert $page) => $page->where('summary', null));
    }

    public function test_serves_a_non_active_owned_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)->get("/progress/{$loop->id}")->assertOk();
    }

    public function test_forbids_viewing_another_users_loop(): void
    {
        $owner = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs(User::factory()->create())
            ->get("/progress/{$loop->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);

        $this->get("/progress/{$loop->id}")->assertRedirect('/login');
    }
}
