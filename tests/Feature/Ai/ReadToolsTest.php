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

    public function test_latest_summary_returns_the_loops_newest_summary(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Summary::factory()->for($intention)->for($user)->create(['content' => 'Mornings fail.']);
        $this->actingAs($user);

        $result = (string) $this->app->make(GetLatestSummary::class)
            ->handle(new ToolRequest(['intention_id' => $intention->id]));

        $this->assertStringContainsString('Mornings fail.', $result);
    }
}
