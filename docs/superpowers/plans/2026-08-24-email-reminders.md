# Email Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver habit cues by email — either as a daily digest at the user's chosen local time, or one email per cue as it fires — with a per-user setting.

**Architecture:** Three preference columns on `users`. `ActionDueNotification` gains a conditional `mail` channel. A new `reminders:digest` command runs every minute and sends each digest user their due-today actions once per local day. Both the digest and the `today-actions` MCP tool read through one extracted `TodaysActions` service so they can never disagree.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, React 19 + Inertia v3, vitest, Amazon SES.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-24-email-reminders-design.md`.
- The `database` notification channel must stay unconditional. Turning email off must never cost the user their in-app cue.
- The three modes are `off`, `digest`, `every_cue`, declared as `User::EMAIL_REMINDERS_*` constants with a `User::EMAIL_REMINDER_MODES` array. Never hardcode the strings elsewhere.
- Default for every user is `digest` at `07:00`.
- The digest fires when the user's local time is **at or past** `digest_time` and nothing has been sent on their local today — never an exact-minute match. This is deliberate: exact matching loses the whole day's digest to one failed run.
- `users` uses a `#[Fillable([...])]` attribute, not a `$fillable` property. New writable columns must be added there or `update()` silently ignores them.
- Settings React pages use `@/components/ui/*` (Button, Input, Label, InputError, Heading) — NOT `@/patyourself/primitives`, which is for the app's own screens.
- Run PHP tests with `php artisan test --compact`; frontend with `npm run test`, `npm run types:check`, `npm run lint:check`. Run `vendor/bin/pint --dirty --format agent` before committing.
- Baseline before this plan: PHP 385 passing, vitest 90 passing.

---

### Task 1: Preference columns on `users`

**Files:**
- Create: `database/migrations/2026_08_24_100000_add_email_reminder_preferences_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Settings/EmailReminderPreferencesTest.php`

