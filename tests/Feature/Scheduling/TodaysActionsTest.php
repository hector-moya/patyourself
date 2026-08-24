<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\TodaysActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodaysActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_returns_actions_due_by_the_end_of_the_users_local_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $today = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => Carbon::parse('2026-08-24 21:30:00'),
        ]);

        Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => Carbon::parse('2026-08-26 09:00:00'),
        ]);

        $actions = app(TodaysActions::class)->for($user);

        $this->assertSame([$today->id], $actions->pluck('id')->all());
    }

    public function test_includes_unscheduled_cue_anchored_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $anchored = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertTrue(app(TodaysActions::class)->for($user)->contains($anchored));
    }

    public function test_excludes_actions_on_paused_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $paused = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        Action::factory()->for($paused)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertCount(0, app(TodaysActions::class)->for($user));
    }

    public function test_never_returns_another_users_actions(): void
    {
        $stranger = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($stranger)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertCount(0, app(TodaysActions::class)->for(User::factory()->create()));
    }
}
