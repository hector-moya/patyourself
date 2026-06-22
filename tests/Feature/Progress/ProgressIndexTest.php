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

class ProgressIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lists_only_the_users_active_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);
        Intention::factory()->for($user)->completed()->create();
        Intention::factory()->create(); // another user's active loop

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/index')
                ->has('loops', 2)
            );
    }

    public function test_card_carries_computed_metrics(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE, 'title' => 'Morning walk']);
        $strategy = Strategy::factory()->initial()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create(['logged_at' => now()->subDay()]);
        ActionLog::factory()->for($action)->failed()->create(['logged_at' => now()]);

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.title', 'Morning walk')
                ->where('loops.0.completion_rate', 67)
                ->where('loops.0.totals.completed', 2)
                ->where('loops.0.totals.failed', 1)
                ->where('loops.0.streak.outcome', 'failed')
            );
    }

    public function test_summary_excerpt_is_the_trimmed_first_line(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => "First line of the summary.\nSecond line that is hidden.",
        ]);

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.summary_excerpt', 'First line of the summary.')
            );
    }

    public function test_loop_without_logs_reports_null_rate_and_empty_recent(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.completion_rate', null)
                ->where('loops.0.recent', [])
                ->where('loops.0.summary_excerpt', null)
            );
    }

    public function test_renders_with_no_active_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('progress/index')->has('loops', 0));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/progress')->assertRedirect('/login');
    }
}
