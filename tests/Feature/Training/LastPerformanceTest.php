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
use Illuminate\Support\Facades\DB;
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

    /**
     * A clock-scheduled gym action. `recurrence` and `series_started_at` are
     * pinned explicitly rather than left to the factory's random defaults.
     */
    private function clockScheduledGymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(30),
            ]);
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
     * Two occasions can share a `scheduled_for` — different actions, same
     * slot, the same exercise recorded in both — and without a tiebreaker
     * which one counts as "last" is whatever order the engine returns,
     * which differs between SQLite here and MySQL in production.
     *
     * Killing mutation: remove `orderByDesc('occurrences.id')`. Killed 8/8
     * reruns. Rerun repeatedly on purpose, since a dropped tiebreaker is only
     * ever *nondeterministic* in principle — a single failing run could not
     * by itself rule out a lucky pass on a different run. What the eight runs
     * actually showed is that SQLite's join comes back in a stable order even
     * without the tiebreaker, but a stably *wrong* one: it never matches the
     * order the tiebreaker demands, so the mutation was killed every time,
     * not merely once.
     */
    public function test_two_occasions_sharing_a_scheduled_for_resolve_deterministically(): void
    {
        $user = $this->user();
        // Two different actions, not one — `occurrences` uniques on
        // (action_id, scheduled_for), so the same action could never carry
        // two occasions at the same instant.
        $firstAction = $this->clockScheduledGymAction($user);
        $secondAction = $this->clockScheduledGymAction($user);
        $squat = $this->exercise();
        $sharedTime = now()->subDays(3);

        $first = Occurrence::factory()->for($firstAction)->create(['scheduled_for' => $sharedTime]);
        PerformedSet::factory()->create([
            'occurrence_id' => $first->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 40,
        ]);

        $second = Occurrence::factory()->for($secondAction)->create(['scheduled_for' => $sharedTime]);
        PerformedSet::factory()->create([
            'occurrence_id' => $second->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 8,
            'weight' => 90,
        ]);

        $currentAction = $this->clockScheduledGymAction($user);
        $current = app(MaterialisesOccasion::class)->forAction($currentAction);

        $result = app(LastPerformance::class)->forExercise($squat, $current);

        $this->assertNotNull($result);
        // $second was created after $first, so it carries the higher id —
        // the returned sets must be $second's (reps 8, weight 90kg), not
        // $first's (reps 5, weight 40kg).
        $this->assertSame([8], array_column($result['sets'], 'reps'));
        $this->assertSame([90.0], array_column($result['sets'], 'weight'));
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

    /**
     * The read must not grow with history it is never going to return.
     *
     * The exercise catalogue is shared, so one Exercise row accumulates sets
     * from everyone who ever trains it. Batch 3's progression screen runs this
     * read once per exercise on the page, so what it costs per call is not a
     * detail that can be settled later.
     *
     * The statements themselves, not a count of them or of their bindings.
     * The shape being ruled out sent a *constant* number of queries, and did
     * not send the ids as bindings either: `whereKey()` on a collection of
     * integers routes to `whereIntegerInRaw`, which interpolates the whole
     * list into the SQL text as literals. So neither a query count nor a
     * binding count can see it — the growth is in the statement string, which
     * is exactly what this compares.
     *
     * Killing mutation: restore the earlier
     * `PerformedSet::...->pluck('occurrence_id')->unique()` fed back in as
     * `Occurrence::whereKey($occurrenceIds)`. That select is scoped by
     * exercise alone, so every occasion any user has ever recorded this
     * exercise against is interpolated into an `id in (...)` list — 3
     * strangers and 30 strangers then send visibly different statements and
     * this assertion fails. Verified by direct mutation and rerun.
     */
    public function test_the_read_sends_the_same_statements_however_much_history_the_exercise_carries(): void
    {
        $this->assertSame(
            $this->statementsReadingLastPerformanceBeside(3),
            $this->statementsReadingLastPerformanceBeside(30),
            'The last-performance read sends a bigger statement as the shared exercise accumulates other people’s sessions.'
        );
    }

    /**
     * The SQL one `forExercise` call sends, with `$strangers` other users'
     * sessions already recorded against the same shared exercise.
     *
     * @return list<string>
     */
    private function statementsReadingLastPerformanceBeside(int $strangers): array
    {
        $squat = $this->exercise();

        for ($i = 0; $i < $strangers; $i++) {
            $strangerAction = $this->gymAction($this->user());
            $strangerOccurrence = Occurrence::factory()->for($strangerAction)
                ->create(['scheduled_for' => now()->subDays(2)]);
            PerformedSet::factory()->create([
                'occurrence_id' => $strangerOccurrence->id,
                'exercise_id' => $squat->id,
                'set_number' => 1,
                'reps' => 5,
                'weight' => 100,
            ]);
        }

        $user = $this->user();
        $action = $this->gymAction($user);

        $mine = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $mine->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 10,
            'weight' => 60,
        ]);

        $current = app(MaterialisesOccasion::class)->forAction($action);

        // The query log rather than DB::listen: a listener registered per call
        // would still be attached on the next one and double-count it.
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = app(LastPerformance::class)->forExercise($squat, $current);

        $statements = array_map(
            fn (array $query): string => (string) $query['query'],
            DB::getQueryLog(),
        );

        DB::flushQueryLog();
        DB::disableQueryLog();

        // Pins that the measured call actually did the work, so a read that
        // returned null early could not pass this by being cheap.
        $this->assertNotNull($result);
        $this->assertSame([10], array_column($result['sets'], 'reps'));

        return $statements;
    }
}
