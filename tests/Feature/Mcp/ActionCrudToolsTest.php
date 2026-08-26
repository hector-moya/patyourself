<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\AddActionTool;
use App\Mcp\Tools\RemoveActionTool;
use App\Mcp\Tools\UpdateActionTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The tools that unfreeze a loop's action layer — most importantly splitting
 * one action into two (one per meal), which the occurrence model made
 * meaningful and which previously required opening the app.
 */
class ActionCrudToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function loop(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_ACTIVE]);

        return $loop->fresh();
    }

    private function existingAction(User $user): Action
    {
        $anchor = now()->subDays(3)->setTime(19, 0);
        $loop = $this->loop($user);

        return Action::factory()
            ->for($loop)
            ->for($loop->activeStrategy)
            ->create([
                'title' => 'Put the fork down between mouthfuls',
                'recurrence' => 'daily',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);
    }

    public function test_add_action_creates_a_clock_action_and_anchors_its_series(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        $response = PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Put the pan back on the stove before sitting down',
            'kind' => 'clock',
            'time' => '19:00',
            'recurrence' => 'daily',
        ]);

        $response->assertOk();

        $payload = $this->payload($response);
        $action = Action::findOrFail($payload['action_id']);

        $this->assertSame('Put the pan back on the stove before sitting down', $action->title);
        $this->assertTrue($action->series_started_at->equalTo($action->scheduled_for));
        $this->assertSame(1, $payload['strategy_version']);
        $this->assertArrayHasKey('next_occurrence_at', $payload);
        $this->assertArrayNotHasKey('status', $payload);
    }

    public function test_add_action_creates_a_cue_anchored_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Name the craving out loud',
            'kind' => 'anchored',
            'anchor' => 'after serving the first plate',
        ])->assertOk();

        $action = $loop->actions()->firstOrFail();

        $this->assertNull($action->scheduled_for);
        $this->assertSame('after serving the first plate', $action->metadata['anchor']);
    }

    public function test_two_actions_can_share_one_loop_so_each_meal_is_its_own(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        foreach ([['Lunch', '12:30'], ['Dinner', '19:00']] as [$title, $time]) {
            PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
                'intention_id' => $loop->id,
                'title' => $title,
                'kind' => 'clock',
                'time' => $time,
                'recurrence' => 'daily',
            ])->assertOk();
        }

        $this->assertSame(['Lunch', 'Dinner'], $loop->actions()->orderBy('id')->pluck('title')->all());
    }

    public function test_add_action_rejects_a_malformed_time(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Dinner',
            'kind' => 'clock',
            'time' => '25:99',
            'recurrence' => 'daily',
        ])->assertHasErrors();

        $this->assertSame(0, $loop->actions()->count());
    }

    public function test_add_action_requires_a_time_for_a_clock_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Dinner',
            'kind' => 'clock',
        ])->assertHasErrors();

        $this->assertSame(0, $loop->actions()->count());
    }

    public function test_add_action_requires_an_anchor_for_an_anchored_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Name the craving',
            'kind' => 'anchored',
        ])->assertHasErrors();

        $this->assertSame(0, $loop->actions()->count());
    }

    public function test_add_action_rejects_a_loop_with_no_active_version(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(AddActionTool::class, [
            'intention_id' => $loop->id,
            'title' => 'Dinner',
            'kind' => 'clock',
            'time' => '19:00',
            'recurrence' => 'daily',
        ])->assertHasErrors();
    }

    public function test_add_action_rejects_another_users_loop(): void
    {
        $loop = $this->loop(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(AddActionTool::class, [
                'intention_id' => $loop->id,
                'title' => 'Dinner',
                'kind' => 'clock',
                'time' => '19:00',
                'recurrence' => 'daily',
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, $loop->actions()->count());
    }

    public function test_update_action_retitles_without_touching_the_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->existingAction($user);
        $anchor = $action->series_started_at;

        PatYourSelfServer::actingAs($user)->tool(UpdateActionTool::class, [
            'action_id' => $action->id,
            'title' => 'Put the fork down between every mouthful',
        ])->assertOk();

        $fresh = $action->fresh();

        $this->assertSame('Put the fork down between every mouthful', $fresh->title);
        $this->assertTrue($fresh->series_started_at->equalTo($anchor));
        $this->assertSame('daily', $fresh->recurrence);
    }

    public function test_update_action_reschedules_and_re_anchors(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->existingAction($user);
        $anchor = $action->series_started_at;

        PatYourSelfServer::actingAs($user)->tool(UpdateActionTool::class, [
            'action_id' => $action->id,
            'kind' => 'clock',
            'time' => '20:30',
            'recurrence' => 'daily',
        ])->assertOk();

        $fresh = $action->fresh();

        $this->assertFalse($fresh->series_started_at->equalTo($anchor));
        $this->assertTrue($fresh->series_started_at->equalTo($fresh->scheduled_for));
    }

    public function test_rescheduling_keeps_the_occasions_already_materialised(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->existingAction($user);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(2)->setTime(19, 0),
        ]);

        PatYourSelfServer::actingAs($user)->tool(UpdateActionTool::class, [
            'action_id' => $action->id,
            'kind' => 'clock',
            'time' => '20:30',
            'recurrence' => 'daily',
        ])->assertOk();

        $this->assertNotNull($occurrence->fresh());
        $this->assertSame(1, $action->occurrences()->count());
    }

    public function test_update_action_rejects_another_users_action(): void
    {
        $action = $this->existingAction(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(UpdateActionTool::class, ['action_id' => $action->id, 'title' => 'Hijacked'])
            ->assertHasErrors(['Not found.']);

        $this->assertSame('Put the fork down between mouthfuls', $action->fresh()->title);
    }

    public function test_update_action_rejects_a_call_that_changes_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->existingAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(UpdateActionTool::class, ['action_id' => $action->id])
            ->assertHasErrors();
    }

    public function test_remove_action_archives_and_keeps_the_evidence(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->existingAction($user);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDay(),
        ]);
        ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Served the second plate before I sat down',
        ]);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(RemoveActionTool::class, ['action_id' => $action->id]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(Action::STATUS_ARCHIVED, $payload['status']);
        $this->assertSame(1, $payload['occurrences_kept']);
        $this->assertSame(Action::STATUS_ARCHIVED, $action->fresh()->status);
        $this->assertSame(1, ActionLog::where('action_id', $action->id)->count());
        $this->assertSame(
            'Served the second plate before I sat down',
            ActionLog::where('action_id', $action->id)->firstOrFail()->reason,
        );
    }

    public function test_remove_action_rejects_another_users_action(): void
    {
        $action = $this->existingAction(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(RemoveActionTool::class, ['action_id' => $action->id])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }
}
