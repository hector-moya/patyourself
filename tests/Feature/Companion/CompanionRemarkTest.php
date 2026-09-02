<?php

namespace Tests\Feature\Companion;

use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The remarks Blob relays. Written by the coach through MCP, read back on
 * /companion and nowhere else.
 */
class CompanionRemarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_remark_belongs_to_a_user_and_optionally_to_a_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $general = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);
        $attached = CompanionRemark::factory()->for($user)->for($loop)->create();

        $this->assertNull($general->intention);
        $this->assertTrue($attached->intention->is($loop));
        $this->assertTrue($attached->user->is($user));
        $this->assertSame(2, $user->companionRemarks()->count());
    }

    public function test_the_body_is_stored_exactly_as_it_was_written(): void
    {
        $body = '  Blob has been standing by the window.  ';

        $remark = CompanionRemark::factory()
            ->for(User::factory())
            ->create(['intention_id' => null, 'body' => $body]);

        $this->assertSame($body, $remark->refresh()->body);
    }
}
