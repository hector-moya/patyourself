<?php

namespace Tests\Feature\Actions;

use App\Actions\PersistAuthoredIntention;
use App\Actions\RescheduleAction;
use App\Actions\StartExperiment;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredIntention;
use App\Services\Authoring\AuthoredStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `series_started_at` is where an action's current cadence began, and it is
 * what materialisation walks forward from. `scheduled_for` cannot do that job
 * because it rolls forward on every log, so every place that writes an action
 * has to set the anchor too — otherwise that action's occasions never
 * materialise and it silently drops out of every check-in.
 */
class SeriesAnchorTest extends TestCase
{
    use RefreshDatabase;

    private function authoredLoop(?AuthoredAction $action): AuthoredIntention
    {
        return new AuthoredIntention(
            title: 'Eating to 80%',
            description: null,
            type: Intention::TYPE_BREAK,
            cue: 'Plate is empty and there is food left in the pan',
            craving: 'The taste is still there and stopping feels like waste',
            response: 'Serve a second plate',
            reward: 'A few more minutes of the taste',
            confidence: null,
            tags: [],
            strategy: new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CUE,
                approach: 'Put the pan back on the stove before sitting down',
            ),
            model: 'none',
            action: $action,
        );
    }

    public function test_a_loop_created_through_the_authoring_path_anchors_its_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $loop = app(PersistAuthoredIntention::class)->handle($user, $this->authoredLoop(
            new AuthoredAction(
                title: 'Put the pan back on the stove before sitting down',
                description: null,
                kind: 'clock',
                time: '19:00',
                recurrence: 'daily',
                anchor: null,
            ),
        ));

        $action = $loop->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->equalTo($action->scheduled_for));
    }

    public function test_a_new_experiment_anchors_the_action_it_proposes(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        Action::factory()->for($loop)->for($current)->create(['status' => Action::STATUS_PENDING]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            null,
            null,
            new AuthoredAction(
                title: 'Name the craving out loud before serving',
                description: null,
                kind: 'clock',
                time: '19:00',
                recurrence: 'daily',
                anchor: null,
            ),
        );

        $action = $next->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->equalTo($action->scheduled_for));
    }

    public function test_an_experiment_that_inherits_the_prior_cadence_still_anchors(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        $priorSlot = now()->addHours(3)->startOfSecond();
        Action::factory()->for($loop)->for($current)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => $priorSlot,
            'series_started_at' => $priorSlot,
            'recurrence' => 'daily',
        ]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        );

        $action = $next->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->equalTo($priorSlot));
    }

    public function test_rescheduling_to_a_new_time_re_anchors_the_series(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeek()->startOfSecond();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['scheduled_for' => $anchor, 'series_started_at' => $anchor, 'recurrence' => 'daily']);

        app(RescheduleAction::class)->handle($action, 'clock', '19:30', 'daily', null, 'UTC');

        $fresh = $action->fresh();

        $this->assertFalse($fresh->series_started_at->equalTo($anchor));
        $this->assertTrue($fresh->series_started_at->equalTo($fresh->scheduled_for));
    }

    public function test_turning_an_action_cue_anchored_clears_the_series_anchor(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeek()->startOfSecond();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['scheduled_for' => $anchor, 'series_started_at' => $anchor, 'recurrence' => 'daily']);

        app(RescheduleAction::class)->handle($action, 'anchored', null, null, 'after dinner', 'UTC');

        $fresh = $action->fresh();

        $this->assertNull($fresh->scheduled_for);
        $this->assertNull($fresh->series_started_at);
    }
}