**Interfaces:**
- Produces: `User::EMAIL_REMINDERS_OFF = 'off'`, `User::EMAIL_REMINDERS_DIGEST = 'digest'`, `User::EMAIL_REMINDERS_EVERY_CUE = 'every_cue'`, `User::EMAIL_REMINDER_MODES` (array of the three), and the columns `email_reminders`, `digest_time`, `digest_last_sent_on`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailReminderPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_a_seven_am_digest(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::EMAIL_REMINDERS_DIGEST, $user->email_reminders);
        $this->assertSame('07:00', $user->digest_time);
        $this->assertNull($user->digest_last_sent_on);
    }

    public function test_the_preference_columns_are_writable(): void
    {
        $user = User::factory()->create();

        $user->update([
            'email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE,
            'digest_time' => '21:30',
        ]);

        $this->assertSame(User::EMAIL_REMINDERS_EVERY_CUE, $user->fresh()->email_reminders);
        $this->assertSame('21:30', $user->fresh()->digest_time);
    }

    public function test_digest_last_sent_on_casts_to_a_date(): void
    {
        $user = User::factory()->create(['digest_last_sent_on' => '2026-08-24']);

        $this->assertSame('2026-08-24', $user->fresh()->digest_last_sent_on->toDateString());
    }

    public function test_the_mode_list_holds_exactly_the_three_modes(): void
    {
        $this->assertSame(
            ['off', 'digest', 'every_cue'],
            User::EMAIL_REMINDER_MODES,
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Settings/EmailReminderPreferencesTest.php`
Expected: FAIL — `Undefined constant App\Models\User::EMAIL_REMINDERS_DIGEST`.

- [ ] **Step 3: Write the migration**

Follow the local convention (see `database/migrations/*_add_timezone_to_users_table.php`): a comment explaining the column, and `after()` for ordering.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // How the user wants habit cues delivered by email: off, one daily
            // digest, or one email per cue as it fires. Defaults to the
            // low-volume option rather than opting users out — reminders are
            // the product's purpose. See App\Services\Reminders\DigestDispatcher.
            $table->string('email_reminders')->default('digest')->after('timezone');

            // Local HH:MM the daily digest should be sent at, in the user's own
            // timezone.
            $table->string('digest_time', 5)->default('07:00')->after('email_reminders');

            // The user's local date the digest was last sent on; the guard that
            // caps delivery at one per day.
            $table->date('digest_last_sent_on')->nullable()->after('digest_time');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_reminders', 'digest_time', 'digest_last_sent_on']);
        });
    }
};
```

- [ ] **Step 4: Add the constants, fillable entries and cast**

In `app/Models/User.php`, extend the existing `#[Fillable([...])]` attribute above the class to include the two writable columns (leave `digest_last_sent_on` out — only the dispatcher writes it, and it should not be mass-assignable from a form):

```php
#[Fillable(['name', 'email', 'password', 'timezone', 'email_reminders', 'digest_time'])]
```

Add the constants inside the class, near the top, mirroring how `Intention` declares its statuses:

```php
    /** Email cues are off entirely; the in-app inbox still receives them. */
    public const EMAIL_REMINDERS_OFF = 'off';

    /** One email each day at digest_time, listing everything due. */
    public const EMAIL_REMINDERS_DIGEST = 'digest';

    /** One email per cue, at the moment the action fires. */
    public const EMAIL_REMINDERS_EVERY_CUE = 'every_cue';

    /** @var array<int, string> */
    public const EMAIL_REMINDER_MODES = [
        self::EMAIL_REMINDERS_OFF,
        self::EMAIL_REMINDERS_DIGEST,
        self::EMAIL_REMINDERS_EVERY_CUE,
    ];
```

Add the date cast to the existing `casts()` method:

```php
            'digest_last_sent_on' => 'date',
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings/EmailReminderPreferencesTest.php`
Expected: PASS (4 tests).

Then confirm nothing else broke: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/User.php tests/Feature/Settings/EmailReminderPreferencesTest.php
git commit -m "feat(reminders): email reminder preference columns on users"
```

---

### Task 2: Extract the shared due-today query

**Files:**
- Create: `app/Services/Scheduling/TodaysActions.php`
- Modify: `app/Mcp/Tools/TodayActionsTool.php`
- Test: `tests/Feature/Scheduling/TodaysActionsTest.php`

**Interfaces:**
- Produces: `TodaysActions::for(User $user): Collection<int, Action>` — pending actions on the user's active loops that are unscheduled or due by end of the user's local today, ordered by `scheduled_for`, with `intention:id,title` eager-loaded.

**Why:** the digest needs exactly the set `today-actions` returns. Two copies of the query would drift, and the digest would tell the user one thing while Claude told them another.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\TodaysActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodaysActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_returns_actions_due_by_the_end_of_the_users_local_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $today = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => Carbon::parse('2026-08-24 21:30:00'),
        ]);

        Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => Carbon::parse('2026-08-26 09:00:00'),
        ]);

        $actions = app(TodaysActions::class)->for($user);

        $this->assertSame([$today->id], $actions->pluck('id')->all());
    }

    public function test_includes_unscheduled_cue_anchored_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $anchored = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertTrue(app(TodaysActions::class)->for($user)->contains($anchored));
    }

    public function test_excludes_actions_on_paused_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $paused = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        Action::factory()->for($paused)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertCount(0, app(TodaysActions::class)->for($user));
    }

    public function test_never_returns_another_users_actions(): void
    {
        $stranger = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($stranger)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        $this->assertCount(0, app(TodaysActions::class)->for(User::factory()->create()));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Scheduling/TodaysActionsTest.php`
Expected: FAIL — `Target class [App\Services\Scheduling\TodaysActions] does not exist`.

- [ ] **Step 3: Extract the service**

Create `app/Services/Scheduling/TodaysActions.php`, moving the query verbatim out of `TodayActionsTool::handle()`:

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The one definition of "what is due today" for a user: pending actions on
 * active loops that are either cue-anchored or scheduled by the end of the
 * user's local day.
 *
 * Shared so the daily digest email and the today-actions MCP tool can never
 * disagree about what the user owes today.
 */
class TodaysActions
{
    /**
     * @return Collection<int, Action>
     */
    public function for(User $user): Collection
    {
        $timezone = $user->timezone ?? config('app.timezone');
        $endOfToday = Date::now($timezone)->endOfDay()->utc();

        return Action::query()
            ->pending()
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $endOfToday))
            ->with('intention:id,title')
            ->orderBy('scheduled_for')
            ->get();
    }
}
```

Then rewrite `TodayActionsTool::handle()` to call it, keeping the response mapping exactly as it is:

```php
    public function handle(Request $request, TodaysActions $todaysActions): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');

        $actions = $todaysActions->for($user);

        return Response::json($actions->map(fn (Action $action): array => [
            // ... unchanged mapping ...
        ])->values()->all());
    }
```

Remove the now-unused imports from the tool (`Builder`, `Intention`, `Date`) if nothing else uses them.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Scheduling/TodaysActionsTest.php tests/Feature/Mcp/TodayActionsToolTest.php`
Expected: PASS.

**`tests/Feature/Mcp/TodayActionsToolTest.php` must pass completely unmodified.** That is the proof the extraction was faithful. If it fails, the extraction changed behaviour — fix the service, never the tool's test.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scheduling/TodaysActions.php app/Mcp/Tools/TodayActionsTool.php tests/Feature/Scheduling/TodaysActionsTest.php
git commit -m "refactor(scheduling): extract the shared TodaysActions query"
```

---

### Task 3: Per-cue email

**Files:**
- Modify: `app/Notifications/ActionDueNotification.php`
- Test: `tests/Feature/Notifications/ActionDueNotificationTest.php`

**Interfaces:**
- Consumes: `User::EMAIL_REMINDERS_*` from Task 1.
- Produces: `ActionDueNotification` implements `ShouldQueue`; `via()` returns `['database']` plus `'mail'` only for `every_cue`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Notifications;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function action(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->create(['title' => 'Read before bed']))
            ->create(['title' => 'Read ten pages']);
    }

    public function test_emails_the_cue_when_the_user_wants_every_cue(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $channels = (new ActionDueNotification($this->action($user)))->via($user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_does_not_email_the_cue_for_digest_users(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->assertNotContains('mail', (new ActionDueNotification($this->action($user)))->via($user));
    }

    public function test_does_not_email_the_cue_when_reminders_are_off(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_OFF]);

        $this->assertNotContains('mail', (new ActionDueNotification($this->action($user)))->via($user));
    }

    public function test_the_in_app_cue_is_delivered_in_every_mode(): void
    {
        foreach (User::EMAIL_REMINDER_MODES as $mode) {
            $user = User::factory()->create(['email_reminders' => $mode]);

            $this->assertContains(
                'database',
                (new ActionDueNotification($this->action($user)))->via($user),
                "database channel missing for mode [{$mode}]",
            );
        }
    }

    public function test_the_cue_email_names_the_action_and_links_to_the_app(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);
        $action = $this->action($user);

        $mail = (new ActionDueNotification($action))->toMail($user)->render();

        $this->assertStringContainsString('Read ten pages', $mail);
        $this->assertStringContainsString('Read before bed', $mail);
        $this->assertStringContainsString(route('intentions.show', $action->intention_id), $mail);
    }
}
```

The mail's settings link is asserted in Task 5, once the `notifications.edit` route exists. Do not
reference that route here — this task must be green on its own.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Notifications/ActionDueNotificationTest.php`
Expected: FAIL — `via()` returns only `['database']`, so `assertContains('mail', ...)` fails.

