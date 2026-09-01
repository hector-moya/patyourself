<?php

namespace Tests\Feature\Companion;

use App\Actions\UpdateIntention;
use App\Actions\WriteReflection;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blob read back out of the record.
 *
 * The resolver stores nothing, so every case here is really a question about
 * the existing tables: what counts as an outcome, what counts as an insight,
 * and whose they are.
 */
class CompanionResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every timestamp in this file is derived from `now()`, and
     * {@see test_a_failed_outcome_advances_blob_exactly_as_far_as_a_completed_one}
     * compares three users' entire resolved state — `unlocked_at` included.
     *
     * Unfrozen, a run that crosses a second boundary between the first user's
     * outcomes and the third's produces timestamps one second apart and fails
     * on a difference that says nothing about Blob. Nothing in this class needs
     * the clock to move, so it does not.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    private function resolver(): CompanionResolver
    {
        return app(CompanionResolver::class);
    }

    /**
     * Outcomes for one user, `$count` of them, one per day so the order is
     * unambiguous.
     */
    private function logOutcomes(User $user, int $count, string $outcome = ActionLog::OUTCOME_COMPLETED): void
    {
        for ($index = 0; $index < $count; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => $outcome,
                'reason' => $outcome === ActionLog::OUTCOME_FAILED ? 'Never came up' : null,
                'logged_at' => now()->subDays(30 - $index),
            ]);
        }
    }

    public function test_a_user_with_no_record_has_no_blob_yet(): void
    {
        $state = $this->resolver()->forUser(User::factory()->create());

        $this->assertSame(0, $state->stageIndex());
        $this->assertSame(0, $state->logCount);
        $this->assertSame([], $state->features());
        $this->assertNull($state->latestUnlock());
    }

    public function test_the_first_outcome_brings_blob_into_existence(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 1);

        $state = $this->resolver()->forUser($user);

        $this->assertSame(1, $state->stageIndex());
        $this->assertSame(['blob'], $state->features());
        $this->assertSame('blob', $state->latestUnlock()['name']);
        $this->assertStringContainsString('Blob is here.', $state->latestUnlock()['message']);
    }

    public function test_logging_walks_the_first_three_stages(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $state = $this->resolver()->forUser($user);

        $this->assertSame(3, $state->stageIndex());
        $this->assertSame(['blob', 'legs'], $state->features());
        $this->assertSame([['type' => 'shoes', 'variant' => null]], $state->items());
        $this->assertSame([], $state->abilities());
    }

    /**
     * The acceptance criterion the whole design turns on. Honest logging is the
     * behaviour being rewarded, so a failure is worth exactly as much as a
     * completion — and a skip, which says the occasion never happened, is still
     * the record being kept.
     */
    public function test_a_failed_outcome_advances_blob_exactly_as_far_as_a_completed_one(): void
    {
        $completions = User::factory()->create();
        $failures = User::factory()->create();
        $skips = User::factory()->create();

        $this->logOutcomes($completions, 5, ActionLog::OUTCOME_COMPLETED);
        $this->logOutcomes($failures, 5, ActionLog::OUTCOME_FAILED);
        $this->logOutcomes($skips, 5, ActionLog::OUTCOME_SKIPPED);

        $this->assertEquals(
            $this->resolver()->forUser($completions)->toArray(),
            $this->resolver()->forUser($failures)->toArray(),
        );
        $this->assertEquals(
            $this->resolver()->forUser($completions)->toArray(),
            $this->resolver()->forUser($skips)->toArray(),
        );
    }

    public function test_a_concluded_experiment_counts_as_an_insight_whatever_the_verdict(): void
    {
        foreach (Strategy::VERDICTS as $verdict) {
            $user = User::factory()->create();
            $this->logOutcomes($user, 5);

            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create(['verdict' => $verdict, 'verdict_note' => 'What the evidence showed.']);

            $state = $this->resolver()->forUser($user);

            $this->assertSame(1, $state->insightCount, "a {$verdict} verdict should count");
            $this->assertSame(['walk'], $state->abilities());
        }
    }

    public function test_starting_a_new_version_counts_as_an_insight(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create();
        $first = Strategy::factory()->for($loop)->create(['version' => 1]);
        Strategy::factory()->for($loop)->create(['version' => 2, 'parent_strategy_id' => $first->id]);

        $state = $this->resolver()->forUser($user);

        // Only the second version: the first is the loop being created, not an
        // insight about it.
        $this->assertSame(1, $state->insightCount);
        $this->assertSame(['walk'], $state->abilities());
    }

    public function test_correcting_the_chain_counts_as_an_insight(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);

        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);

        $state = $this->resolver()->forUser($user);

        $this->assertSame(1, $state->insightCount);
        $this->assertSame(['walk'], $state->abilities());
    }

    public function test_a_retitle_or_a_pause_is_not_an_insight(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create([
            'title' => 'Evening walk',
            'craving' => 'To feel less tired',
            'status' => Intention::STATUS_ACTIVE,
        ]);

        app(UpdateIntention::class)->handle($loop, ['title' => 'Walk after dinner']);
        app(UpdateIntention::class)->handle($loop, ['status' => Intention::STATUS_PAUSED]);
        // Re-sending the craving unchanged is not a correction either.
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To feel less tired']);

        $this->assertSame(0, $this->resolver()->forUser($user)->insightCount);
    }

    public function test_writing_a_reflection_counts_as_an_insight(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create();

        app(WriteReflection::class)->handle($loop, 'The cue is firing; the response is where it stops.');

        $state = $this->resolver()->forUser($user);

        $this->assertSame(1, $state->insightCount);
        $this->assertSame(['walk'], $state->abilities());
    }

    /**
     * A ladder entry needs the Nth insight overall, so the four sources are one
     * stream rather than four.
     */
    public function test_insights_from_different_sources_accumulate_into_one_stream(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);
        Strategy::factory()->for($loop)->create(['verdict' => Strategy::VERDICT_WORKED]);
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);
        app(WriteReflection::class)->handle($loop, 'Still reads as a cue problem.');

        $state = $this->resolver()->forUser($user);

        $this->assertSame(3, $state->insightCount);
        $this->assertSame(6, $state->stageIndex());
        $this->assertSame(['walk', 'read'], $state->abilities());
        $this->assertSame([
            ['type' => 'shoes', 'variant' => null],
            ['type' => 'scarf', 'variant' => null],
        ], $state->items());
    }

    public function test_another_users_record_never_advances_this_blob(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $this->logOutcomes($stranger, 20);
        Strategy::factory()
            ->for(Intention::factory()->for($stranger))
            ->create(['verdict' => Strategy::VERDICT_WORKED]);

        $this->assertSame(0, $this->resolver()->forUser($user)->stageIndex());
    }

    /**
     * Each unlock is dated by the trigger that earned it, not by the moment the
     * screen asked. The third stage is earned by the third outcome.
     */
    public function test_an_unlock_is_dated_by_the_trigger_that_earned_it(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 3);

        $third = $user->actionLogs()->orderBy('logged_at')->orderBy('id')->get()->last();

        $this->assertSame(
            $third->logged_at->toIso8601String(),
            $this->resolver()->forUser($user)->latestUnlock()['unlocked_at'],
        );
    }

    /**
     * The acceptance criterion that keeps the ladder data: a new stage is a
     * config entry, and nothing in the resolver knows about it.
     */
    public function test_the_ladder_can_be_extended_by_editing_config_alone(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 7);

        $opening = array_slice(config('companion.ladder'), 0, 3);

        config(['companion.ladder' => $opening]);
        $this->assertSame(3, $this->resolver()->forUser($user)->stageIndex());

        config(['companion.ladder' => [
            ...$opening,
            [
                'trigger' => 'logs',
                'at' => 7,
                'kind' => 'ability',
                'name' => 'hum',
                'message' => 'Blob can hum. One note, held for a while.',
            ],
        ]]);

        $state = $this->resolver()->forUser($user);

        $this->assertSame(4, $state->stageIndex());
        $this->assertSame(['hum'], $state->abilities());
    }

    /**
     * An object arrives with the unlock that earned it and never leaves. Until
     * then the room has nothing in it — not an outline of one.
     */
    public function test_a_room_object_arrives_with_the_unlock_that_earned_it(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);

        // Two insights: enough for `walk` and `scarf`, not yet for `read`.
        Strategy::factory()->for($loop)->create(['verdict' => Strategy::VERDICT_WORKED]);
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);

        $this->assertSame([], $this->resolver()->forUser($user)->roomObjects());

        app(WriteReflection::class)->handle($loop, 'The cue is firing.');

        $state = $this->resolver()->forUser($user);

        $this->assertContains('read', $state->abilities());
        $this->assertSame(['bookshelf'], $state->roomObjects());
    }

    /** The renderer flag and the room palette ride along with the state. */
    public function test_the_state_carries_the_config_the_screens_need(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 1);

        $payload = $this->resolver()->forUser($user)->toArray();

        $this->assertSame(config('companion.renderer'), $payload['renderer']);
        $this->assertSame(config('companion.room'), $payload['room']);
        $this->assertArrayHasKey('room_object', $payload['unlocks'][0]);
    }

    /**
     * The ladder is walked in order and stops at the first entry the record does
     * not satisfy, so an insight recorded before Blob exists waits for it.
     */
    public function test_the_walk_stops_at_the_first_unsatisfied_entry(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 1);

        Strategy::factory()
            ->for(Intention::factory()->for($user))
            ->create(['verdict' => Strategy::VERDICT_INCONCLUSIVE]);

        $state = $this->resolver()->forUser($user);

        $this->assertSame(1, $state->insightCount);
        $this->assertSame(1, $state->stageIndex());
        $this->assertSame([], $state->abilities());
    }
}
