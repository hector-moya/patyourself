<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Recurrence;
use PHPUnit\Framework\TestCase;

class RecurrenceTest extends TestCase
{
    public function test_maps_known_tokens(): void
    {
        $this->assertSame(Recurrence::Daily, Recurrence::tryFromToken('daily'));
        $this->assertSame(Recurrence::Weekdays, Recurrence::tryFromToken('weekdays'));
        $this->assertSame(Recurrence::Weekly, Recurrence::tryFromToken('weekly'));
    }

    public function test_once_null_and_unknown_tokens_are_one_off(): void
    {
        $this->assertNull(Recurrence::tryFromToken('once'));
        $this->assertNull(Recurrence::tryFromToken(null));
        $this->assertNull(Recurrence::tryFromToken('fortnightly'));
    }
}
