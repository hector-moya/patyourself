<?php

namespace Tests\Feature\Loops;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LoopsIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'running', 'status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['title' => 'resting', 'status' => Intention::STATUS_PAUSED]);

        $this->actingAs($user)->get(route('loops.index', ['status' => 'paused']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', 'paused')
                ->has('intentions', 1)
                ->where('intentions.0.title', 'resting'));
    }

    public function test_search_matches_the_chain_not_just_the_title(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'press-ups', 'cue' => 'the kettle clicks off']);
        Intention::factory()->for($user)->create(['title' => 'reading', 'cue' => 'getting into bed']);

        $this->actingAs($user)->get(route('loops.index', ['q' => 'kettle']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'press-ups'));
    }

    public function test_the_filters_compose(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'press-ups', 'cue' => 'the kettle clicks off', 'status' => Intention::STATUS_ACTIVE,
        ]);
        Intention::factory()->for($user)->create([
            'title' => 'tea', 'cue' => 'the kettle clicks off', 'status' => Intention::STATUS_PAUSED,
        ]);

        $this->actingAs($user)->get(route('loops.index', ['q' => 'kettle', 'status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'press-ups'));
    }

    public function test_a_percent_in_the_search_is_a_literal_not_a_wildcard(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'give 100% at the gym']);
        Intention::factory()->for($user)->create(['title' => 'run 100 reps at the gym']);

        // Unescaped, `%100%%` collapses to `%100%` and matches BOTH titles.
        // Escaped, only the title carrying a literal percent sign matches.
        $this->actingAs($user)->get(route('loops.index', ['q' => '100%']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'give 100% at the gym'));
    }

    public function test_an_underscore_in_the_search_is_a_literal_not_a_single_character_wildcard(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'a_b']);
        Intention::factory()->for($user)->create(['title' => 'axb']);

        // Unescaped, `_` is a single-character wildcard, so `%a_b%` matches BOTH titles.
        // Escaped, only the title carrying a literal underscore matches.
        $this->actingAs($user)->get(route('loops.index', ['q' => 'a_b']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'a_b'));
    }

    /**
     * SQLite (this suite's driver) tolerates `ESCAPE '\'` as raw SQL text, but
     * under MySQL's default sql_mode a backslash inside a string literal is
     * itself an escape character, so that quoted literal never closes and the
     * query 500s in production. No SQLite-backed execution test can see that
     * failure, so this pins the property structurally: the escape character
     * must be a bound parameter (`ESCAPE ?`), never embedded as a quoted
     * literal (`ESCAPE '...'`), in the SQL actually sent to the driver.
     */
    public function test_the_search_binds_the_escape_character_rather_than_embedding_it(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'anything']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->get(route('loops.index', ['q' => 'anything']))->assertOk();

        $searchQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'LIKE'));

        $this->assertNotEmpty($searchQueries, 'Expected the request to run a LIKE search query.');

        foreach ($searchQueries as $sql) {
            $this->assertStringContainsString('ESCAPE ?', $sql);
            $this->assertStringNotContainsString("ESCAPE '", $sql);
        }
    }

    /**
     * `status` two lines above already tolerates garbage rather than
     * erroring, and `ExportController` makes the same promise for `format`.
     * `q` should keep it too: `?q[]=x` hands `query('q', '')` an array, and
     * `(string) $array` raises a PHP warning that Laravel's error handler
     * turns into an `ErrorException` — a 500 from a hand-edited URL.
     */
    public function test_an_array_shaped_q_is_ignored_rather_than_erroring(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'running']);

        $this->actingAs($user)->get(route('loops.index', ['q' => ['x']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.q', null)
                ->has('intentions', 1));
    }

    public function test_an_unknown_status_is_ignored_rather_than_erroring(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'running']);

        $this->actingAs($user)->get(route('loops.index', ['status' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', null)
                ->has('intentions', 1));
    }

    public function test_another_users_loops_are_never_searchable(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        Intention::factory()->for($theirs)->create(['title' => 'their kettle loop', 'cue' => 'the kettle clicks off']);

        $this->actingAs($mine)->get(route('loops.index', ['q' => 'kettle']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 0));
    }
}
