<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Training\LastPerformance;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exercise screen's "what you lifted last time" read model.
 *
 * A pure read: no suggested weight, no record detection, no 1RM, no
 * percentage, no trend called progress — the spec says the only thing a
 * suggestion would give you is last session's own numbers, and those can
 * simply be on the screen.
 */
class LastPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-07 19:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    private function gymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    private function exercise(string $name = 'Barbell Back Squat'): Exercise
    {
        return Exercise::factory()->create(['name' => $name]);
    }

    public function test_it_returns_nothing_for_an_exercise_with_no_history(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();
        $current = app(MaterialisesOccasion::class)->forAction($action);

        $this->assertNull(app(LastPerformance::class)->forExercise($squat, $current));
    }

    /**
     * Two prior sessions plus data already entered into the very session
     * being viewed. The result must be the more recent of the two prior
     * sessions — proving both the ordering and that the current occasion's
     * own (still in-progress) sets are excluded rather than winning by being
     * the newest row in the table.
     *
     * Killing mutation: drop the `where('occurrence_id', '!=', $excluding->id)`
     * exclusion. The current occasion is the chronologically latest, so it
     * would win and this test's reps assertion would read today's
     * in-progress numbers instead of last session's. Verified by direct
     * mutation and rerun, captured in the task report.
     */
    public function test_it_returns_the_most_recent_prior_sessions_sets_and_excludes_the_current_occasion(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();

        $old = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDays(4)]);
        PerformedSet::factory()->create([
            'occurrence_id' => $old->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 40,
        ]);

        $recent = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDays(1)]);
        PerformedSet::factory()->create([
            'occurrence_id' => $recent->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 10,
            'weight' => 60,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $recent->id,
            'exercise_id' => $squat->id,
            'set_number' => 2,
            'reps' => 8,
            'weight' => 60,
        ]);

        $current = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->create([
            'occurrence_id' => $current->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 12,
            'weight' => 70,
        ]);

        $result = app(LastPerformance::class)->forExercise($squat, $current);

        $this->assertNotNull($result);
        $this->assertTrue($recent->scheduled_for->equalTo($result['performed_at']));
        $this->assertSame([10, 8], array_column($result['sets'], 'reps'));
        $this->assertSame([60.0, 60.0], array_column($result['sets'], 'weight'));
    }

    /**
     * Killing mutation: default the weight read to `(float) $set->weight`
     * unconditionally instead of preserving `null`. PHP casts `null` to
     * `0.0`, so `assertNull` fails against a `0.0`. Verified by direct
     * mutation and rerun, captured in the task report.
     */
    public function test_a_body_weight_set_comes_back_as_null_rather_than_zero(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $pushup = $this->exercise('Push-Up');

        $earlier = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $earlier->id,
            'exercise_id' => $pushup->id,
            'set_number' => 1,
            'reps' => 20,
            'weight' => null,
        ]);

        $current = app(MaterialisesOccasion::class)->forAction($action);

        $result = app(LastPerformance::class)->forExercise($pushup, $current);

        $this->assertNotNull($result);
        $this->assertNull($result['sets'][0]['weight']);
    }

    /**
     * The exercise catalogue is shared, so the same Exercise row can carry
     * sets from many users. A last-performance read that only scoped by
     * exercise_id would hand one user's numbers to another.
     *
     * Killing mutation: drop the user scoping from the occasion lookup.
     * Verified by direct mutation and rerun, captured in the task report.
     */
    public function test_it_does_not_return_another_users_history(): void
    {
        $stranger = $this->user();
        $strangerAction = $this->gymAction($stranger);
        $squat = $this->exercise();

        $strangerOccurrence = Occurrence::factory()->for($strangerAction)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $strangerOccurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 100,
        ]);

        $user = $this->user();
        $action = $this->gymAction($user);
        $current = app(MaterialisesOccasion::class)->forAction($action);

        $this->assertNull(app(LastPerformance::class)->forExercise($squat, $current));
    }
}
