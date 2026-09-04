<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Companion\CompanionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which of Blob's two places the record puts it in.
 *
 * A pure read over the same counts the ladder walk already has — no scene is
 * ever stored, so a new threshold in a later phase is a config edit, not a
 * migration.
 */
class CompanionSceneTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(User $user): CompanionState
    {
        return app(CompanionResolver::class)->forUser($user);
    }

    /**
     * Enough logs to clear the log-driven opening of the ladder, plus
     * `$count` insight events spread across separate loops so none of them
     * collide on the `(intention_id, version)` unique constraint.
     */
    private function userWithInsights(int $count): User
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 5; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(30 - $index),
            ]);
        }

        for ($index = 0; $index < $count; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }

        return $user;
    }

    public function test_a_new_record_starts_outside(): void
    {
        $user = User::factory()->create();

        $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);
    }

    public function test_a_record_that_has_reached_the_threshold_is_indoors(): void
    {
        $user = $this->userWithInsights(5);

        $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);
    }

    /**
     * The regression this threshold exists to prevent: an established record
     * must not lose sight of anything it earned.
     */
    public function test_an_established_record_keeps_every_object_it_earned(): void
    {
        $user = $this->userWithInsights(9);
        $state = $this->resolve($user)->toArray();

        $this->assertSame('cabin', $state['scene']);
        $this->assertContains('bookshelf', $state['room_objects']);
    }

    public function test_the_scene_is_read_from_the_record_and_never_stored(): void
    {
        $user = $this->userWithInsights(5);

        $this->resolve($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id, 'scene' => 'cabin']);
    }
}
