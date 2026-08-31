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
     * The tail's own object pool is NOT disjoint from the authored ladder's —
     * the owner ruled ship it repeating for now, and dedupe on the way out
     * (`CompanionState::roomObjects()`) rather than invent a second pool just
     * for the tail. This test documents that overlap on purpose, so the next
     * person who changes either list sees it here rather than discovering it
     * from a dev-console duplicate-key warning.
     *
     * If this ever goes red because the overlap changed, update the asserted
     * set (and the config comment explaining it) — do not delete the test.
     */
    public function test_the_tail_object_pool_overlaps_the_authored_ladder_on_purpose(): void
    {
        $authored = array_values(array_filter(array_map(
            static fn (array $entry): ?string => $entry['roomObject'] ?? null,
            $this->ladder(),
        )));

        $tailObjects = $this->config()['tail']['room_objects'] ?? [];

        $this->assertSame(
            ['rug', 'lamp', 'plant'],
            array_values(array_intersect($tailObjects, $authored)),
            'the tail room-object pool no longer overlaps the authored ladder the way this test documents',
        );
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
     * as much as the code does — the tail included, since it is the copy
     * surface with the longest life and the least supervision: nobody reviews
     * a rung's wording by hand once it is past the authored ladder.
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

        foreach ($this->config()['tail']['messages'] ?? [] as $index => $template) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $template,
                "tail message {$index} says \"{$banned}\"",
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

    /**
     * The tail's templates are rendered against a real item type and a real
     * colour at read time (CompanionResolver::tailUnlocks()), so the grammar
     * has to hold for every combination the resolver can actually produce —
     * not just the ones that happened to ship first. This is what would have
     * caught "Blob has another shoes" and "There is a amber glasses now": the
     * copy-never-keeps-score guard above cannot, because neither is a banned
     * word, both are a banned SHAPE.
     *
     * Mirrors the substitution CompanionResolver::tailUnlocks() does —
     * `{type}` through `item_display_names`, `{variant}` raw — so a future
     * change to either mapping is exercised here rather than only in
     * production.
     */
    public function test_the_tail_copy_reads_correctly_for_every_type_and_colour(): void
    {
        $config = $this->config();
        $messages = $config['tail']['messages'] ?? [];
        $variants = $config['tail']['variants'] ?? [];
        $displayNames = $config['item_display_names'] ?? [];
        $types = $this->itemTypes();

        $this->assertNotEmpty($messages);
        $this->assertNotEmpty($variants);
        $this->assertNotEmpty($types);

        foreach ($messages as $messageIndex => $template) {
            foreach ($types as $type) {
                $displayType = $displayNames[$type] ?? $type;

                foreach ($variants as $variant) {
                    $rendered = str_replace(['{type}', '{variant}'], [$displayType, $variant], $template);
                    $where = "tail message {$messageIndex} with type \"{$type}\" and variant \"{$variant}\" rendered as \"{$rendered}\"";

                    // Padded with a leading space so a banned construction at
                    // the very start of the sentence cannot hide from a
                    // substring check that requires a space before it —
                    // template 1 starts with "A {type}...", which is exactly
                    // the shape of the bug this guards against.
                    $padded = ' '.$rendered;

                    $this->assertStringNotContainsStringIgnoringCase(' a shoes', $padded, $where);
                    $this->assertStringNotContainsStringIgnoringCase(' a glasses', $padded, $where);
                    $this->assertStringNotContainsStringIgnoringCase(' another shoes', $padded, $where);
                    $this->assertStringNotContainsStringIgnoringCase(' another glasses', $padded, $where);
                    $this->assertStringNotContainsStringIgnoringCase(' a amber', $padded, $where);

                    // No indefinite article directly before a vowel-initial
                    // colour, whichever colour that happens to be — avoiding
                    // the construction rather than special-casing vowels.
                    if (preg_match('/^[aeiou]/i', $variant) === 1) {
                        $this->assertStringNotContainsStringIgnoringCase(" a {$variant}", $padded, $where);
                    }
                }
            }
        }
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
     * The tail's own room-object pool overlaps the authored ladder's on
     * purpose (see config('companion.tail.room_objects')), so the same name
     * can legitimately be earned twice. A repeated name reaching the view
     * would both misrepresent the room — it does not gain a second rug — and
     * hand the room's `<g key={name}>` a duplicate React key.
     */
    public function test_state_deduplicates_a_room_object_earned_twice(): void
    {
        $state = new CompanionState(9, 14, [
            $this->unlock('body', 'blob'),
            $this->unlock('ability', 'wave', roomObject: 'rug'),
            $this->unlock('item', 'shoes', variant: 'coral', roomObject: 'rug'),
        ]);

        $this->assertSame(['rug'], $state->roomObjects());
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
