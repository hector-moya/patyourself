<?php

namespace Tests\Unit\Companion;

use Tests\TestCase;

class CompanionWoodpileContentTest extends TestCase
{
    public function test_everything_the_pile_takes_is_something_blob_can_carry(): void
    {
        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes');

        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag');

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried');

        $this->assertNotEmpty($takes);

        foreach ($takes as $item) {
            $this->assertArrayHasKey($item, $catalogue, "[{$item}] is not in the bag's catalogue.");
            $this->assertContains(
                $catalogue[$item]['category'],
                $carried,
                "[{$item}] is not a carried category, so it can never be in the bag to stack.",
            );
        }
    }

    public function test_the_line_blob_says_names_what_it_stacked(): void
    {
        $line = (string) config('companion.woodpile.stacked');

        $this->assertStringContainsString('{name}', $line);
        $this->assertStringContainsString('{count}', $line);
        $this->assertStringContainsString('{label}', $line);
        $this->assertStringNotContainsString('!', $line);
    }
}