- [ ] **Step 3: Add the conditional mail channel**

In `app/Notifications/ActionDueNotification.php`:

```php
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The cue: an action's scheduled moment has arrived (SP2 fired it). Always
 * delivered in-app via the database channel and surfaced in the inbox; also
 * emailed when the user has chosen to hear about every cue.
 *
 * Queued so the SMTP round trip never runs inside the scheduler's minute —
 * actions:fire dispatches this and must stay fast.
 */
class ActionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;
```

(add `use Illuminate\Bus\Queueable;` to the imports)

```php
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->email_reminders === User::EMAIL_REMINDERS_EVERY_CUE) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loop = $this->action->intention;

        return (new MailMessage)
            ->subject($this->action->title)
            ->line("It's time for: {$this->action->title}")
            ->line("Loop: {$loop->title}")
            ->line("Cue: {$loop->cue}")
            ->action('Open PatYourSelf', route('intentions.show', $loop->id))
            ->line('Manage your reminders: '.route('notifications.edit'));
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Notifications/ActionDueNotificationTest.php`
Expected: PASS (the settings-link assertion passes once Task 5 lands; see the note in Step 1).

Then: `php artisan test --compact`
Expected: PASS — in particular `tests/Feature/Notifications/SendDueNotificationTest.php` must still pass. The test queue is `sync`, so queuing the notification does not change observable behaviour there.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/ActionDueNotification.php tests/Feature/Notifications/ActionDueNotificationTest.php
git commit -m "feat(reminders): email each cue when the user opts into every-cue"
```

---

### Task 4: The daily digest

**Files:**
- Create: `app/Notifications/DailyDigestNotification.php`
- Create: `app/Services/Reminders/DigestDispatcher.php`
- Create: `app/Console/Commands/SendReminderDigests.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Reminders/DigestDispatcherTest.php`

**Interfaces:**
- Consumes: `TodaysActions::for()` (Task 2), `User::EMAIL_REMINDERS_*` (Task 1).
- Produces: `DigestDispatcher::dispatchDue(): int` returning the number of digests sent; command signature `reminders:digest`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reminders;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Services\Reminders\DigestDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DigestDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWithDueAction(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ], $attributes));

        Action::factory()
            ->for(Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]))
            ->create(['status' => Action::STATUS_PENDING, 'scheduled_for' => null]);

        return $user;
    }

    public function test_sends_the_digest_once_the_users_local_time_reaches_their_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
        $this->assertSame('2026-08-24', $user->fresh()->digest_last_sent_on->toDateString());
    }

    public function test_does_not_send_before_the_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 06:59:00');

        $user = $this->userWithDueAction();

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($user);
        $this->assertNull($user->fresh()->digest_last_sent_on);
    }

    public function test_still_sends_when_the_run_is_late(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 11:30:00');

        $user = $this->userWithDueAction();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }

    public function test_uses_the_users_own_timezone_not_utc(): void
    {
        Notification::fake();
        // 12:00 UTC is 22:00 in Sydney (past 07:00) and 04:00 in Los Angeles (before it).
        Carbon::setTestNow('2026-08-24 12:00:00');

        $sydney = $this->userWithDueAction(['timezone' => 'Australia/Sydney']);
        $losAngeles = $this->userWithDueAction(['timezone' => 'America/Los_Angeles']);

        app(DigestDispatcher::class)->dispatchDue();

        Notification::assertSentTo($sydney, DailyDigestNotification::class);
        Notification::assertNothingSentTo($losAngeles);
    }

    public function test_does_not_send_twice_in_the_same_local_day(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        app(DigestDispatcher::class)->dispatchDue();

        Carbon::setTestNow('2026-08-24 09:00:00');

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentToTimes($user, DailyDigestNotification::class, 1);
    }

    public function test_sends_again_the_next_day(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();
        app(DigestDispatcher::class)->dispatchDue();

        Carbon::setTestNow('2026-08-25 07:00:00');

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentToTimes($user, DailyDigestNotification::class, 2);
    }

    public function test_skips_users_with_nothing_due_and_does_not_stamp_them(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = User::factory()->create([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ]);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($user);
        $this->assertNull($user->fresh()->digest_last_sent_on);
    }

    public function test_skips_users_who_are_off_or_on_every_cue(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $off = $this->userWithDueAction(['email_reminders' => User::EMAIL_REMINDERS_OFF]);
        $everyCue = $this->userWithDueAction(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($off);
        Notification::assertNothingSentTo($everyCue);
    }

    public function test_the_command_runs_the_dispatcher(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        $this->artisan('reminders:digest')->assertSuccessful();

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Reminders/DigestDispatcherTest.php`
Expected: FAIL — `Target class [App\Services\Reminders\DigestDispatcher] does not exist`.

