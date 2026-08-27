<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\UpdateLoopTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The chain as first written is a hypothesis, and the craving is the part most
 * often wrong. This is how it gets corrected without the user opening the app.
 */
class UpdateLoopToolTest extends TestCase
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

    private function loop(User $user, string $status = Intention::STATUS_ACTIVE): Intention
    {
        return Intention::factory()->for($user)->create([
            'title' => 'Eating to 80%',
            'status' => $status,
            'cue' => 'Plate empty, food left in the pan',
            'craving' => 'The taste is still there',
            'response' => 'Serve a second plate',
            'reward' => 'A few more minutes of the taste',
        ]);
    }

    public function test_it_corrects_the_craving(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        $response = PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'craving' => 'Stopping while food is left feels like waste',
        ]);

        $response->assertOk();

        $this->assertSame(
            'Stopping while food is left feels like waste',
            $this->payload($response)['loop']['craving'],
        );
        $this->assertSame('Stopping while food is left feels like waste', $loop->fresh()->craving);
    }

    public function test_a_partial_call_leaves_every_other_field_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'craving' => 'Stopping while food is left feels like waste',
        ])->assertOk();

        $fresh = $loop->fresh();

        $this->assertSame('Eating to 80%', $fresh->title);
        $this->assertSame('Plate empty, food left in the pan', $fresh->cue);
        $this->assertSame('Serve a second plate', $fresh->response);
        $this->assertSame('A few more minutes of the taste', $fresh->reward);
    }

    public function test_activating_a_paused_loop_re_arms_its_stale_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, Intention::STATUS_PAUSED);
        $strategy = Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_ACTIVE]);
        $stale = now()->subDays(4)->setTime(19, 0);
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'status' => Action::STATUS_ACTIVE,
            'recurrence' => 'daily',
            'series_started_at' => $stale,
        ]);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'status' => Intention::STATUS_ACTIVE,
        ])->assertOk();

        // The behaviour UpdateIntention already owns: a loop that sat paused
        // must not fire every missed slot the moment it goes live.
        $this->assertTrue($action->fresh()->series_started_at->isFuture());
    }

    public function test_it_can_pause_and_archive_a_loop(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'status' => Intention::STATUS_PAUSED,
        ])->assertOk();

        $this->assertSame(Intention::STATUS_PAUSED, $loop->fresh()->status);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'status' => Intention::STATUS_ARCHIVED,
        ])->assertOk();

        $this->assertSame(Intention::STATUS_ARCHIVED, $loop->fresh()->status);
    }

    /**
     * A loop is a behaviour under change, not a task. "Completed" is exactly
     * the finish-line framing the notebook avoids.
     */
    public function test_it_refuses_to_mark_a_loop_completed(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'status' => Intention::STATUS_COMPLETED,
        ])->assertHasErrors();

        $this->assertSame(Intention::STATUS_ACTIVE, $loop->fresh()->status);
    }

    public function test_it_rejects_a_call_that_changes_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)
            ->tool(UpdateLoopTool::class, ['intention_id' => $loop->id])
            ->assertHasErrors();
    }

    public function test_it_rejects_a_blank_chain_field(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'cue' => '   ',
        ])->assertHasErrors();

        $this->assertSame('Plate empty, food left in the pan', $loop->fresh()->cue);
    }

    public function test_it_rejects_another_users_loop(): void
    {
        $loop = $this->loop(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(UpdateLoopTool::class, ['intention_id' => $loop->id, 'craving' => 'Hijacked'])
            ->assertHasErrors(['Not found.']);

        $this->assertSame('The taste is still there', $loop->fresh()->craving);
    }
}
