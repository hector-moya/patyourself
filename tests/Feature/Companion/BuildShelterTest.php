<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildShelter;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Putting up one stage of the shelter.
 *
 * The arc is lean-to → hut → cabin and the stages REPLACE one another: two are
 * never standing at once, and what is built only ever advances. A stage whose
 * predecessor does not exist is not refused with an explanation — it is never
 * offered in the first place — so every refusal here is a request the screen
 * does not make.
 */
class BuildShelterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function carrying(int $planks, ?string $shelter = null): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        if ($planks > 0) {
            $companion->items()->create(['item' => 'planks', 'quantity' => $planks]);
        }

        if ($shelter !== null) {
            $companion->update(['shelter' => $shelter]);
        }

        return [$user, $companion->fresh()];
    }

    /** Five concluded experiments, which is the cabin's floor exactly. */
    private function withInsights(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }
    }

    public function test_the_first_stage_goes_up_on_an_empty_clearing(): void
    {
        [$user, $companion] = $this->carrying(4);

        $this->assertSame('lean-to', app(BuildShelter::class)->handle($user, 'lean-to'));
        $this->assertSame('lean-to', $companion->fresh()->shelter);
    }

    /** It consumes exactly its price, and leaves no emptied row behind. */
    public function test_building_consumes_exactly_the_price(): void
    {
        [$user, $companion] = $this->carrying(6);

        app(BuildShelter::class)->handle($user, 'lean-to');

        $this->assertSame(2, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    public function test_a_stage_whose_predecessor_is_missing_is_refused(): void
    {
        [$user, $companion] = $this->carrying(20);

        try {
            app(BuildShelter::class)->handle($user, 'hut');
            $this->fail('the hut should need a lean-to to become');
        } catch (CompanionEconomyException) {
            // Expected. What matters is that it took nothing on the way out.
        }

        $this->assertNull($companion->fresh()->shelter);
        $this->assertSame(20, (int) $companion->items()->where('item', 'planks')->value('quantity'));
    }

    /** And the same stage twice is the same refusal: its predecessor is itself. */
    public function test_a_stage_that_already_stands_is_refused(): void
    {
        [$user, $companion] = $this->carrying(20, 'lean-to');

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildShelter::class)->handle($user, 'lean-to');
        } finally {
            $this->assertSame('lean-to', $companion->fresh()->shelter);
        }
    }

    public function test_the_hut_goes_up_on_a_lean_to(): void
    {
        [$user, $companion] = $this->carrying(8, 'lean-to');

        $this->assertSame('hut', app(BuildShelter::class)->handle($user, 'hut'));
        $this->assertSame('hut', $companion->fresh()->shelter);
    }

    /**
     * The floor E1 pinned, still at five and no longer granting anything. A hut
     * with four insights and fourteen planks is not a cabin.
     */
    public function test_the_cabin_needs_the_floor_as_well_as_the_hut(): void
    {
        [$user, $companion] = $this->carrying(14, 'hut');
        $this->withInsights($user, 4);

        try {
            app(BuildShelter::class)->handle($user, 'cabin');
            $this->fail('four insights is below the cabin floor');
        } catch (CompanionEconomyException) {
            // Expected.
        }

        $this->assertSame('hut', $companion->fresh()->shelter);
        $this->assertSame(14, (int) $companion->items()->where('item', 'planks')->value('quantity'));

        $this->withInsights($user, 1);

        $this->assertSame('cabin', app(BuildShelter::class)->handle($user, 'cabin'));
    }

    public function test_short_planks_refuse_and_consume_nothing(): void
    {
        [$user, $companion] = $this->carrying(3);

        try {
            app(BuildShelter::class)->handle($user, 'lean-to');
            $this->fail('three planks is not four');
        } catch (CompanionEconomyException $refusal) {
            $this->assertStringContainsString('1 planks', $refusal->getMessage());
        }

        $this->assertSame(3, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertNull($companion->fresh()->shelter);
    }

    /**
     * Building needs no skill and no tool. F2's rule from this side: a recipe
     * gates on a tool and never on a skill, and planks already carry the
     * handsaw's gate upstream, so gating here would tax the same work twice.
     */
    public function test_building_a_shelter_needs_no_skill_and_no_tool(): void
    {
        [$user, $companion] = $this->carrying(4);

        $this->assertSame(0, $companion->skills()->count());
        $this->assertSame(0, $companion->items()->where('item', 'handsaw')->count());

        $this->assertSame('lean-to', app(BuildShelter::class)->handle($user, 'lean-to'));
    }

    /** A structure is not carried, so putting one up can never overflow a bag. */
    public function test_a_shelter_never_takes_up_bag_room(): void
    {
        [$user, $companion] = $this->carrying(4);

        app(BuildShelter::class)->handle($user, 'lean-to');

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(0, $fresh->held());
        $this->assertSame(5, $fresh->capacity());
    }

    public function test_a_stage_nobody_authored_is_rejected(): void
    {
        [$user] = $this->carrying(40);

        $this->expectException(InvalidArgumentException::class);

        app(BuildShelter::class)->handle($user, 'castle');
    }

    /**
     * The logs a record has do not stand in for insights. The cabin floor reads
     * the same four insight kinds the resolver derives and nothing else.
     */
    public function test_logging_alone_never_clears_the_cabin_floor(): void
    {
        [$user, $companion] = $this->carrying(14, 'hut');

        for ($index = 0; $index < 30; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(30 - $index),
            ]);
        }

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildShelter::class)->handle($user, 'cabin');
        } finally {
            $this->assertSame('hut', $companion->fresh()->shelter);
        }
    }
}
