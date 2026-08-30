<?php

namespace Tests\Feature\Experiments;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartExperimentWebTest extends TestCase
{
    use RefreshDatabase;

    private function loopWithActiveVersion(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
        ]);

        return $loop->refresh();
    }

    public function test_the_owner_starts_the_next_experiment(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving out loud before opening the app.',
                'rationale' => 'The cue is unavoidable, so the cue is the wrong place to intervene.',
                'supersedes_reason' => 'Removing the cue did not survive contact with a working day.',
                'review_after_days' => 14,
                'cadence' => 'keep',
            ])
            ->assertRedirect();

        $loop->refresh();
        $this->assertSame(2, $loop->activeStrategy->version);
        $this->assertSame(Strategy::POINT_CRAVING, $loop->activeStrategy->intervention_point);
        $this->assertSame(14, $loop->activeStrategy->plannedDays());
    }

    public function test_the_approach_rationale_and_supersedes_reason_are_stored_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);
        $approach = '  Name the craving out loud.   Before opening the app.  ';
        $rationale = '  The cue is unavoidable.  ';
        $supersedesReason = '  Removing the cue did not survive contact with a working day.  ';

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => $approach,
            'rationale' => $rationale,
            'supersedes_reason' => $supersedesReason,
            'cadence' => 'keep',
        ]);

        $strategy = $loop->refresh()->activeStrategy;
        $this->assertSame($approach, $strategy->approach);
        $this->assertSame($rationale, $strategy->rationale);
        $this->assertSame($supersedesReason, $strategy->parent->superseded_reason);
    }

    public function test_an_invalid_intervention_point_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => 'willpower',
                'approach' => 'Try harder.',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('intervention_point');

        $this->assertSame(1, $loop->refresh()->activeStrategy->version);
    }

    public function test_an_empty_approach_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_REWARD,
                'approach' => '',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('approach');
    }

    public function test_a_negative_review_window_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_REWARD,
                'approach' => 'Log the reward you actually got.',
                'review_after_days' => -1,
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('review_after_days');
    }

    public function test_a_zero_review_window_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_REWARD,
                'approach' => 'Log the reward you actually got.',
                'review_after_days' => 0,
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('review_after_days');

        // review_at = now() would make isUnderReview() true immediately, and
        // the notebook would ask for a verdict on an experiment that just started.
        $this->assertSame(1, $loop->refresh()->activeStrategy->version);
    }

    public function test_an_omitted_review_window_leaves_the_experiment_open_ended(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_REWARD,
            'approach' => 'Log the reward you actually got.',
            'cadence' => 'keep',
        ]);

        $this->assertNull($loop->refresh()->activeStrategy->plannedDays());
    }

    public function test_keeping_the_cadence_inherits_the_previous_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);
        Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'title' => 'Weigh in',
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving first.',
            'cadence' => 'keep',
        ]);

        $action = $loop->refresh()->activeAction;
        $this->assertSame('daily', $action->recurrence);
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_changing_the_cadence_re_proposes_the_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);
        Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving first.',
            'cadence' => 'change',
            'action_title' => 'Say it out loud',
            'action_kind' => 'clock',
            'action_time' => '21:30',
            'action_recurrence' => 'weekdays',
        ]);

        $action = $loop->refresh()->activeAction;
        $this->assertSame('Say it out loud', $action->title);
        $this->assertSame('weekdays', $action->recurrence);
    }

    public function test_a_clock_cadence_without_a_time_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'change',
                'action_title' => 'Say it out loud',
                'action_kind' => 'clock',
            ])
            ->assertSessionHasErrors('action_time');
    }

    public function test_superseding_a_worked_version_records_stacked_on_success(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);
        $loop->activeStrategy->update(['verdict' => Strategy::VERDICT_WORKED]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Keep going with what worked, one step further.',
            'cadence' => 'keep',
        ]);

        $this->assertSame(
            Strategy::REASON_STACKED_ON_SUCCESS,
            $loop->refresh()->activeStrategy->change_reason,
        );
    }

    public function test_superseding_an_unconcluded_version_records_restrategized_on_failure(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving first.',
            'cadence' => 'keep',
        ]);

        // We have no record of success here, so guessing stacked_on_success
        // would be a permanent, unrecoverable falsehood — history is append-only.
        $this->assertSame(
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            $loop->refresh()->activeStrategy->change_reason,
        );
    }

    public function test_a_stranger_cannot_start_an_experiment(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion(User::factory()->create());

        $this->actingAs($stranger)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'keep',
            ])
            ->assertForbidden();
    }

    public function test_a_loop_with_no_active_version_reports_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving first.',
                'cadence' => 'keep',
            ])
            ->assertSessionHasErrors('intervention_point');
    }
}
