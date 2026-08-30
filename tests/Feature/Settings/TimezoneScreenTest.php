<?php

namespace Tests\Feature\Settings;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimezoneScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The screen itself renders through app.blade.php's @vite — this
        // suite must stay green on a fresh clone that has never run
        // `npm run build`.
        $this->withoutVite();
    }

    public function test_the_screen_shows_the_stored_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);

        $this->actingAs($user)->get(route('timezone.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/timezone')
                ->where('timezone', 'Australia/Brisbane')
                ->has('timezones'));
    }

    public function test_changing_the_timezone_re_anchors_future_occurrences(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(2),
        ]);
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDay()]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Europe/London'])
            ->assertRedirect();

        $this->assertSame('Europe/London', $user->refresh()->timezone);
        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_an_unknown_identifier_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Australia/Brisbane', $user->refresh()->timezone);
    }

    /**
     * `timezone_identifiers_list()` excludes backward-compat aliases —
     * `US/Pacific` has no entry in it on this PHP build. Without pushing the
     * user's own stored zone into the option list, the controlled <select>
     * would have no matching <option>, the browser would fall back to its
     * first entry, and Inertia's <Form> would serialise that on Save —
     * silently rewriting an untouched user's zone to something else.
     */
    public function test_a_stored_legacy_alias_stays_selectable_and_survives_an_untouched_save(): void
    {
        $this->assertNotContains(
            'US/Pacific',
            timezone_identifiers_list(),
            'This pin assumes US/Pacific is a legacy alias on the running PHP build; if that ever changes, pick another alias.'
        );

        $user = User::factory()->create(['timezone' => 'US/Pacific']);

        $this->actingAs($user)->get(route('timezone.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/timezone')
                ->where('timezone', 'US/Pacific')
                ->where('timezones', fn ($timezones) => collect($timezones)->contains('US/Pacific')));

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'US/Pacific'])
            ->assertRedirect();

        $this->assertSame('US/Pacific', $user->refresh()->timezone);
    }

    /**
     * A future-dated daily action is not stale — it is the schedule the user
     * already has. nextAfter() always advances at least once, so feeding it
     * a future anchor would jump a whole cadence forward instead of leaving
     * it alone.
     */
    public function test_a_future_anchored_daily_action_is_left_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->addWeek(),
        ]);
        $originalAnchor = $action->series_started_at;
        $future = $action->occurrences()->create(['scheduled_for' => now()->addWeek()]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Europe/London'])
            ->assertRedirect();

        $this->assertDatabaseHas('occurrences', ['id' => $future->id]);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($originalAnchor));
    }

    /**
     * For a one-off, nextAfter() returns null and control falls to
     * firstOccurrence(), which places it today-or-tomorrow at that clock
     * time — yanking a future one-off to within 24 hours if it is not
     * filtered out first.
     */
    public function test_a_future_anchored_one_off_action_is_left_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => null,
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->addWeeks(3),
        ]);
        $originalAnchor = $action->series_started_at;
        $future = $action->occurrences()->create(['scheduled_for' => now()->addWeeks(3)]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Europe/London'])
            ->assertRedirect();

        $this->assertDatabaseHas('occurrences', ['id' => $future->id]);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($originalAnchor));
    }

    /**
     * Re-submitting the already-stored zone — e.g. pressing Save without
     * touching the control — has nothing to move. Uses a genuinely stale
     * action so this pins the no-op guard itself rather than piggy-backing
     * on the staleness filter.
     */
    public function test_a_no_op_save_does_not_touch_anything(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(2),
        ]);
        $originalAnchor = $action->series_started_at;
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDay()]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Australia/Brisbane'])
            ->assertRedirect();

        $this->assertDatabaseHas('occurrences', ['id' => $future->id]);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($originalAnchor));
    }

    /**
     * A user who has never set a timezone stores null, and the app default
     * (config('app.timezone')) is what the edit screen shows and what
     * re-submitting unchanged sends back. previousTimezone has to be
     * computed as that default, not left null, so this still lands on the
     * no-op guard — otherwise it would always disagree with the submitted
     * value and run ReanchorsSeries::forActions() on a save that changed
     * nothing, advancing series_started_at and deleting unlogged future
     * occurrences.
     */
    public function test_a_null_stored_timezone_saving_the_app_default_leaves_the_schedule_alone(): void
    {
        $user = User::factory()->create(['timezone' => null]);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(2),
        ]);
        $originalAnchor = $action->series_started_at;
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDay()]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => (string) config('app.timezone')])
            ->assertRedirect();

        $this->assertSame((string) config('app.timezone'), $user->refresh()->timezone);
        $this->assertDatabaseHas('occurrences', ['id' => $future->id]);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($originalAnchor));
    }
}
