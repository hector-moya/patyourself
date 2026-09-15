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
        Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]); // another user's active loop

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
        // Pin timestamps: OutcomeStreak orders by logged_at DESC, so the failed
        // log must be newest for the leading streak outcome to be "failed".
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

    /**
     * A loop that carries two versions, the first with a poor record and the
     * second with a good one.
     *
     * @return array{0: Intention, 1: Strategy, 2: Strategy}
     */
    private function loopWithTwoVersions(User $user): array
    {
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
            'title' => 'Eating to 80%',
        ]);

        $first = Strategy::factory()->initial()->superseded()->for($loop)->create(['version' => 1]);
        $second = Strategy::factory()->restrategized()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        // v1: 1 held of 4 decided — 25%.
        $firstAction = Action::factory()->for($loop)->for($first)->create();
        ActionLog::factory()->for($firstAction)->completed()->create();
        ActionLog::factory()->for($firstAction)->failed()->count(3)->create();

        // v2: 3 held of 4 decided — 75%.
        $secondAction = Action::factory()->for($loop)->for($second)->create();
        ActionLog::factory()->for($secondAction)->completed()->count(3)->create();
        ActionLog::factory()->for($secondAction)->failed()->create();

        return [$loop, $first, $second];
    }

    /**
     * The card is about the version that is running, not the loop's lifetime.
     *
     * This is the whole point of the screen. Lifetime figures here would be
     * 4 held of 8 — 50% — which folds v1's record into the number v1 is then
     * compared against, and makes a revision look worse than it is for as long
     * as the version it replaced stays in the denominator.
     *
     * Named killing mutation: swap `forCurrentVersion()` for `forLoop()` in
     * ProgressController::card(). The rate below becomes 50 and this fails.
     */
    public function test_a_card_reports_the_running_version_not_the_whole_loop(): void
    {
        $user = User::factory()->create();
        $this->loopWithTwoVersions($user);

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.version', 2)
                ->where('loops.0.completion_rate', 75)
                ->where('loops.0.totals.completed', 3)
                ->where('loops.0.totals.failed', 1)
            );
    }

    public function test_a_card_carries_the_version_it_replaced_and_the_rate_it_held(): void
    {
        $user = User::factory()->create();
        $this->loopWithTwoVersions($user);

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.previous_version.version', 1)
                ->where('loops.0.previous_version.rate', 25)
            );
    }

    public function test_a_first_version_has_nothing_to_compare_against(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        ActionLog::factory()->for($action)->completed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loops.0.previous_version', null));
    }

    /**
     * A version replaced before anything was logged against it decided nothing,
     * so it cannot be compared against. The comparison skips back to the last
     * version that did produce a decision rather than reporting 0%, which would
     * make every revision look like an improvement.
     */
    public function test_the_comparison_skips_a_version_that_never_decided_anything(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $first = Strategy::factory()->initial()->superseded()->for($loop)->create(['version' => 1]);
        Strategy::factory()->superseded()->for($loop)->create(['version' => 2]);
        $third = Strategy::factory()->for($loop)->create([
            'version' => 3,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $firstAction = Action::factory()->for($loop)->for($first)->create();
        ActionLog::factory()->for($firstAction)->completed()->create();
        ActionLog::factory()->for($firstAction)->failed()->create();

        // v2 ran and was replaced without a single outcome. v3 is live.
        $thirdAction = Action::factory()->for($loop)->for($third)->create();
        ActionLog::factory()->for($thirdAction)->completed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.previous_version.version', 1)
                ->where('loops.0.previous_version.rate', 50)
            );
    }

    /**
     * A loop between experiments still has a record. It reports the lifetime
     * one, names no version, and offers no comparison — there is no running
     * version for a comparison to be about.
     */
    public function test_a_loop_with_no_running_version_falls_back_to_its_lifetime_record(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $retired = Strategy::factory()->initial()->for($loop)->create([
            'status' => Strategy::STATUS_RETIRED,
        ]);
        $action = Action::factory()->for($loop)->for($retired)->create();
        ActionLog::factory()->for($action)->completed()->count(3)->create();
        ActionLog::factory()->for($action)->failed()->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.version', null)
                ->where('loops.0.day_of_experiment', null)
                ->where('loops.0.previous_version', null)
                ->where('loops.0.completion_rate', 75)
            );
    }

    public function test_the_screen_summarises_the_whole_record(): void
    {
        $user = User::factory()->create();
        $this->loopWithTwoVersions($user);

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // v2's figures only — 3 held of 4 decided.
                ->where('summary.held', 3)
                ->where('summary.decided', 4)
                ->where('summary.versions_running', 1)
                ->where('summary.versions_ahead', 1)
            );
    }

    /**
     * "Ahead of what they replaced" is a claim about the record, so it is
     * counted rather than assumed. A revision that did worse than the version
     * it replaced must not be counted as ahead — that is the finding the
     * experiment was run to get.
     */
    public function test_a_version_doing_worse_than_its_predecessor_is_not_counted_as_ahead(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $first = Strategy::factory()->initial()->superseded()->for($loop)->create(['version' => 1]);
        $second = Strategy::factory()->restrategized()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        // v1 held 3 of 4; v2 holds 1 of 4. The revision made it worse.
        $firstAction = Action::factory()->for($loop)->for($first)->create();
        ActionLog::factory()->for($firstAction)->completed()->count(3)->create();
        ActionLog::factory()->for($firstAction)->failed()->create();

        $secondAction = Action::factory()->for($loop)->for($second)->create();
        ActionLog::factory()->for($secondAction)->completed()->create();
        ActionLog::factory()->for($secondAction)->failed()->count(3)->create();

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loops.0.completion_rate', 25)
                ->where('loops.0.previous_version.rate', 75)
                ->where('summary.versions_running', 1)
                ->where('summary.versions_ahead', 0)
            );
    }

    /**
     * A loop with nothing decided has neither held nor failed anything.
     * Folding its zero into the totals would drag the headline down for the
     * crime of being new.
     */
    public function test_a_loop_with_nothing_decided_is_left_out_of_the_summary(): void
    {
        $user = User::factory()->create();
        $recording = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($recording)->create();
        $action = Action::factory()->for($recording)->for($strategy)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();

        // Brand new, nothing logged.
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get('/progress')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('loops', 2)
                ->where('summary.held', 2)
                ->where('summary.decided', 2)
            );
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/progress')->assertRedirect('/login');
    }

    public function test_the_progress_index_carries_no_usage_prop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('progress'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('progress/index')->missing('usage'));
    }
}
