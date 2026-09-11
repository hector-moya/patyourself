<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

class CompanionRoomConfigTest extends TestCase
{
    /**
     * Read from the file rather than through `config()`: this class boots no
     * application, which is the same reason CompanionLadderTest reads its own
     * config directly.
     *
     * @return array<string, array{from: int, wall: string, window: string, light: string, dim: float|int}>
     */
    private function room(): array
    {
        return (require dirname(__DIR__, 3).'/config/companion.php')['room'];
    }

    /**
     * `config('companion.room')` is the source of truth for the parts of the
     * day, and this list is written out a second time in `scenes.test.ts`,
     * which checks the forest declares a backdrop for each of them. A fifth
     * part added to config reddens here first — exact equality, not a subset —
     * and that failure is the reminder to go and add the backdrop.
     */
    public function test_the_day_has_four_parts(): void
    {
        $this->assertSame(
            ['sunrise', 'day', 'dusk', 'night'],
            array_keys($this->room()),
        );
    }

    public function test_each_part_starts_after_the_one_before_it(): void
    {
        $previous = -1;

        foreach ($this->room() as $name => $palette) {
            $this->assertGreaterThan(
                $previous,
                $palette['from'],
                "{$name} does not start after the part before it",
            );

            $previous = $palette['from'];
        }
    }

    /**
     * The overlay is drawn over everything including Blob, so a daytime
     * opacity above zero would tint the character at noon for no reason.
     */
    public function test_daylight_adds_no_tint(): void
    {
        $room = $this->room();

        $this->assertSame(0, $room['day']['dim']);
        $this->assertGreaterThan(0, $room['night']['dim']);
        $this->assertGreaterThan(0, $room['sunrise']['dim']);
        $this->assertGreaterThan(0, $room['dusk']['dim']);
    }

    public function test_every_part_carries_a_full_palette(): void
    {
        foreach ($this->room() as $name => $palette) {
            foreach (['from', 'wall', 'window', 'light', 'dim'] as $key) {
                $this->assertArrayHasKey($key, $palette, "{$name} is missing {$key}");
            }
        }
    }

    /**
     * Exactly one part of the day is the one Blob sleeps through, and it is the
     * one that starts latest. The flag is what the drawing keys off, so a
     * config that flagged `day` would put Blob to sleep at lunchtime and a
     * config that flagged none would leave it awake all night.
     */
    public function test_the_last_part_of_the_day_is_the_one_blob_sleeps_through(): void
    {
        $room = $this->room();

        $asleep = array_keys(array_filter(
            $room,
            static fn (array $part): bool => ($part['asleep'] ?? false) === true,
        ));

        $this->assertCount(1, $asleep);

        // uasort keeps the keys, so the last one is the part that starts
        // latest — which is the part that has to be the sleeping one.
        uasort($room, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $this->assertSame(array_key_last($room), $asleep[0]);
    }
}
