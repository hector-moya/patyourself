<?php

namespace Tests\Unit\Companion;

use App\Services\Companion\CompanionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ladder is data, and these are the rules that data has to keep. They exist
 * so that adding a stage stays a config edit: anything a future entry could get
 * wrong is caught here rather than on the screen.
 */
class CompanionLadderTest extends TestCase
{
    /** @var list<string> */
    private const TRIGGERS = ['logs', 'insights'];

    /** @var list<string> */
    private const KINDS = ['body', 'item', 'ability'];

    /**
     * Read from the file rather than through config(), so this stays a unit test
     * with no application booted around it.
     *
     * @return array{item_types: list<string>, ladder: list<array<string, mixed>>}
     */
    private function config(): array
    {
        return require __DIR__.'/../../../config/companion.php';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ladder(): array
    {
        return $this->config()['ladder'];
    }

    /**
     * @return list<string>
     */
    private function itemTypes(): array
    {
        return $this->config()['item_types'];
    }

    public function test_every_entry_is_shaped_the_way_the_resolver_reads_it(): void
    {
        foreach ($this->ladder() as $index => $entry) {
            $where = "ladder entry {$index}";

            $this->assertArrayHasKey('trigger', $entry, $where);
            $this->assertArrayHasKey('at', $entry, $where);
            $this->assertArrayHasKey('kind', $entry, $where);
            $this->assertArrayHasKey('name', $entry, $where);
            $this->assertArrayHasKey('message', $entry, $where);

            $this->assertContains($entry['trigger'], self::TRIGGERS, $where);
            $this->assertContains($entry['kind'], self::KINDS, $where);
            $this->assertIsInt($entry['at'], $where);
            $this->assertGreaterThan(0, $entry['at'], $where);
            $this->assertNotSame('', trim($entry['message']), $where);

            if (array_key_exists('roomObject', $entry)) {
                $this->assertIsString($entry['roomObject'], $where);
                $this->assertNotSame('', trim($entry['roomObject']), $where);
            }
        }
    }

    /**
     * The room is a record of what happened, so an object belongs to the thing
     * that earned it. Two entries handing out the same object would put it in
     * the room twice.
     */
    public function test_no_room_object_is_handed_out_twice(): void
    {
        $objects = array_values(array_filter(array_map(
            static fn (array $entry): ?string => $entry['roomObject'] ?? null,
            $this->ladder(),
        )));

        $this->assertSame($objects, array_unique($objects));
    }

    /**
     * The resolver walks the ladder in order and stops at the first unsatisfied
     * entry, so a threshold that goes backwards within a trigger would make an
     * earlier entry unreachable once a later one is satisfied.
     */
    public function test_thresholds_climb_within_each_trigger(): void
    {
        $highest = array_fill_keys(self::TRIGGERS, 0);

        foreach ($this->ladder() as $index => $entry) {
            $this->assertGreaterThan(
                $highest[$entry['trigger']],
                $entry['at'],
                "ladder entry {$index} does not climb past the previous {$entry['trigger']} threshold",
            );

            $highest[$entry['trigger']] = $entry['at'];
        }
    }

    /**
     * The first three stages come from logging so the first week is alive
     * without waiting on an experiment to conclude.
     */
    public function test_the_first_three_stages_come_from_logging(): void
    {
        $ladder = $this->ladder();

        $this->assertSame(['logs', 1, 'body', 'blob'], [$ladder[0]['trigger'], $ladder[0]['at'], $ladder[0]['kind'], $ladder[0]['name']]);
        $this->assertSame(['logs', 3, 'body', 'legs'], [$ladder[1]['trigger'], $ladder[1]['at'], $ladder[1]['kind'], $ladder[1]['name']]);
        $this->assertSame(['logs', 5, 'item', 'shoes'], [$ladder[2]['trigger'], $ladder[2]['at'], $ladder[2]['kind'], $ladder[2]['name']]);
    }

    /**
     * Everything past the log-driven opening is earned by an insight event, and
     * alternates ability -> item -> ability -> item.
     */
    public function test_stages_after_the_opening_alternate_ability_and_item(): void
    {
        $rest = array_slice($this->ladder(), 3);

        $this->assertNotEmpty($rest, 'the ladder should carry insight-driven stages');

        foreach ($rest as $offset => $entry) {
            $this->assertSame('insights', $entry['trigger'], 'stage '.($offset + 4).' should be earned by an insight event');
            $this->assertSame(
                $offset % 2 === 0 ? 'ability' : 'item',
                $entry['kind'],
                'stage '.($offset + 4).' breaks the ability -> item alternation',
            );
        }
    }

    /**
     * Items are capped at four distinct types forever. Past the fourth, a stage
     * recolours something Blob already owns instead of inventing a fifth thing
     * to collect.
     */
    public function test_items_never_introduce_a_fifth_type(): void
    {
        $types = $this->itemTypes();

        $this->assertCount(4, $types);

        $seen = [];

        foreach ($this->ladder() as $index => $entry) {
            if ($entry['kind'] !== 'item') {
                continue;
            }

            $this->assertContains($entry['name'], $types, "ladder entry {$index} wears an item type that is not in config");

            if (in_array($entry['name'], $seen, true)) {
                $this->assertNotNull(
                    $entry['variant'] ?? null,
                    "ladder entry {$index} repeats an item type, so it has to name a colour variant",
                );
            }

            $seen[] = $entry['name'];
        }

        $this->assertLessThanOrEqual(4, count(array_unique($seen)));
    }

    /**
     * Blob is not a score. This is the acceptance criterion the copy has to keep
     * as much as the code does.
     */
    #[DataProvider('bannedCopyProvider')]
    public function test_the_copy_never_keeps_score(string $banned): void
    {
        foreach ($this->ladder() as $index => $entry) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $entry['message'],
                "ladder entry {$index} says \"{$banned}\"",
            );
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function bannedCopyProvider(): array
    {
        return [
            ['streak'],
            ['congratulation'],
            ['well done'],
            ['you did it'],
            ['keep it up'],
            ['%'],
            ['!'],
        ];
    }

    public function test_state_sorts_unlocks_into_features_items_and_abilities(): void
    {
        $state = new CompanionState(5, 2, [
            $this->unlock('body', 'blob'),
            $this->unlock('body', 'legs'),
            $this->unlock('item', 'shoes'),
            $this->unlock('ability', 'walk'),
            $this->unlock('item', 'scarf', 'coral'),
        ]);

        $this->assertSame(['blob', 'legs'], $state->features());
        $this->assertSame(['walk'], $state->abilities());
        $this->assertSame([
            ['type' => 'shoes', 'variant' => null],
            ['type' => 'scarf', 'variant' => 'coral'],
        ], $state->items());
        $this->assertSame(5, $state->stageIndex());
        $this->assertSame('scarf', $state->latestUnlock()['name']);
    }

    public function test_state_before_the_first_outcome_is_empty_rather_than_absent(): void
    {
        $state = new CompanionState(0, 0, []);

        $this->assertSame(0, $state->stageIndex());
        $this->assertSame([], $state->features());
        $this->assertSame([], $state->items());
        $this->assertSame([], $state->abilities());
        $this->assertNull($state->latestUnlock());
    }

    /**
     * The room holds only what has been earned, and each object arrives with
     * the unlock that earned it.
     */
    public function test_state_lists_only_the_room_objects_that_were_earned(): void
    {
        $state = new CompanionState(5, 2, [
            $this->unlock('body', 'blob'),
            $this->unlock('ability', 'read', roomObject: 'bookshelf'),
            $this->unlock('item', 'hat'),
        ]);

        $this->assertSame(['bookshelf'], $state->roomObjects());
    }

    public function test_a_room_with_nothing_earned_yet_is_empty_rather_than_outlined(): void
    {
        $state = new CompanionState(1, 0, [$this->unlock('body', 'blob')]);

        $this->assertSame([], $state->roomObjects());
    }

    /**
     * @return array{kind: string, name: string, variant: ?string, room_object: ?string, message: string, unlocked_at: ?string}
     */
    private function unlock(
        string $kind,
        string $name,
        ?string $variant = null,
        ?string $roomObject = null,
    ): array {
        return [
            'kind' => $kind,
            'name' => $name,
            'variant' => $variant,
            'room_object' => $roomObject,
            'message' => "Blob has {$name} now.",
            'unlocked_at' => '2026-08-27T09:00:00+00:00',
        ];
    }
}