- [ ] **Step 3: Write the notification, dispatcher and command**

`app/Notifications/DailyDigestNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The daily digest: everything the user owes today, in one email at their
 * chosen local time. Mail only — the in-app inbox already carries each cue
 * individually as it fires.
 */
class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Action>  $actions
     */
    public function __construct(private readonly Collection $actions) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $count = $this->actions->count();

        $mail = (new MailMessage)
            ->subject($count === 1 ? '1 thing today' : "{$count} things today")
            ->line('Here is what you are working on today.');

        foreach ($this->actions as $action) {
            $when = $action->scheduled_for
                ? $action->scheduled_for->timezone($timezone)->format('g:ia')
                : 'when the cue happens';

            $mail->line("• {$action->title} — {$action->intention->title} ({$when})");
        }

        return $mail
            ->action('Open PatYourSelf', route('dashboard'))
            ->line('Manage your reminders: '.route('notifications.edit'));
    }
}
```

`app/Services/Reminders/DigestDispatcher.php`:

```php
<?php

namespace App\Services\Reminders;

use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Services\Scheduling\TodaysActions;
use Illuminate\Support\Facades\Date;

/**
 * Sends each digest subscriber one email a day, at or after their chosen local
 * time, listing what is due today.
 *
 * Deliberately "at or past" rather than an exact minute match: an exact match
 * would stake the whole day's digest on one scheduler minute succeeding, and a
 * missed minute would silently cost the user that day with no error anywhere.
 * The per-local-day stamp is what caps it at one email.
 *
 * Users are isolated from each other by the queue: DailyDigestNotification is
 * ShouldQueue, so notify() only enqueues here and a delivery failure fails that
 * user's job alone rather than aborting the run for everyone behind them.
 */
class DigestDispatcher
{
    public function __construct(private readonly TodaysActions $todaysActions) {}

    /**
     * @return int the number of digests sent
     */
    public function dispatchDue(): int
    {
        $sent = 0;

        User::query()
            ->where('email_reminders', User::EMAIL_REMINDERS_DIGEST)
            ->cursor()
            ->each(function (User $user) use (&$sent): void {
                $localNow = Date::now($user->timezone ?? config('app.timezone'));

                if ($localNow->format('H:i') < $user->digest_time) {
                    return;
                }

                if ($user->digest_last_sent_on?->toDateString() === $localNow->toDateString()) {
                    return;
                }

                $actions = $this->todaysActions->for($user);

                if ($actions->isEmpty()) {
                    return;
                }

                $user->notify(new DailyDigestNotification($actions));

                // Stamped only after a successful dispatch, so a failure retries
                // on the next minute rather than silently skipping the day.
                $user->forceFill(['digest_last_sent_on' => $localNow->toDateString()])->save();

                $sent++;
            });

        return $sent;
    }
}
```

