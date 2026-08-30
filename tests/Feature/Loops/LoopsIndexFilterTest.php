<?php

namespace Tests\Feature\Loops;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function titles(AssertableInertia $page): array
    {
        return array_column($page->toArray()['props']['intentions'], 'title');
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
        Intention::factory()->for($user)->create(['title' => 'read before bed']);

        // A bare `%` would wildcard and match both rows.
        $this->actingAs($user)->get(route('loops.index', ['q' => '100%']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 1));
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
