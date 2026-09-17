<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ladder, pinned whole, immediately before
 * {@see CompanionResolver::insightMoments()} changes shape to carry each
 * insight's kind (F1 §6).
 *
 * The authored walk and the tail both index into that return value. This
 * asserts the entire resolved unlock list — names, kinds, variants, room
 * objects and the moment each was earned — so a refactor that reorders, drops
 * or re-dates a single insight cannot pass.
 *
 * Nothing here should ever need editing again. If it goes red, the ladder
 * changed, and that is the thing this file exists to notice.
 */
class CompanionLadderPinTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->base = CarbonImmutable::parse('2026-01-01T09:00:00+00:00');
    }

    public function test_the_ladder_and_its_tail_are_unchanged(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 5; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $this->base->addDays($index),
            ]);
        }

        // Cycled rather than grouped, so the merge across the four sources is
        // exercised: a sort that is only correct within one kind fails here.
        $kinds = [
            'reflection',
            'chain-correction',
            'started-experiment',
            'concluded-experiment',
        ];

        $moments = [];

        for ($index = 0; $index < 15; $index++) {
            $at = $this->base->addDays(10)->addHours($index);
            $moments[] = $at;

            $this->insight($user, $kinds[$index % 4], $at);
        }

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertSame(5, $state->logCount);
        $this->assertSame(15, $state->insightCount);

        $shape = array_map(
            static fn (array $unlock): array => [
                $unlock['kind'],
                $unlock['name'],
                $unlock['variant'],
                $unlock['room_object'],
            ],
            $state->unlocks,
        );

        $this->assertSame([
            ['body', 'blob', null, null],
            ['body', 'legs', null, null],
            ['body', 'arms', null, null],
            ['item', 'shoes', null, null],
            ['ability', 'walk', null, null],
            ['item', 'scarf', null, null],
            ['ability', 'read', null, 'bookshelf'],
            ['item', 'hat', null, null],
            ['ability', 'wave', null, 'rug'],
            ['item', 'glasses', null, null],
            ['ability', 'jump', null, 'lamp'],
            ['item', 'scarf', 'coral', null],
            ['ability', 'carry', null, 'plant'],
            // The tail: a recolour every three further insights. No room
            // object on either of these — `room_every` is 3, so the first tail
            // object arrives on the third tail rung, at 18 insights.
            ['item', 'shoes', 'coral', null],
            ['item', 'scarf', 'moss', null],
        ], $shape);

        // Dated by the trigger that earned it, not by the request that noticed
        // it. The four log rungs first, then the insight rungs in order.
        $this->assertSame(
            $this->base->toIso8601String(),
            $state->unlocks[0]['unlocked_at'],
        );
        $this->assertSame(
            $this->base->addDays(4)->toIso8601String(),
            $state->unlocks[3]['unlocked_at'],
        );
        $this->assertSame(
            $moments[0]->toIso8601String(),
            $state->unlocks[4]['unlocked_at'],
        );
        $this->assertSame(
            $moments[8]->toIso8601String(),
            $state->unlocks[12]['unlocked_at'],
        );
        $this->assertSame(
            $moments[11]->toIso8601String(),
            $state->unlocks[13]['unlocked_at'],
        );
        $this->assertSame(
            $moments[14]->toIso8601String(),
            $state->unlocks[14]['unlocked_at'],
        );
    }

    /**
     * One insight of one kind, at one moment, built from the rows the resolver
     * actually reads rather than through the Actions that normally write them —
     * this is a pin of the resolver, and the timestamps have to be exact.
     */
    private function insight(User $user, string $kind, CarbonImmutable $at): void
    {
        match ($kind) {
            'reflection' => Summary::factory()->create([
                'user_id' => $user->id,
                'intention_id' => Intention::factory()->for($user),
                'scope' => Summary::SCOPE_INTENTION,
                'created_at' => $at,
                'updated_at' => $at,
            ]),

            'chain-correction' => Intention::factory()->for($user)->create([
                'metadata' => ['chain_revisions' => [
                    ['at' => $at->toIso8601String(), 'field' => 'craving'],
                ]],
            ]),

            'started-experiment' => $this->startedExperiment($user, $at),

            'concluded-experiment' => Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                    'created_at' => $at->subMinute(),
                    'updated_at' => $at,
                ]),

            default => null,
        };
    }

    /**
     * The parent has no verdict and no parent of its own, so it is the loop
     * being created rather than an insight — only the child counts.
     */
    private function startedExperiment(User $user, CarbonImmutable $at): void
    {
        $loop = Intention::factory()->for($user)->create();

        $first = Strategy::factory()->for($loop)->create([
            'version' => 1,
            'created_at' => $at->subMinute(),
            'updated_at' => $at->subMinute(),
        ]);

        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'parent_strategy_id' => $first->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