Note `forceFill` — `digest_last_sent_on` is deliberately not in the `#[Fillable]` list, because only this dispatcher should write it.

`app/Console/Commands/SendReminderDigests.php` — mirror `FireDueActions`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Reminders\DigestDispatcher;
use Illuminate\Console\Command;

/**
 * Sends the daily reminder digest to every user whose local digest time has
 * arrived and who has not had one today. The scheduler runs this every minute
 * (see routes/console.php); all logic lives in the dispatcher so it can be
 * feature-tested directly.
 */
class SendReminderDigests extends Command
{
    protected $signature = 'reminders:digest';

    protected $description = 'Send the daily reminder digest to users whose local digest time has arrived';

    public function handle(DigestDispatcher $dispatcher): int
    {
        $sent = $dispatcher->dispatchDue();

        $this->components->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
```

In `routes/console.php`, add the import and the schedule entry beside the existing one:

```php
// The daily digest: every minute, send to any user whose local digest time has
// arrived and who has not had one today. Runs per-minute rather than hourly so
// each user can pick their own time in their own timezone.
Schedule::command(SendReminderDigests::class)->everyMinute()->withoutOverlapping();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Reminders/DigestDispatcherTest.php`
Expected: PASS (10 tests).

Then: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/DailyDigestNotification.php app/Services/Reminders app/Console/Commands/SendReminderDigests.php routes/console.php tests/Feature/Reminders
git commit -m "feat(reminders): daily digest email at the user's local time"
```

---

### Task 5: Notification settings backend

**Files:**
- Create: `app/Http/Controllers/Settings/NotificationsController.php`
- Create: `app/Http/Requests/Settings/NotificationsUpdateRequest.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/NotificationSettingsTest.php`

**Interfaces:**
- Produces: routes `notifications.edit` (`GET settings/notifications`) and `notifications.update` (`PATCH settings/notifications`); Inertia page `settings/notifications`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('notifications.edit'))
            ->assertOk();
    }

    public function test_guests_cannot_reach_it(): void
    {
        $this->get(route('notifications.edit'))->assertRedirect(route('login'));
    }

    public function test_a_user_can_switch_to_every_cue(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->actingAs($user)
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE,
            ])
            ->assertRedirect();

        $this->assertSame(User::EMAIL_REMINDERS_EVERY_CUE, $user->fresh()->email_reminders);
    }

    public function test_a_user_can_set_their_digest_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
                'digest_time' => '21:30',
            ])
            ->assertRedirect();

        $this->assertSame('21:30', $user->fresh()->digest_time);
    }

    public function test_the_digest_time_is_required_for_the_digest_mode(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            ])
            ->assertSessionHasErrors('digest_time');
    }

    public function test_a_malformed_digest_time_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
                'digest_time' => 'half seven',
            ])
            ->assertSessionHasErrors('digest_time');
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), ['email_reminders' => 'carrier pigeon'])
            ->assertSessionHasErrors('email_reminders');
    }

    public function test_both_reminder_emails_link_to_these_settings(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);
        $action = \App\Models\Action::factory()
            ->for(\App\Models\Intention::factory()->for($user))
            ->create();

        $cue = (new \App\Notifications\ActionDueNotification($action))->toMail($user)->render();
        $digest = (new \App\Notifications\DailyDigestNotification(collect([$action])))->toMail($user)->render();

        $this->assertStringContainsString(route('notifications.edit'), $cue);
        $this->assertStringContainsString(route('notifications.edit'), $digest);
    }

    public function test_one_user_cannot_change_anothers_preferences(): void
    {
        $owner = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_OFF,
            ]);

        $this->assertSame(User::EMAIL_REMINDERS_DIGEST, $owner->fresh()->email_reminders);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Settings/NotificationSettingsTest.php`
Expected: FAIL — `Route [notifications.edit] not defined`.

- [ ] **Step 3: Write the request, controller and routes**

`app/Http/Requests/Settings/NotificationsUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email_reminders' => ['required', 'string', Rule::in(User::EMAIL_REMINDER_MODES)],
            'digest_time' => [
                Rule::requiredIf(fn (): bool => $this->input('email_reminders') === User::EMAIL_REMINDERS_DIGEST),
                'nullable',
                'date_format:H:i',
            ],
        ];
    }
}
```

`app/Http/Controllers/Settings/NotificationsController.php` — follow `ProfileController`, including the toast:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationsUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the user wants habit cues delivered by email. The in-app inbox is not
 * configurable here — it always receives every cue.
 */
class NotificationsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'emailReminders' => $user->email_reminders,
            'digestTime' => $user->digest_time,
            'modes' => User::EMAIL_REMINDER_MODES,
        ]);
    }

    public function update(NotificationsUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder settings updated.')]);

        return to_route('notifications.edit');
    }
}
```

