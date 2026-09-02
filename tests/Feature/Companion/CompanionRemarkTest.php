<?php

namespace Tests\Feature\Companion;

use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use App\Services\Companion\CompanionRemarks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function remarks(): CompanionRemarks
    {
        return app(CompanionRemarks::class);
    }

    public function test_a_general_remark_is_always_eligible(): void
    {
        $user = User::factory()->create();
        $remark = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $this->assertTrue($this->remarks()->nextFor($user)?->is($remark));
    }

    public function test_a_remark_on_an_active_loop_is_eligible(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $remark = CompanionRemark::factory()->for($user)->for($loop)->create();

        $this->assertTrue($this->remarks()->nextFor($user)?->is($remark));
    }

    /**
     * A loop that has stopped stops talking. The row is never deleted — history
     * is append-only — it simply stops being eligible, and becomes eligible
     * again if the loop is reactivated.
     *
     * @return array<string, array{string}>
     */
    public static function silentStatusProvider(): array
    {
        return [
            'paused' => [Intention::STATUS_PAUSED],
            'archived' => [Intention::STATUS_ARCHIVED],
            'completed' => [Intention::STATUS_COMPLETED],
        ];
    }

    #[DataProvider('silentStatusProvider')]
    public function test_a_remark_on_a_loop_that_is_not_active_is_not_eligible(string $status): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => $status]);
        CompanionRemark::factory()->for($user)->for($loop)->create();

        $this->assertNull($this->remarks()->nextFor($user));
        $this->assertSame(1, CompanionRemark::count());
    }

    public function test_another_users_remark_is_never_eligible(): void
    {
        CompanionRemark::factory()
            ->for(User::factory())
            ->create(['intention_id' => null]);

        $this->assertNull($this->remarks()->nextFor(User::factory()->create()));
    }

    public function test_with_no_remarks_there_is_nothing_to_say(): void
    {
        $this->assertNull($this->remarks()->nextFor(User::factory()->create()));
    }

    public function test_it_does_not_repeat_the_one_shown_last_visit(): void
    {
        $user = User::factory()->create();
        $first = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);
        CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        // Ten draws: a picker that ignored `$excluding` would return the
        // excluded one within a handful of tries.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertNotSame($first->id, $this->remarks()->nextFor($user, $first->id)?->id);
        }
    }

    /**
     * One eligible remark repeats rather than Blob falling silent. Silence
     * here would read as the remark being withdrawn.
     */
    public function test_a_single_remark_repeats_rather_than_going_quiet(): void
    {
        $user = User::factory()->create();
        $only = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $this->assertSame($only->id, $this->remarks()->nextFor($user, $only->id)?->id);
    }

    /**
     * The suite runs on SQLite and production runs on MySQL, so a green test is
     * not evidence the SQL is portable. A prior phase shipped an `ESCAPE '\'`
     * that was fine on SQLite and a syntax error on MySQL, with the whole suite
     * passing.
     *
     * Captures the query the public path actually runs and asserts it is
     * builder-generated all the way down: an EXISTS subquery for the loop's
     * status, and every value bound rather than written into the string.
     */
    public function test_the_eligibility_read_binds_every_value(): void
    {
        $user = User::factory()->create();
        CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $this->remarks()->nextFor($user);

        $read = collect($queries)->first(
            static fn (QueryExecuted $query): bool => str_contains($query->sql, 'companion_remarks'),
        );

        $this->assertNotNull($read, 'The eligibility read never ran.');
        $this->assertStringContainsStringIgnoringCase('exists', $read->sql);
        $this->assertContains(Intention::STATUS_ACTIVE, $read->bindings);
        $this->assertStringNotContainsString(
            "'",
            $read->sql,
            'A value is written into the SQL rather than bound.',
        );
    }
}
