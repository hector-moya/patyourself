<?php

namespace Tests\Feature\Api;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The versioned strategy timeline for a loop, read-only. Powers the history
 * view on the loop detail screen (Task 20). History reads oldest version first
 * and is never rewritten in place.
 */
class StrategyHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a two-version history: v1 superseded by v2 after a failure.
     */
    private function loopWithHistory(User $user): Intention
    {
        $intention = Intention::factory()->for($user)->create();

        $v1 = $intention->strategies()->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay the book on the pillow',
            'rationale' => 'Make the cue impossible to miss',
            'change_reason' => Strategy::REASON_INITIAL,
            'superseded_reason' => 'Kept forgetting once in bed',
        ]);

        $intention->strategies()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Read a single page, no more',
            'rationale' => 'Shrink the response to remove friction',
            'change_reason' => Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            'parent_strategy_id' => $v1->id,
        ]);

        return $intention;
    }

    public function test_guests_are_unauthorized(): void
    {
        $intention = Intention::factory()->create();

        $this->getJson("/api/intentions/{$intention->id}/strategies")->assertUnauthorized();
    }

    public function test_returns_the_timeline_oldest_version_first(): void
    {
        $user = User::factory()->create();
        $intention = $this->loopWithHistory($user);

        Sanctum::actingAs($user);

        $this->getJson("/api/intentions/{$intention->id}/strategies")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.status', Strategy::STATUS_SUPERSEDED)
            ->assertJsonPath('data.0.change_reason', Strategy::REASON_INITIAL)
            ->assertJsonPath('data.0.superseded_reason', 'Kept forgetting once in bed')
            ->assertJsonPath('data.1.version', 2)
            ->assertJsonPath('data.1.status', Strategy::STATUS_ACTIVE)
            ->assertJsonPath('data.1.intervention_point', Strategy::POINT_RESPONSE)
            ->assertJsonPath('data.1.change_reason', Strategy::REASON_RESTRATEGIZED_ON_FAILURE);
    }

    public function test_exposes_the_full_history_shape(): void
    {
        $user = User::factory()->create();
        $intention = $this->loopWithHistory($user);

        Sanctum::actingAs($user);

        $this->getJson("/api/intentions/{$intention->id}/strategies")
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'version', 'status', 'intervention_point', 'approach',
                'rationale', 'change_reason', 'superseded_reason',
                'parent_strategy_id', 'created_at',
            ]]]);
    }

    public function test_is_empty_for_a_loop_with_no_strategies(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/intentions/{$intention->id}/strategies")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_forbids_another_users_loop(): void
    {
        $intention = $this->loopWithHistory(User::factory()->create());

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/intentions/{$intention->id}/strategies")->assertForbidden();
    }
}
