<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\GetLatestSummary;
use App\Ai\Tools\GetLoopDetail;
use App\Ai\Tools\ListLoops;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class ReadToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_loops_returns_only_the_users_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'Mine']);
        Intention::factory()->create(['title' => 'Theirs']);
        $this->actingAs($user);

        $result = (string) $this->app->make(ListLoops::class)->handle(new ToolRequest([]));

        $this->assertStringContainsString('Mine', $result);
        $this->assertStringNotContainsString('Theirs', $result);
    }

    public function test_loop_detail_includes_anatomy_strategy_and_recent_logs(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create(['cue' => 'Alarm at 6am']);
        $intention->strategies()->create([
            'version' => 1, 'status' => 'active', 'intervention_point' => 'cue',
            'approach' => 'Shoes by the door', 'change_reason' => 'initial',
        ]);
        $action = Action::factory()->for($intention)->create();
        ActionLog::factory()->for($action)->for($user)->failed('overslept')->create();
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertStringContainsString('Alarm at 6am', $result);
        $this->assertStringContainsString('Shoes by the door', $result);
        $this->assertStringContainsString('overslept', $result);
    }

    public function test_loop_detail_refuses_another_users_loop(): void
    {
        $other = Intention::factory()->create();
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => $other->id]));

        $this->assertStringContainsString('not found', strtolower($result));
    }

    public function test_loop_detail_handles_a_missing_id_argument(): void
    {
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest([]));

        $this->assertSame('Loop not found.', $result);
    }

    public function test_loop_detail_handles_an_array_id_argument(): void
    {
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => [1, 2]]));

        $this->assertSame('Loop not found.', $result);
    }

    public function test_latest_summary_returns_the_loops_newest_summary(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Summary::factory()->for($intention)->for($user)->create(['content' => 'Older summary.']);
        Summary::factory()->for($intention)->for($user)->create(['content' => 'Newer summary.']);
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertStringContainsString('Newer summary.', $result);
        $this->assertStringNotContainsString('Older summary.', $result);
    }

    public function test_latest_summary_handles_a_missing_id_argument(): void
    {
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest([]));

        $this->assertSame('Loop not found.', $result);
    }

    public function test_read_tools_return_not_found_with_no_authenticated_user(): void
    {
        $intention = Intention::factory()->create();

        $listResult = (string) $this->app->make(ListLoops::class)
            ->handle(new ToolRequest([]));

        $detailResult = (string) $this->app->make(GetLoopDetail::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $summaryResult = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        // All three must return a string without throwing.
        $this->assertIsString($listResult);
        $this->assertStringContainsString('not found', strtolower($detailResult));
        $this->assertStringContainsString('not found', strtolower($summaryResult));
    }

    public function test_list_loops_reports_when_the_user_has_no_loops(): void
    {
        $this->actingAs(User::factory()->create());

        $result = (string) $this->app->make(ListLoops::class)->handle(new ToolRequest([]));

        $this->assertStringContainsString('No loops yet.', $result);
    }

    public function test_latest_summary_reports_when_the_loop_has_none(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertSame('No summary yet for this loop.', $result);
    }
}
