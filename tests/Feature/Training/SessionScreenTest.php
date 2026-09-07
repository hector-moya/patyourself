<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Training\SessionScreen;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The recording screen's read model: an occasion's routine, in position
 * order, each exercise carrying the sets already recorded against it.
 *
 * Two claims beyond the shape itself. First, the tracker is additive — a
 * plain action with no routine at all must return empty rather than error,
 * because "an action with no template logs exactly as it does today" is the
 * whole reason this module was allowed to exist beside the plain loop.
 * Second, this has to be safe to call from a screen: it must cost the same
 * number of queries whether the routine has one exercise or several, never
 * one query per row.
 */
class SessionScreenTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action on a loop that records through the gym workflow.
     * `anchored()` pins both of ActionFactory's random fields so nothing here
     * depends on which values the factory would otherwise have rolled.
     */
    private function gymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    /**
     * Killing mutation: swap `->with('exercise')` for the column-limited
     * `->with('exercise:id,name')`. `instructions` is not among the named
     * columns, so it comes back null forever while the suite stays green —
     * exactly the trap this project has been bitten by three times. Verified
     * by direct mutation and rerun.
     */
    public function test_it_returns_the_routine_in_position_order_with_recorded_sets_attached(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);

        $squat = Exercise::factory()->create([
            'name' => 'Barbell Back Squat',
            'instructions' => ['Set up under the bar.', 'Stand and step back.'],
        ]);
        $row = Exercise::factory()->create(['name' => 'Barbell Row']);
        $press = Exercise::factory()->create(['name' => 'Overhead Press']);

        ActionExercise::factory()
            ->count(3)
            ->sequence(
                ['exercise_id' => $squat->id, 'target_sets' => 5, 'target_reps' => 5],
                ['exercise_id' => $row->id, 'target_sets' => 4, 'target_reps' => 8],
                ['exercise_id' => $press->id, 'target_sets' => 3, 'target_reps' => 10],
            )
            ->create(['action_id' => $action->id]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        PerformedSet::factory()->count(2)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
        ]);

        $screen = app(SessionScreen::class)->for($occurrence);

        $this->assertCount(3, $screen);

        // Position order, not insertion or id order.
        $this->assertSame($squat->id, $screen[0]['exercise']->id);
        $this->assertSame($row->id, $screen[1]['exercise']->id);
        $this->assertSame($press->id, $screen[2]['exercise']->id);

        $this->assertSame(5, $screen[0]['target_sets']);
        $this->assertSame(5, $screen[0]['target_reps']);

        // Proof the exercise is loaded in full, not column-limited.
        $this->assertSame(
            ['Set up under the bar.', 'Stand and step back.'],
            $screen[0]['exercise']->instructions,
        );

        $this->assertCount(2, $screen[0]['performed_sets']);
        $this->assertCount(0, $screen[1]['performed_sets']);
        $this->assertCount(0, $screen[2]['performed_sets']);
    }

    /**
     * "An action with no template logs exactly as it does today. The
     * tracker is additive." A plain action — no gym workflow at all — has no
     * routine to read, and this must not error looking for one.
     */
    public function test_an_action_with_no_routine_returns_empty(): void
    {
        $user = $this->user();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->anchored()
            ->create();

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $this->assertSame([], app(SessionScreen::class)->for($occurrence));
    }

    public function test_it_costs_the_same_number_of_queries_for_four_exercises_as_for_one(): void
    {
        $this->assertSame(
            $this->queriesBuildingSessionWith(1),
            $this->queriesBuildingSessionWith(4),
            'The session screen costs more per extra exercise — a relation is being lazy-loaded per row.',
        );
    }

    /** Queries issued building the session screen for a routine of `$exercises` rows. */
    private function queriesBuildingSessionWith(int $exercises): int
    {
        $user = $this->user();
        $action = $this->gymAction($user);

        $exerciseIds = Exercise::factory()->count($exercises)->create()->pluck('id')->all();

        ActionExercise::factory()
            ->count($exercises)
            ->sequence(fn (Sequence $sequence): array => ['exercise_id' => $exerciseIds[$sequence->index]])
            ->create(['action_id' => $action->id]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        foreach ($exerciseIds as $exerciseId) {
            PerformedSet::factory()->create([
                'occurrence_id' => $occurrence->id,
                'exercise_id' => $exerciseId,
            ]);
        }

        // The query log rather than DB::listen: a listener registered per call
        // would still be attached on the next one and double-count it.
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(SessionScreen::class)->for($occurrence);

        $count = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    }
}
