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

    /**
     * The normal path, and the one the override must not disturb: with nothing
     * set the record decides. `COMPANION_SCENE=` in a .env file reads back as
     * an empty string rather than as absent, so both have to mean the same
     * thing or half the ways of turning the override off would not.
     */
    public function test_an_absent_or_empty_override_leaves_the_record_deciding(): void
    {
        $user = $this->userWithInsights(5);

        config(['companion.scene_override' => null]);
        $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);

        config(['companion.scene_override' => '']);
        $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);
    }

    /**
     * Why the override exists: the cabin's threshold sits below an established
     * record, so without this there is no way to put the forest on screen
     * short of editing the record itself.
     */
    public function test_the_override_wins_over_the_scene_the_record_derives(): void
    {
        $user = $this->userWithInsights(5);

        config(['companion.scene_override' => 'forest']);

        $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);
    }

    /**
     * Naming a scene can never break the screen. The server hands the name
     * over as it stands rather than checking it against a list of its own:
     * `sceneFor()` in `scenes.ts` is the registry of what can actually be
     * drawn, and it already falls back to the first scene. A second list here
     * would be a second answer to what scenes exist.
     */
    public function test_an_override_no_scene_knows_is_handed_over_rather_than_refused(): void
    {
        $user = $this->userWithInsights(5);

        config(['companion.scene_override' => 'swamp']);

        $this->assertSame('swamp', $this->resolve($user)->toArray()['scene']);
    }
}