In `routes/settings.php`, add the import and both routes inside the existing `Route::middleware(['auth'])` group, beside the profile routes:

```php
    Route::get('settings/notifications', [NotificationsController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationsController::class, 'update'])->name('notifications.update');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Settings/NotificationSettingsTest.php`
Expected: PASS (8 tests).

Then run the whole suite — this is where Task 3's `route('notifications.edit')` assertion starts passing:
Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Settings/NotificationsController.php app/Http/Requests/Settings/NotificationsUpdateRequest.php routes/settings.php tests/Feature/Settings/NotificationSettingsTest.php
git commit -m "feat(reminders): notification settings routes and controller"
```

---

### Task 6: Notification settings page

**Files:**
- Create: `resources/js/pages/settings/notifications.tsx`
- Create: `resources/js/pages/settings/notifications.test.tsx`
- Modify: `resources/js/layouts/settings/layout.tsx`

**Interfaces:**
- Consumes: the `notifications.edit` / `notifications.update` routes from Task 5, and the props `emailReminders`, `digestTime`, `modes`.

- [ ] **Step 1: Write the failing test**

Follow the mocking pattern in `resources/js/pages/progress/show.test.tsx`.

```tsx
import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = {
    url: '/settings/notifications',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Notifications from './notifications';

const modes = ['off', 'digest', 'every_cue'];

describe('Notification settings', () => {
    it('shows the digest time when the digest mode is selected', () => {
        render(
            <Notifications
                emailReminders="digest"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.getByLabelText(/time/i)).toHaveValue('07:00');
    });

    it('hides the digest time for the every-cue mode', () => {
        render(
            <Notifications
                emailReminders="every_cue"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.queryByLabelText(/time/i)).not.toBeInTheDocument();
    });

    it('hides the digest time when reminders are off', () => {
        render(
            <Notifications emailReminders="off" digestTime="07:00" modes={modes} />,
        );

        expect(screen.queryByLabelText(/time/i)).not.toBeInTheDocument();
    });

    it('offers all three modes', () => {
        render(
            <Notifications
                emailReminders="digest"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.getAllByRole('radio')).toHaveLength(3);
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test -- notifications.test`
Expected: FAIL — cannot resolve `./notifications`.

- [ ] **Step 3: Build the page**

Create `resources/js/pages/settings/notifications.tsx`, modelled on `resources/js/pages/settings/profile.tsx`:

- Import `Form` and `Head` from `@inertiajs/react`, and `NotificationsController` from `@/actions/App/Http/Controllers/Settings/NotificationsController`. If that import does not resolve, run `php artisan wayfinder:generate` and read the generated file for the correct export.
- Use `@/components/ui/*` — `Button`, `Input`, `Label`, `InputError` — and `@/components/heading`. Do NOT use `@/patyourself/primitives`; that set is for the app's own screens, not settings.
- Wrap the fields in `<Form {...NotificationsController.update.form()} options={{ preserveScroll: true }}>` with the render-prop `{({ processing, errors }) => (...)}` shape profile.tsx uses.
- Hold the selected mode in `useState` initialised from the `emailReminders` prop, so the time input can show and hide as the user clicks, and render three radios named `email_reminders`.
- Render the `digest_time` `<Input type="time" />` only when the selected mode is `digest`, with a `<Label htmlFor="digest_time">` containing the word "time" so the test's `getByLabelText(/time/i)` finds it.
- Give each mode a one-line description so the choice is self-explanatory — particularly that turning email off does not affect the in-app inbox.
- Add the breadcrumb export at the bottom, mirroring profile.tsx:

```tsx
Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: edit(),
        },
    ],
};
```

(importing `edit` from `@/routes/notifications`)

Then add the nav entry in `resources/js/layouts/settings/layout.tsx` so the page is reachable — import `edit as editNotifications` from `@/routes/notifications` and add to `sidebarNavItems`, after Security:

```tsx
    {
        title: 'Notifications',
        href: editNotifications(),
        icon: null,
    },
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test -- notifications.test`
Expected: PASS (4 tests).

Then: `npm run test && npm run types:check && npm run lint:check`
Expected: all clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/settings/notifications.tsx resources/js/pages/settings/notifications.test.tsx resources/js/layouts/settings/layout.tsx
git commit -m "feat(reminders): notification settings page"
```

---

### Task 7: Full verification

- [ ] **Step 1: Run the whole PHP suite**

Run: `php artisan test --compact`
Expected: PASS. Baseline before this plan was 385; this plan adds roughly 30 tests.

- [ ] **Step 2: Run the frontend checks**

Run: `npm run test && npm run types:check && npm run lint:check`
Expected: all clean. vitest baseline was 90; this plan adds 4.

- [ ] **Step 3: Confirm the digest command is scheduled**

Run: `php artisan schedule:list`
Expected: both `actions:fire` and `reminders:digest` listed, each every minute.

- [ ] **Step 4: Confirm the settings page is routable**

Run: `php artisan route:list --path=settings/notifications`
Expected: `GET` and `PATCH settings/notifications`, named `notifications.edit` and `notifications.update`.

- [ ] **Step 5: Push and open a PR**

```bash
vendor/bin/pint --dirty --format agent
git push -u origin worktree-email-reminders
gh pr create --title "feat(reminders): email reminders — digest or per-cue" --body "Implements docs/superpowers/specs/2026-08-24-email-reminders-design.md"
```
