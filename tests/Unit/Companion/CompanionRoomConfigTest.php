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
}
