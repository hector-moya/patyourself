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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `/loops/{loop}/record` — what happened, as against what the loop is.
 *
 * Several of these moved here from IntentionScreensTest when the outcome
 * history, the notes and the reflection left the loop detail page. The
 * assertions are the same facts about the same data; only the route changed.
 */
class LoopRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function url(Intention $loop, string $query = ''): string
    {
        return "/loops/{$loop->id}/record{$query}";
    }

    public function test_guests_are_redirected(): void
    {
        $loop = Intention::factory()->create();

        $this->get($this->url($loop))->assertRedirect('/login');
    }

    public function test_a_stranger_is_refused(): void
    {
        $loop = Intention::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get($this->url($loop))
            ->assertForbidden();
    }

    public function test_it_names_the_loop_and_the_running_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['title' => 'Eating to 80%']);
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/record')
                ->where('loop.title', 'Eating to 80%')
                ->where('loop.version', 2)
                ->etc()
            );
    }

    /**
     * The occurrence entity's whole point, rendered: an entry sits in the
     * chronology where the occasion happened, not where it was typed.
     *
     * Moved from IntentionScreensTest with the history itself.
     */
    public function test_the_chronology_is_ordered_by_the_occasion(): void
    {
        $this->travelTo('2026-08-26 21:00:00');

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create(['title' => 'Dinner']);

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
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 2)
                ->where('entries.0.occurred_at', '2026-08-25T19:00:00+00:00')
                ->where('entries.1.occurred_at', '2026-08-20T19:00:00+00:00')
                ->where('occasions_total', 2)
                ->where('showing_all_history', false)
                ->etc()
            );
    }

    /**
     * An occasion answered the next morning is a different kind of record from
     * one answered as it happened, and the row says so.
     */
    public function test_an_occasion_answered_on_a_later_day_says_so(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => '2026-08-20 19:00:00',
        ]);
        ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'logged_at' => '2026-08-21 08:00:00',
        ]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.logged_later', true)
                ->etc()
            );
    }

    /** Moved from IntentionScreensTest. Unedited on the way in, unedited on the way out. */
    public function test_a_reason_is_returned_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();
        $reason = "  didn't Think about it AT ALL.  ";

        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.reason', $reason)
                ->where('reasons.0.reason', $reason)
                ->etc()
            );
    }

    /** Moved from IntentionScreensTest — the same `?history=all` contract. */
    public function test_it_shows_recent_history_by_default_and_all_on_request(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        ActionLog::factory()->count(35)->for($action)->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 30)
                ->where('occasions_total', 35)
                ->etc()
            );

        $this->actingAs($user)
            ->get($this->url($loop, '?history=all'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 35)
                ->where('showing_all_history', true)
                ->etc()
            );
    }

    /**
     * Notes ride the same timeline as the occasions, placed by date. A note is
     * almost always about the days around it, and filing it in a separate list
     * from the days it describes loses exactly that.
     */
    public function test_notes_are_interleaved_into_the_chronology_by_date(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

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
                'logged_at' => $occasion,
            ]);
        }

        Note::factory()->for($loop)->create([
            'body' => 'Between the two',
            'created_at' => '2026-08-22 10:00:00',
        ]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 3)
                ->where('entries.0.kind', 'occasion')
                ->where('entries.1.kind', 'note')
                ->where('entries.1.body', 'Between the two')
                ->where('entries.2.kind', 'occasion')
                ->etc()
            );
    }

    /** Moved from IntentionScreensTest with the reflection. */
    public function test_it_carries_the_reflection_with_its_provenance(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => 'Lunch is where it goes.',
            'window_start' => '2026-08-13 00:00:00',
            'window_end' => '2026-08-27 00:00:00',
            'events_count' => 28,
        ]);

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reflection.content', 'Lunch is where it goes.')
                ->where('reflection.events_count', 28)
                ->etc()
            );
    }

    public function test_it_carries_a_null_reflection_when_none_is_written(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reflection', null)
                ->etc()
            );
    }

    /**
     * Moved here from IntentionScreensTest (which had it from ProgressShowTest)
     * and the reason it exists still holds: the writer and the reader have to
     * agree. `latestSummary()` filters to intention scope, so a WriteReflection
     * writing the wrong scope would be silently ignored and the screen would go
     * on showing its empty state while the record filled up. Every other
     * reflection test seeds the row by factory, which cannot catch that.
     */
    public function test_a_reflection_written_by_the_app_is_what_the_record_renders(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        app(WriteReflection::class)->handle($loop, 'Dinner holds. Lunch is where it goes.');

        $this->actingAs($user)
            ->get($this->url($loop))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reflection.content', 'Dinner holds. Lunch is where it goes.')
                ->etc()
            );
    }

    /**
     * A loop with one occasion of each outcome, for the cut assertions below.
     * Two decided, one skipped.
     */
    private function loopWithContext(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        $log = function (string $outcome, string $at, ?array $fields) use ($action, $user): void {
            $occurrence = Occurrence::factory()->create([
                'action_id' => $action->id,
                'scheduled_for' => $at,
            ]);
            ActionLog::factory()->create([
                'action_id' => $action->id,
                'occurrence_id' => $occurrence->id,
                'user_id' => $user->id,
                'outcome' => $outcome,
                'logged_at' => $at,
                'context_fields' => $fields,
            ]);
        };

        // Monday, held, full context.
        $log(ActionLog::OUTCOME_COMPLETED, '2026-08-24 19:00:00', [
            'place' => 'Bedroom',
            'with_others' => false,
            'preceded_by' => 'getting into bed',
        ]);
        // Friday, did not hold, no context recorded at all.
        $log(ActionLog::OUTCOME_FAILED, '2026-08-28 19:00:00', null);
        // Saturday, never happened — decides nothing, counts nowhere.
        $log(ActionLog::OUTCOME_SKIPPED, '2026-08-29 19:00:00', [
            'place' => 'Away',
            'with_others' => true,
            'preceded_by' => 'travelling',
        ]);

        return $loop;
    }

    /**
     * Skips decide nothing, so they are in no cut's denominator anywhere.
     *
     * The skipped occasion above carries a full set of context fields — a
     * "Away" place, "with others", a preceded_by — precisely so that an
     * implementation that forgot to exclude it would show those groups and
     * fail here. It is counted once, in the totals, and nowhere else.
     */
    public function test_a_skipped_occasion_appears_in_no_cut(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithContext($user);

        $response = $this->actingAs($user)->get($this->url($loop))->assertOk();

        $cuts = $response->viewData('page')['props']['cuts'];
        $names = collect($cuts)->flatMap(
            fn (array $cut): array => array_column($cut['rows'], 'name')
        );

        $this->assertNotContains('Away', $names);
        $this->assertNotContains('travelling', $names);
        $this->assertNotContains('Saturday', $names);
    }

    /**
     * Every cut sums to the same decided count.
     *
     * This is the invariant the whole page rests on: four readings of one
     * record that disagree about how much record there is are four readings
     * nobody can trust. The "no context recorded" occasion is what makes it a
     * real test — dropping it is the natural implementation, and it breaks
     * exactly this.
     */
    public function test_every_cut_sums_to_the_same_decided_count(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithContext($user);

        $response = $this->actingAs($user)->get($this->url($loop))->assertOk();
        $cuts = $response->viewData('page')['props']['cuts'];

        $this->assertNotEmpty($cuts);

        foreach ($cuts as $cut) {
            $this->assertSame(
                2,
                array_sum(array_column($cut['rows'], 'decided')),
                "The '{$cut['label']}' cut does not cover every decided occasion.",
            );
        }
    }

    public function test_context_that_was_never_recorded_gets_its_own_row(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithContext($user);

        $response = $this->actingAs($user)->get($this->url($loop))->assertOk();
        $place = collect($response->viewData('page')['props']['cuts'])
            ->firstWhere('key', 'place');

        $this->assertContains('Not recorded', array_column($place['rows'], 'name'));
    }

    /** The day cut is derived from when the occasion happened, not when it was typed. */
    public function test_the_day_cut_reads_the_occasion_date(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithContext($user);

        $response = $this->actingAs($user)->get($this->url($loop))->assertOk();
        $day = collect($response->viewData('page')['props']['cuts'])
            ->firstWhere('key', 'day');

        $rows = collect($day['rows'])->keyBy('name');

        $this->assertSame(1, $rows['Monday']['held']);
        $this->assertSame(1, $rows['Monday']['decided']);
        $this->assertSame(0, $rows['Friday']['held']);
        $this->assertSame(1, $rows['Friday']['decided']);
    }
}
