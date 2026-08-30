<?php

namespace Tests\Feature;

use App\Actions\WriteReflection;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The loops-list and loop-detail screen routing — each an Inertia page
 * rendered in the shared CoachLayout shell. Verifies the controllers hand
 * each screen the right component + props and gate detail on ownership.
 */
class IntentionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Screen content ships in Tasks 18–20; the page render only needs
        // Inertia's component + props, not a built Vite manifest.
        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_the_loops_list(): void
    {
        $this->get('/loops')->assertRedirect('/login');
    }

    public function test_loops_list_renders_only_the_users_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create();
        Intention::factory()->create();

        $this->actingAs($user)
            ->get('/loops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/index')
                ->has('intentions', 2)
            );
    }

    public function test_loops_list_surfaces_active_loops_first(): void
    {
        $user = User::factory()->create();
        $archived = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ARCHIVED]);
        $active = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get('/loops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/index')
                ->where('intentions.0.id', $active->id)
                ->where('intentions.1.id', $archived->id)
            );
    }

    public function test_loop_detail_renders_anatomy_and_the_strategy_timeline(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $v1 = $intention->strategies()->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay the book on the pillow',
            'rationale' => 'Make the cue impossible to miss',
            'change_reason' => Strategy::REASON_INITIAL,
            'superseded_reason' => 'Kept forgetting once in bed',
        ]);
        $intention->strategies()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Read a single page, no more',
            'rationale' => 'Shrink the response',
            'change_reason' => Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            'parent_strategy_id' => $v1->id,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/show')
                ->where('intention.id', $intention->id)
                // The active strategy drives which anatomy stage is highlighted.
                ->where('intention.strategy.intervention_point', Strategy::POINT_RESPONSE)
                // Timeline reads oldest version first.
                ->has('strategies', 2)
                ->where('strategies.0.version', 1)
                ->where('strategies.1.version', 2)
            );
    }

    /**
     * Zero is meaningful: it is the difference between a version that failed
     * and one that was never tested. Without the count the timeline cannot
     * tell them apart.
     */
    public function test_loop_detail_counts_the_outcomes_recorded_under_each_version(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $tested = Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
        ]);
        Strategy::factory()->for($intention)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        ActionLog::factory()->count(3)
            ->for(Action::factory()->for($intention)->for($tested))
            ->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_FAILED, 'reason' => 'Kept going past full']);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('strategies.0.outcomes_recorded', 3)
                ->where('strategies.1.outcomes_recorded', 0)
                ->etc()
            );
    }

    /**
     * The occurrence entity's whole point, rendered: an entry sits in the
     * history where the occasion happened, not where it was typed.
     */
    public function test_loop_detail_orders_the_outcome_history_by_the_occasion(): void
    {
        $this->travelTo('2026-08-26 21:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create(['title' => 'Dinner']);

        // Logged in the same check-in, minutes apart, but describing occasions
        // five days apart.
        foreach (['2026-08-20 19:00:00', '2026-08-25 19:00:00'] as $occasion) {
            $occurrence = Occurrence::factory()->create([
                'action_id' => $action->id,
                'scheduled_for' => $occasion,
            ]);
            ActionLog::factory()->create([
                'action_id' => $action->id,
                'occurrence_id' => $occurrence->id,
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 2)
                ->where('outcomes.0.occurred_at', '2026-08-25T19:00:00+00:00')
                ->where('outcomes.1.occurred_at', '2026-08-20T19:00:00+00:00')
                ->where('outcomes_total', 2)
                ->where('showing_all_history', false)
                ->etc()
            );
    }

    public function test_loop_detail_returns_the_reason_verbatim(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create();
        $reason = "  didn't Think about it AT ALL.  ";

        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('outcomes.0.reason', $reason)
                ->etc()
            );
    }

    public function test_loop_detail_shows_recent_history_by_default_and_all_on_request(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create();

        ActionLog::factory()->count(35)->for($action)->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 30)
                ->where('outcomes_total', 35)
                ->etc()
            );

        $this->actingAs($user)
            ->get("/loops/{$intention->id}?history=all")
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 35)
                ->where('showing_all_history', true)
                ->etc()
            );
    }

    public function test_loop_detail_carries_its_notes_newest_first(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Note::factory()->for($intention)->create([
            'body' => 'Older observation',
            'noted_at' => '2026-08-20 10:00:00',
        ]);
        Note::factory()->for($intention)->create([
            'body' => 'Newer observation',
            'noted_at' => '2026-08-25 10:00:00',
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('notes', 2)
                ->where('notes.0.body', 'Newer observation')
                ->where('notes.1.body', 'Older observation')
                ->etc()
            );
    }

    /**
     * The action layer's raw material: live actions only, with the scheduling
     * fields passed through as-is rather than pre-formatted into a cadence
     * string — so the client applies the same null-safe cadence rules the
     * active-action label already established, instead of a second formatter
     * that can drift from it (or, as here, dangle a "daily at " with nothing
     * after it when there is no upcoming occurrence to report).
     */
    public function test_loop_detail_carries_its_live_actions_with_raw_scheduling_fields(): void
    {
        $this->travelTo('2026-08-26 12:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $clockAction = Action::factory()->for($intention)->create([
            'title' => 'Weigh in',
            'recurrence' => 'daily',
        ]);
        Occurrence::factory()->create([
            'action_id' => $clockAction->id,
            'scheduled_for' => '2026-08-26 19:00:00',
        ]);

        $anchoredAction = Action::factory()->anchored()->for($intention)->create([
            'title' => 'Stretch',
        ]);

        // Retired, not deleted — but the action layer only lists what is
        // still live.
        Action::factory()->archived()->for($intention)->create();

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions', 2)
                ->where('actions.0.id', $clockAction->id)
                ->where('actions.0.title', 'Weigh in')
                ->where('actions.0.recurrence', 'daily')
                ->where('actions.0.schedule_kind', 'clock')
                ->where('actions.0.anchor', null)
                ->where('actions.0.next_occurrence_at', '2026-08-26T19:00:00+00:00')
                ->where('actions.1.id', $anchoredAction->id)
                ->where('actions.1.title', 'Stretch')
                ->where('actions.1.schedule_kind', 'anchored')
                ->where('actions.1.anchor', 'after brushing my teeth')
                ->where('actions.1.recurrence', null)
                ->etc()
            );
    }

    /**
     * The defect this task fixed: a clock action with a recurrence but no
     * occurrence left in today's grid must serialize `next_occurrence_at` as
     * null, not be pre-formatted server-side into a dangling "daily at ".
     */
    public function test_loop_detail_carries_a_null_next_occurrence_when_none_is_left_today(): void
    {
        $this->travelTo('2026-08-26 23:55:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $action = Action::factory()->for($intention)->create([
            'title' => 'Weigh in',
            'recurrence' => 'daily',
        ]);
        // The only occurrence today already passed.
        Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => '2026-08-26 07:00:00',
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.0.recurrence', 'daily')
                ->where('actions.0.next_occurrence_at', null)
                ->etc()
            );
    }

    public function test_loop_detail_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/loops/{$intention->id}")
            ->assertForbidden();
    }

    /**
     * The current experiment's own record, separate from the loop's lifetime.
     * Without it a fresh intervention inherits the previous version's evidence
     * and reads as though it had earned it.
     */
    public function test_loop_detail_carries_the_current_experiments_own_record(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Strategy::factory()->for($intention)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CRAVING,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_version.version', 2)
                ->has('current_version.day_of_experiment')
                ->has('current_version.planned_days')
                ->has('current_version.is_under_review')
                ->has('current_version.completion_rate')
                ->has('current_version.streak.length')
                ->has('current_version.totals.completed')
            );
    }

    /**
     * A loop between experiments is a good state, not an empty one. The prop is
     * null rather than a hollow shape, so the screen can say so plainly.
     */
    public function test_loop_detail_carries_a_null_current_version_between_experiments(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('current_version', null));
    }

    /**
     * The experiment ladder: one entry per version carrying the evidence
     * recorded under it. This is the comparison that says whether the change
     * of strategy did anything.
     */
    public function test_loop_detail_carries_the_experiment_ladder_with_per_version_totals(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $v1 = Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
        ]);
        $v2 = Strategy::factory()->for($intention)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $firstAction = Action::factory()->for($intention)->create(['strategy_id' => $v1->id]);
        $secondAction = Action::factory()->for($intention)->create(['strategy_id' => $v2->id]);

        ActionLog::factory()->for($firstAction)->create(['outcome' => ActionLog::OUTCOME_FAILED]);
        ActionLog::factory()->for($secondAction)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
        ActionLog::factory()->for($secondAction)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('experiments', 2)
                // Each log attributes to the version that was running, through
                // actions.strategy_id — not to whichever version is active now.
                ->where('experiments.0.version', 1)
                ->where('experiments.0.totals.failed', 1)
                ->where('experiments.0.totals.completed', 0)
                ->where('experiments.1.version', 2)
                ->where('experiments.1.totals.completed', 2)
            );
    }

    /**
     * write-reflection records the window and the occasion count from the record
     * rather than from Claude. Dropping them leaves a claim with no provenance.
     */
    public function test_loop_detail_carries_the_reflection_with_its_provenance(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Summary::factory()->create([
            'user_id' => $user->id,
            'intention_id' => $intention->id,
            'content' => 'The craving reads more like hunger than habit.',
            'events_count' => 28,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reflection.content', 'The craving reads more like hunger than habit.')
                ->where('reflection.events_count', 28)
                ->has('reflection.window_start')
                ->has('reflection.window_end')
            );
    }

    public function test_loop_detail_carries_a_null_reflection_when_none_is_written(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reflection', null));
    }

    /**
     * The loops list answers "what am I running" without opening anything, so
     * the embedded summary has to carry the experiment's state, not just its
     * intervention point.
     */
    public function test_loops_list_carries_the_experiment_state_on_each_loop(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Strategy::factory()->for($intention)->create([
            'version' => 3,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get('/loops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intentions.0.strategy.version', 3)
                ->has('intentions.0.strategy.day_of_experiment')
                ->has('intentions.0.strategy.planned_days')
                ->has('intentions.0.strategy.is_under_review')
            );
    }

    /**
     * Progress detail folded into the lab record. The route name survives so
     * nothing that generates the URL breaks, and no bookmark 404s.
     */
    public function test_the_progress_detail_route_redirects_into_the_lab_record(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/progress/{$intention->id}")
            ->assertRedirect("/loops/{$intention->id}");
    }

    /**
     * Ported from ProgressShowTest when the progress detail folded in here. The
     * metrics are asserted by value, not merely by presence — a rate of 100 and
     * a streak of 2 are the difference between wiring the aggregation up and
     * wiring it up correctly.
     */
    public function test_loop_detail_reports_the_current_experiments_rate_and_streak(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
            'title' => 'Morning walk',
        ]);
        $v1 = Strategy::factory()->for($loop)->superseded('kept missing it')->create(['version' => 1]);
        $v2 = Strategy::factory()->for($loop)->restrategized()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'parent_strategy_id' => $v1->id,
        ]);
        $action = Action::factory()->for($loop)->for($v2)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();

        $this->actingAs($user)
            ->get("/loops/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intention.title', 'Morning walk')
                ->where('current_version.completion_rate', 100)
                ->where('current_version.streak.length', 2)
                ->has('strategies', 2)
                ->where('strategies.0.version', 1)
                ->where('strategies.1.version', 2)
            );
    }

    /**
     * Ported from ProgressShowTest, and the reason it existed still holds: the
     * writer and the reader have to agree. `latestSummary()` filters to intention
     * scope, so a WriteReflection writing the wrong scope would be silently
     * ignored and the screen would keep showing its empty state while the record
     * filled up. Every other reflection test seeds the row by factory, which
     * cannot catch that.
     */
    public function test_a_reflection_written_by_the_app_is_what_the_lab_record_renders(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        app(WriteReflection::class)->handle($loop, 'Dinner holds. Lunch is where it goes.');

        $this->actingAs($user)
            ->get("/loops/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reflection.content', 'Dinner holds. Lunch is where it goes.')
            );
    }

    /**
     * Ported from ProgressShowTest. The experiment framing has to survive the
     * resource: a planned length, how far in it is, and whether it is due.
     */
    public function test_loop_detail_serializes_the_experiment_fields_on_each_version(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
            'verdict_note' => 'the cue moved but craving still spikes around 3pm',
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('strategies.0.planned_days', 21)
                ->where('strategies.0.day_of_experiment', 12)
                ->where('strategies.0.is_under_review', false)
                ->where('strategies.0.verdict', null)
                ->where('strategies.0.review_at', '2026-09-22T12:00:00.000000Z')
                ->where('strategies.0.verdict_note', 'the cue moved but craving still spikes around 3pm'));
    }

    /**
     * Ported from ProgressShowTest. An open-ended experiment is a real state —
     * it must serialize as null rather than collapsing to a zero-day run.
     */
    public function test_loop_detail_serializes_an_open_ended_experiment(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => null,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('strategies.0.review_at', null)
                ->where('strategies.0.planned_days', null)
                ->where('strategies.0.is_under_review', false));
    }
}
