<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use App\Services\Companion\CompanionRemarks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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

    /**
     * The dangerous case, not just the easy one: the `orWhereHas` that admits
     * an active-loop remark has to sit inside the same closure that scopes to
     * `$user->companionRemarks()`, or every active-loop remark in the database
     * becomes eligible for every user regardless of whose loop it is on.
     */
    public function test_another_users_remark_on_an_active_loop_is_never_eligible(): void
    {
        $other = User::factory()->create();
        $loop = Intention::factory()->for($other)->create(['status' => Intention::STATUS_ACTIVE]);
        CompanionRemark::factory()->for($other)->for($loop)->create();

        $this->assertNull($this->remarks()->nextFor(User::factory()->create()));
    }

    public function test_with_no_remarks_there_is_nothing_to_say(): void
    {
        $this->assertNull($this->remarks()->nextFor(User::factory()->create()));
    }

    /**
     * Two assertions, deliberately not one. With exactly two eligible remarks
     * and one excluded, the eligible-minus-excluded set has exactly one member,
     * so the outcome check below is not a draw at all — it proves the result.
     * The second assertion proves the mechanism: that `$excluding` actually
     * reached the query as a bound value with a key-exclusion predicate, which
     * is what a dropped `whereKeyNot()` would remove without the outcome check
     * alone reliably catching it (see the task report for the sabotage proof).
     */
    public function test_it_does_not_repeat_the_one_shown_last_visit(): void
    {
        $user = User::factory()->create();
        $first = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);
        $second = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $this->assertSame($second->id, $this->remarks()->nextFor($user, $first->id)?->id);

        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $this->remarks()->nextFor($user, $first->id);

        $read = collect($queries)->first(
            static fn (QueryExecuted $query): bool => str_contains($query->sql, 'companion_remarks'),
        );

        $this->assertNotNull($read, 'The exclusion read never ran.');
        $this->assertContains($first->id, $read->bindings);
        $this->assertStringContainsString(
            '"companion_remarks"."id" != ?',
            $read->sql,
            'The excluded id never reached the query as a key-exclusion predicate.',
        );
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
        // Checked before the binding assertion below: PHPUnit stops at the
        // first failure in a test method, and a value embedded rather than
        // bound would satisfy this one and only trip the binding check, which
        // reports the missing binding rather than the embedded literal itself.
        $this->assertStringNotContainsString(
            "'",
            $read->sql,
            'A value is written into the SQL rather than bound.',
        );
        $this->assertContains(Intention::STATUS_ACTIVE, $read->bindings);
    }

    /**
     * One logged outcome is enough for Blob to exist ({@see CompanionResolver}
     * unlocks the first rung at the first log). Everything below that assumes
     * Blob is already on screen for there to be anybody to relay a remark to.
     */
    private function blobExistsFor(User $user): void
    {
        ActionLog::factory()->create([
            'user_id' => $user->id,
            'logged_at' => now()->subDay(),
        ]);
    }

    public function test_the_screen_carries_one_remark(): void
    {
        $user = User::factory()->create();
        $this->blobExistsFor($user);
        $remark = CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('remark', $remark->body));
    }

    /**
     * With no remarks Blob says nothing. No placeholder, no default line, and
     * nothing that reads as a missing thing.
     *
     * Both assertions matter: `has()` proves the prop is present at all — the
     * frontend contract is that `remark` always arrives, `null` when there is
     * nothing to say — and `where()` proves that value is null rather than
     * some other falsy stand-in. Either assertion alone would also pass if the
     * prop were simply missing from the response.
     */
    public function test_an_account_with_no_remarks_gets_silence(): void
    {
        $user = User::factory()->create();
        $this->blobExistsFor($user);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('remark')
                ->where('remark', null),
            );
    }

    /**
     * Before Blob exists there is nobody to relay anything, and picking a
     * remark nothing renders would burn it — the session would record it as
     * shown when it never was.
     */
    public function test_before_blob_exists_no_remark_is_drawn(): void
    {
        $user = User::factory()->create();
        CompanionRemark::factory()->for($user)->create(['intention_id' => null]);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('remark', null));

        $this->assertNull(session(CompanionRemarks::SESSION_KEY));
    }

    /**
     * Two eligible remarks, one excluded by the first visit, leaves exactly one
     * candidate for the second — see {@see CompanionRemarks::nextFor()}: with
     * `$excluding` set it queries the eligible set minus that one id, and with
     * only two eligible rows total that set has exactly one member. So this is
     * not a draw the assertion happens to win; the second visit's pick is
     * forced.
     *
     * The two remarks are given explicit, distinct bodies rather than left to
     * the factory's `randomElement` default — two random picks could land on
     * the same string, which would fail this assertion for the wrong reason.
     */
    public function test_two_visits_do_not_repeat_the_same_remark(): void
    {
        $user = User::factory()->create();
        $this->blobExistsFor($user);
        CompanionRemark::factory()->for($user)->create([
            'intention_id' => null,
            'body' => 'Blob has been standing by the window a lot this week.',
        ]);
        CompanionRemark::factory()->for($user)->create([
            'intention_id' => null,
            'body' => 'Blob moved the rug twice and put it back where it was.',
        ]);

        $shown = [];

        foreach ([0, 1] as $visit) {
            $this->actingAs($user)->get('/companion')->assertInertia(
                function (Assert $page) use (&$shown): void {
                    $shown[] = $page->toArray()['props']['remark'];
                },
            );
        }

        $this->assertNotSame($shown[0], $shown[1]);
    }
}
