<?php

namespace Tests\Feature\Models;

use App\Models\Intention;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_intention_scoped_summary_is_tied_to_a_loop()
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $summary = Summary::factory()->for($user)->for($intention)->create();

        $this->assertTrue($summary->user->is($user));
        $this->assertTrue($summary->intention->is($intention));
        $this->assertTrue($summary->isIntentionScope());
        $this->assertFalse($summary->isUserScope());
    }

    public function test_an_account_scoped_summary_spans_every_loop()
    {
        $summary = Summary::factory()->userScope()->create();

        $this->assertNull($summary->intention_id);
        $this->assertTrue($summary->isUserScope());
        $this->assertFalse($summary->isIntentionScope());
    }

    public function test_events_count_is_cast_to_an_integer()
    {
        $summary = Summary::factory()->create(['events_count' => '7']);

        $this->assertSame(7, $summary->refresh()->events_count);
    }
}
