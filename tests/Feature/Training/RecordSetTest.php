<?php

namespace Tests\Feature\Training;

use App\Actions\Training\RecordSet;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Scheduling\ResolvesOccasionSlot;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording a set — the gym workflow's record extension site, written.
 *
 * Two claims. First, numbering: `set_number` continues from whatever is
 * already on the (occurrence, exercise) pair rather than restarting at 1,
 * because two sessions on the same day with no verdict pressed between them
 * deliberately resolve to the same occasion (see
 * {@see ResolvesOccasionSlot}), and a restart would
 * throw against the unique index on (occurrence_id, exercise_id,
 * set_number).
 *
 * Second, the economy: recording does not log. `logCount`, read through
 * {@see CompanionResolver}, is what the companion ladder spends — writing
 * a {@see PerformedSet} must move it by exactly zero, however many sets a
 * session racks up, because one occasion produces exactly one
 * {@see ActionLog} and the verdict is a separate press, by a person, always.
 */
class RecordSetTest extends TestCase
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

    /**
     * A cue-anchored action on a loop that records through the gym workflow.
     * `anchored()` pins both of ActionFactory's random fields — `recurrence`
     * and `series_started_at` — to null, so nothing here depends on which
     * values the factory would otherwise have rolled.
     */
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

    public function test_it_numbers_the_first_set_one(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $set = app(RecordSet::class)->handle($occurrence, $squat, 8, 60.0);

        $this->assertSame(1, $set->set_number);
        $this->assertSame(1, PerformedSet::count());
    }

    /**
     * Killing mutation: swap `max('set_number') + 1` for `count() + 1`. The
     * existing rows are deliberately non-contiguous (1 and 5, not 1 and 2) —
     * a row count of 2 looks the same whether the highest number already used
     * is 2 or 5, so only a real MAX distinguishes them. Verified by direct
     * mutation and rerun, captured in the task report.
     */
    public function test_it_continues_numbering_from_the_existing_max(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 5,
        ]);

        $set = app(RecordSet::class)->handle($occurrence, $squat, 8, 60.0);

        $this->assertSame(6, $set->set_number);
    }

    /**
     * Two sessions, no verdict pressed between them, so
     * {@see MaterialisesOccasion} deliberately resolves both to the same
     * occasion. The second session's recording must continue the first
     * session's numbering rather than restart at 1 — restarting would throw
     * a QueryException against the unique index on (occurrence_id,
     * exercise_id, set_number).
     *
     * The existing rows are non-contiguous (1 and 5) for the same reason as
     * the test above: it is what actually distinguishes a MAX-based
     * implementation from a COUNT-based one. Asserting the resulting
     * numbering, not merely the absence of an exception, is deliberate — an
     * implementation that silently swallowed the collision would pass a
     * weaker test.
     *
     * Killing mutation: swap `max('set_number') + 1` for `count() + 1`.
     * Verified by direct mutation and rerun, captured in the task report.
     */
    public function test_a_second_session_on_the_same_occasion_does_not_restart_at_one(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();

        $session1 = app(MaterialisesOccasion::class)->forAction($action);

        PerformedSet::factory()->create([
            'occurrence_id' => $session1->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $session1->id,
            'exercise_id' => $squat->id,
            'set_number' => 5,
        ]);

        // Later the same day, recording starts again. No verdict has been
        // pressed, so this resolves to the very same occasion — not a new
        // one.
        $session2 = app(MaterialisesOccasion::class)->forAction($action);
        $this->assertSame($session1->id, $session2->id);

        $set = app(RecordSet::class)->handle($session2, $squat, 8, 60.0);

        $this->assertSame(6, $set->set_number);
        $this->assertSame(
            [1, 5, 6],
            PerformedSet::where('occurrence_id', $session1->id)
                ->where('exercise_id', $squat->id)
                ->orderBy('set_number')
                ->pluck('set_number')
                ->all(),
        );
    }

    /**
     * `weight` is NULL for body weight, never 0 — zero is a weight, and a
     * progression read cannot tell "no load" from "no load recorded".
     *
     * Killing mutation: default `$weight` to `0.0` instead of passing `null`
     * through. `assertNull` then fails against a stored `"0.00"`. Verified by
     * direct mutation and rerun, captured in the task report.
     */
    public function test_a_body_weight_set_records_null_not_zero(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $pushup = $this->exercise('Push-Up');
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $set = app(RecordSet::class)->handle($occurrence, $pushup, 15, null);

        $this->assertNull($set->weight);
        $this->assertNull($set->fresh()->weight);
    }

    public function test_a_set_with_reps_and_no_weight_is_valid(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $pullup = $this->exercise('Pull-Up');
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $this->actingAs($user)
            ->post(route('occurrences.sets.store', $occurrence), [
                'exercise_id' => $pullup->id,
                'reps' => 10,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $set = PerformedSet::sole();
        $this->assertSame(10, $set->reps);
        $this->assertNull($set->weight);
    }

    /**
     * "A set with neither is not recorded" — reps is what makes a row mean
     * anything at all, so a payload naming neither reps nor weight is
     * refused before it ever reaches {@see RecordSet}.
     *
     * Killing mutation: drop the `required` rule from `reps`. The request
     * then succeeds and this test's `assertSessionHasErrors` fails.
     * Verified by direct mutation and rerun, captured in the task report.
     */
    public function test_a_set_with_neither_is_refused(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $pullup = $this->exercise('Pull-Up');
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $this->actingAs($user)
            ->post(route('occurrences.sets.store', $occurrence), [
                'exercise_id' => $pullup->id,
            ])
            ->assertSessionHasErrors('reps');

        $this->assertSame(0, PerformedSet::count());
    }

    /**
     * Killing mutation: replace `Exercise::availableTo($this->user())` with a
     * bare `exists:exercises,id` rule. The stranger's private row still
     * exists, so the request would succeed and this test would fail.
     * Verified by direct mutation and rerun, captured in the task report.
     */
    public function test_an_exercise_outside_the_users_catalogue_is_refused(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        $stranger = $this->user();
        $private = Exercise::factory()->for($stranger)->create(['name' => "Stranger's own move"]);

        $this->actingAs($user)
            ->post(route('occurrences.sets.store', $occurrence), [
                'exercise_id' => $private->id,
                'reps' => 10,
            ])
            ->assertSessionHasErrors('exercise_id');

        $this->assertSame(0, PerformedSet::count());
    }

    /**
     * Recording is gated the same way logging is: a set is a fact about one
     * occasion, and an occasion belongs to the user who owns its loop.
     *
     * Killing mutation: delete the `Gate::authorize('log', $occurrence)`
     * line from the controller. Verified by direct mutation and rerun,
     * captured in the task report.
     */
    public function test_another_users_occurrence_is_refused(): void
    {
        $owner = $this->user();
        $action = $this->gymAction($owner);
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        $intruder = $this->user();
        $squat = $this->exercise();

        $this->actingAs($intruder)
            ->post(route('occurrences.sets.store', $occurrence), [
                'exercise_id' => $squat->id,
                'reps' => 10,
            ])
            ->assertForbidden();

        $this->assertSame(0, PerformedSet::count());
    }

    public function test_recording_a_set_creates_no_log_and_moves_the_count_by_zero(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise();
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        app(RecordSet::class)->handle($occurrence, $squat, 8, 60.0);

        $this->assertSame(0, ActionLog::count());
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_forty_sets_across_four_exercises_still_create_no_log(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        $exercises = Exercise::factory()->count(4)->create();
        $record = app(RecordSet::class);

        foreach ($exercises as $exercise) {
            for ($i = 0; $i < 10; $i++) {
                $record->handle($occurrence, $exercise, 8, 60.0);
            }
        }

        $this->assertSame(40, PerformedSet::where('occurrence_id', $occurrence->id)->count());
        $this->assertSame(0, ActionLog::count());
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }
}
