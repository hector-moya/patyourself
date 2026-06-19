<?php

namespace Tests\Feature\Models;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_lineage_links_a_version_to_its_parent_and_children()
    {
        $intention = Intention::factory()->create();
        $v1 = Strategy::factory()->for($intention)->create(['version' => 1, 'status' => Strategy::STATUS_SUPERSEDED]);
        $v2 = Strategy::factory()->for($intention)->create([
            'version' => 2,
            'parent_strategy_id' => $v1->id,
        ]);

        $this->assertTrue($v2->parent->is($v1));
        $this->assertTrue($v1->children->first()->is($v2));
        $this->assertTrue($v2->intention->is($intention));
    }

    public function test_active_and_ordered_by_version_scopes()
    {
        $intention = Intention::factory()->create();
        Strategy::factory()->for($intention)->create(['version' => 1, 'status' => Strategy::STATUS_SUPERSEDED]);
        Strategy::factory()->for($intention)->create(['version' => 3, 'status' => Strategy::STATUS_RETIRED]);
        $active = Strategy::factory()->for($intention)->create(['version' => 2, 'status' => Strategy::STATUS_ACTIVE]);

        $this->assertTrue(Strategy::active()->get()->pluck('id')->contains($active->id));
        $this->assertSame(1, Strategy::active()->count());

        $versions = Strategy::query()->orderedByVersion()->pluck('version')->all();
        $this->assertSame([1, 2, 3], $versions);
    }

    public function test_is_active_predicate()
    {
        $intention = Intention::factory()->create();

        $this->assertTrue(Strategy::factory()->for($intention)->create(['version' => 1])->isActive());
        $this->assertFalse(
            Strategy::factory()->for($intention)->superseded()->create(['version' => 2])->isActive()
        );
    }

    public function test_intervention_point_index_locates_the_point_on_the_chain()
    {
        $intention = Intention::factory()->create();

        $cue = Strategy::factory()->for($intention)->create(['version' => 1, 'intervention_point' => Strategy::POINT_CUE]);
        $reward = Strategy::factory()->for($intention)->create(['version' => 2, 'intervention_point' => Strategy::POINT_REWARD]);

        $this->assertSame(0, $cue->interventionPointIndex());
        $this->assertSame(3, $reward->interventionPointIndex());
        // A restrategy moving cue → reward is a downstream shift.
        $this->assertGreaterThan($cue->interventionPointIndex(), $reward->interventionPointIndex());
    }

    public function test_intervention_point_index_is_null_for_an_unknown_point()
    {
        $strategy = Strategy::factory()->make(['intervention_point' => 'nonsense']);

        $this->assertNull($strategy->interventionPointIndex());
    }
}
