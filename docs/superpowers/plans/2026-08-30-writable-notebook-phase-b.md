# Writable Notebook — Phase B Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the notebook within reach — a timezone you can see and correct, a reminder you can answer from the lock screen, and an app that installs to the home screen.

**Architecture:** Three unrelated mechanisms that share one purpose. The timezone screen reuses the existing `PATCH` and adds the re-anchoring that was always missing. The quick-log route is the app's only unauthenticated write, protected by signed expiring URLs and made idempotent by the record rather than by new state. The PWA is build configuration plus a service worker that deliberately does not cache documents.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3 + React 19, `vite-plugin-pwa` (new dependency, approved), PHPUnit 12, Vitest 4.

## Global Constraints

- Everything in Phase A's Global Constraints still applies — verbatim text, no gamification, no numeric targets, the notebook never nags, append-only, Pint after PHP changes, `php artisan wayfinder:generate --with-form` after route changes.
- **`vite-plugin-pwa` is the only dependency this phase may add.** Any other addition needs the owner's approval first.
- **Never cache document requests in the service worker.** Caching Inertia HTML serves a stale CSRF token and produces 419s that look like random logouts.
- **`failed` is never a one-click outcome.** A failure must carry the user's own stated reason.
- Herd serves the app at https://patyourself.test. Never run `php artisan serve`.

---

### Task 1: The timezone screen, and re-anchoring when it changes

**Decision gate — resolve before writing code.**

Changing a timezone has to re-anchor future occurrences, or a user who moves keeps being cued at their old local time forever, silently. That purge-and-re-anchor logic already exists twice: in `RescheduleAction` and in `UpdateIntention::reanchorStaleActions()`. The duplication is **deliberate** — extraction was proposed during the roll-forward work and the owner ruled the two call sites conceptually independent.

A third copy is where "two independent call sites" becomes "we maintain three of these". **Ask the owner to confirm the reversal before extracting.** If they decline, the timezone controller gets its own copy with a comment pointing at the ruling, and this task still ships — it is slower to maintain, not broken.

The steps below assume the extraction is approved.

**Files:**
- Create: `app/Services/Scheduling/ReanchorsSeries.php`
- Create: `app/Http/Controllers/Settings/TimezoneController.php` → add `edit()`
- Create: `resources/js/pages/settings/timezone.tsx`
- Modify: `app/Actions/RescheduleAction.php`, `app/Actions/UpdateIntention.php` (call the service)
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/TimezoneScreenTest.php`, `tests/Unit/Scheduling/ReanchorsSeriesTest.php`

**Interfaces:**
- Produces: `ReanchorsSeries::forActions(Collection $actions, string $timezone): void` — purges each action's unlogged future occurrences and moves `series_started_at` to its next real occurrence. Consumed by `RescheduleAction`, `UpdateIntention` and `TimezoneController`.

- [ ] **Step 1: Write the failing service test**

```php
<?php

namespace Tests\Unit\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\ReanchorsSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReanchorsSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_unlogged_future_occurrences_and_keeps_logged_ones(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'schedule_kind' => 'clock',
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(3),
        ]);

        $logged = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $logged->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'completed',
            'logged_at' => now()->subDay(),
        ]);
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDays(2)]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London');

        $this->assertDatabaseHas('occurrences', ['id' => $logged->id]);
        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_it_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'schedule_kind' => 'anchored',
            'recurrence' => null,
            'series_started_at' => null,
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London');

        $this->assertNull($action->refresh()->series_started_at);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ReanchorsSeriesTest`
Expected: FAIL — `Target class [App\Services\Scheduling\ReanchorsSeries] does not exist`.

- [ ] **Step 3: Extract the service**

Move the body of `UpdateIntention::reanchorStaleActions()` into `app/Services/Scheduling/ReanchorsSeries.php` verbatim, generalised to take a collection and a timezone. Keep every existing comment — they record why `nextAfter()` is used before `firstOccurrence()`, and why anything unlogged ahead of now belongs to the cadence being left behind.

```php
<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Moves a series' anchor forward and drops the occasions that belonged to the
 * cadence being left behind.
 *
 * Extracted from RescheduleAction and UpdateIntention, which each held their
 * own copy. That duplication was a deliberate ruling — the two call sites were
 * judged conceptually independent — reversed when a third caller (a timezone
 * change) made three copies of one rule.
 *
 * Logged occurrences are never touched: an outcome describes an occasion that
 * happened, and re-anchoring is about the future.
 */
final readonly class ReanchorsSeries
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  Collection<int, Action>  $actions
     */
    public function forActions(Collection $actions, string $timezone): void
    {
        $now = CarbonImmutable::now();

        $actions
            ->reject(fn (Action $action): bool => $action->series_started_at === null)
            ->each(function (Action $action) use ($now, $timezone): void {
                $seriesStartedAt = $action->series_started_at->toImmutable();
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot,
                // preserving the weekday and staying DST-correct instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() handles.
                $next = $this->schedule->nextAfter($seriesStartedAt, $now, $recurrence, $timezone)
                    ?? $this->schedule->firstOccurrence(
                        $now,
                        $seriesStartedAt->setTimezone($timezone)->format('H:i'),
                        $recurrence,
                        $timezone,
                    );

                if ($next === null) {
                    return;
                }

                $action->occurrences()
                    ->unlogged()
                    ->where('scheduled_for', '>', $now)
                    ->delete();

                $action->update(['series_started_at' => $next]);
            });
    }
}
```

- [ ] **Step 4: Repoint both existing callers**

In `UpdateIntention`, replace the body of `reanchorStaleActions()` with a call to the service, keeping the existing query that selects only genuinely stale actions. In `RescheduleAction`, replace its equivalent block the same way. Run the existing suites for both to prove the extraction changed no behaviour:

```bash
php artisan test --compact --filter=UpdateIntention
php artisan test --compact --filter=RescheduleAction
```

Expected: PASS, unchanged.

- [ ] **Step 5: Run the new service test**

Run: `php artisan test --compact --filter=ReanchorsSeriesTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Write the failing screen test**

```php
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
            'schedule_kind' => 'clock',
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
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `php artisan test --compact --filter=TimezoneScreenTest`
Expected: FAIL — `Route [timezone.edit] not defined`.

- [ ] **Step 8: Add `edit()` and the re-anchor to `TimezoneController`**

```php
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/timezone', [
            'timezone' => $request->user()->timezone ?? (string) config('app.timezone'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }
```

and in `update()`, after saving the new zone:

```php
        // Occurrences store absolute instants, so without this a user who moves
        // keeps being cued at their old local time — silently, forever.
        $this->reanchor->forActions(
            $user->intentions()
                ->with('actions')
                ->get()
                ->flatMap(fn (Intention $loop) => $loop->actions)
                ->where('status', '!=', Action::STATUS_ARCHIVED),
            $timezone,
        );
```

Validate the identifier with `Rule::in(timezone_identifiers_list())`.

- [ ] **Step 9: Add the route**

In `routes/settings.php`, beside the existing `PATCH`:

```php
    Route::get('settings/timezone', [TimezoneController::class, 'edit'])->name('timezone.edit');
```

- [ ] **Step 10: Write the screen**

`resources/js/pages/settings/timezone.tsx`, following `settings/notifications.tsx`: show the stored zone, show what the browser currently reports (`Intl.DateTimeFormat().resolvedOptions().timeZone`) with a one-click "use this" when they differ, and a `<select>` of the identifier list. Copy states plainly that changing the zone moves future occasions and leaves logged ones alone.

- [ ] **Step 11: Run everything and commit**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=TimezoneScreenTest
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Services/Scheduling/ReanchorsSeries.php app/Actions app/Http/Controllers/Settings/TimezoneController.php routes/settings.php resources/js/pages/settings/timezone.tsx resources/js/routes resources/js/actions tests
git commit -m "feat(settings): the timezone becomes visible, correctable, and re-anchoring"
```

---

### Task 2: One-click logging from the reminder email

**Files:**
- Create: `app/Http/Controllers/QuickLogController.php`
- Create: `resources/views/quick-log.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/QuickLog/QuickLogTest.php`

**Interfaces:**
- Consumes: `App\Actions\LogAction::handle(User $user, Action $action, array $data, ?Occurrence $occurrence = null): ActionLog`.
- Produces: route `occurrences.quick-log` at `GET /o/{occurrence}/{outcome}`, generated with `URL::temporarySignedRoute('occurrences.quick-log', now()->addDays(7), [...])` — consumed by Task 3's mail.

**The security trade, stated:** this is the app's only unauthenticated write. Anyone holding the email can log that one occasion. Blast radius is one outcome, correctable in the app. Accepted because the alternative costs the one-click property on the device where reminders are actually read.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\QuickLog;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class QuickLogTest extends TestCase
{
    use RefreshDatabase;

    private function occurrence(): Occurrence
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);

        return $action->occurrences()->create([
            'scheduled_for' => now()->subHour(),
            'fired_at' => now()->subHour(),
        ]);
    }

    private function signedUrl(Occurrence $occurrence, string $outcome, ?string $expiry = null): string
    {
        return URL::temporarySignedRoute(
            'occurrences.quick-log',
            $expiry ? now()->parse($expiry) : now()->addDays(7),
            ['occurrence' => $occurrence->id, 'outcome' => $outcome],
        );
    }

    public function test_a_signed_link_logs_the_outcome_without_a_login(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'completed'))->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'occurrence_id' => $occurrence->id,
            'outcome' => 'completed',
        ]);
    }

    public function test_skipped_is_also_loggable_in_one_click(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'skipped'))->assertOk();

        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrence->id, 'outcome' => 'skipped']);
    }

    public function test_failed_is_not_available_in_one_click(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'failed'))->assertNotFound();

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_an_unsigned_url_is_rejected(): void
    {
        $occurrence = $this->occurrence();

        $this->get("/o/{$occurrence->id}/completed")->assertForbidden();

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $occurrence = $this->occurrence();
        $url = $this->signedUrl($occurrence, 'completed');

        $this->travel(8)->days();

        $this->get($url)->assertForbidden();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_second_click_does_not_write_a_second_outcome(): void
    {
        $occurrence = $this->occurrence();
        $url = $this->signedUrl($occurrence, 'completed');

        $this->get($url)->assertOk();
        $this->get($url)->assertOk()->assertSee('already logged', false);

        $this->assertDatabaseCount('action_logs', 1);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=QuickLogTest`
Expected: FAIL — `Route [occurrences.quick-log] not defined`.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\LogAction;
use App\Models\Occurrence;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers a reminder from the email, without a login.
 *
 * The app's only unauthenticated write. Protected by a signed URL that expires
 * in seven days, and effectively single-use: an occasion that already carries an
 * outcome is not written again, which also makes a double click correct.
 *
 * `failed` is deliberately absent. A failure has to carry the user's own stated
 * reason, and a one-click failure would either drop it or invent it — so the
 * mail deep-links into the app for that one.
 */
class QuickLogController extends Controller
{
    private const ONE_CLICK_OUTCOMES = ['completed', 'skipped'];

    public function __invoke(Occurrence $occurrence, string $outcome, LogAction $logAction): View
    {
        if (! in_array($outcome, self::ONE_CLICK_OUTCOMES, true)) {
            throw new NotFoundHttpException;
        }

        $occurrence->loadMissing(['action.intention.user', 'log']);

        // Idempotent by reading the record rather than by storing new state:
        // this is what gives the signed link single-use semantics, and it makes
        // a double click correct instead of a second write.
        if ($occurrence->isLogged()) {
            return view('quick-log', [
                'title' => $occurrence->action->title,
                'outcome' => $occurrence->log->outcome,
                'alreadyLogged' => true,
            ]);
        }

        $logAction->handle(
            $occurrence->action->intention->user,
            $occurrence->action,
            ['outcome' => $outcome],
            $occurrence,
        );

        return view('quick-log', [
            'title' => $occurrence->action->title,
            'outcome' => $outcome,
            'alreadyLogged' => false,
        ]);
    }
}
```

- [ ] **Step 4: Write the confirmation view**

`resources/views/quick-log.blade.php` — a standalone page, not the Inertia app: no session, no nav, nothing about the user's other loops. It says what was recorded, says it can be changed in the app, and links to `route('dashboard')`. When `$alreadyLogged` it says "already logged" and shows the outcome that is on the record.

- [ ] **Step 5: Add the route**

In `routes/web.php`, **outside** the `auth` group, at the bottom of the file before the settings require:

```php
// Answering a cue straight from the email. Outside `auth` by design — see
// QuickLogController for the trade this accepts and what bounds it.
Route::get('o/{occurrence}/{outcome}', QuickLogController::class)
    ->middleware(['signed', 'throttle:20,1'])
    ->name('occurrences.quick-log');
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=QuickLogTest`
Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/QuickLogController.php resources/views/quick-log.blade.php routes/web.php tests/Feature/QuickLog
git commit -m "feat(reminders): a cue can be answered from the email"
```

---

### Task 3: Put the buttons in the mail

**Files:**
- Modify: `app/Notifications/ActionDueNotification.php`, `app/Notifications/DailyDigestNotification.php`
- Test: `tests/Feature/Notifications/QuickLogLinksTest.php`

**Interfaces:**
- Consumes: route `occurrences.quick-log` (Task 2).
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Assert by *following* the generated link, not by matching its string — a test that matches a URL passes with a URL that does not work.

```php
public function test_the_cue_mail_carries_a_working_done_link(): void
{
    $occurrence = $this->occurrence();
    $user = $occurrence->action->intention->user;
    $user->update(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

    $mail = (new ActionDueNotification($occurrence))->toMail($user);
    $rendered = (string) $mail->render();

    preg_match('#https?://[^"\s]+/o/\d+/completed[^"\s]*#', $rendered, $matches);
    $this->assertNotEmpty($matches, 'The cue mail should carry a one-click Done link.');

    $this->get(html_entity_decode($matches[0]))->assertOk();
    $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrence->id, 'outcome' => 'completed']);
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=QuickLogLinksTest`
Expected: FAIL — no matching link in the rendered mail.

- [ ] **Step 3: Add the links to `ActionDueNotification::toMail()`**

```php
        $done = URL::temporarySignedRoute('occurrences.quick-log', now()->addDays(7), [
            'occurrence' => $this->occurrence->id,
            'outcome' => 'completed',
        ]);

        $skipped = URL::temporarySignedRoute('occurrences.quick-log', now()->addDays(7), [
            'occurrence' => $this->occurrence->id,
            'outcome' => 'skipped',
        ]);

        return (new MailMessage)
            ->subject($action->title)
            ->line("It's time for: {$action->title}")
            ->line("Loop: {$loop->title}")
            ->line("Cue: {$loop->cue}")
            ->action('Done', $done)
            ->line("Didn't happen: {$skipped}")
            // Not one-click on purpose: a failure carries your own reason, and
            // this link opens the app so you can write it.
            ->line("It happened and the strategy didn't hold: ".route('loops.show', $loop->id))
            ->line('Manage your reminders: '.route('notifications.edit'));
```

- [ ] **Step 4: Do the same per row in `DailyDigestNotification`**

Each digest row gets its own Done and Didn't-happen links for that occasion.

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact --filter=QuickLogLinksTest
php artisan test --compact --filter=Notification
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications tests/Feature/Notifications
git commit -m "feat(reminders): the cue mail can be answered without opening the app"
```

---

### Task 4: The PWA

**Files:**
- Modify: `package.json` (add `vite-plugin-pwa`), `vite.config.ts`, `resources/js/app.tsx`
- Create: `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/maskable-512.png`
- Test: `tests/Feature/PwaManifestTest.php`

**Interfaces:**
- Produces: a build-time manifest and service worker. Nothing in application code consumes them.

- [ ] **Step 1: Install the dependency**

```bash
npm install -D vite-plugin-pwa
```

This is the one dependency addition Phase B is authorised to make.

- [ ] **Step 2: Write the failing test**

```php
public function test_the_service_worker_never_precaches_documents(): void
{
    $sw = base_path('public/build/sw.js');
    $this->assertFileExists($sw, 'Run `npm run build` before this test.');

    $contents = file_get_contents($sw);

    // The failure this guards is silent: a cached document serves a stale CSRF
    // token and the user sees random 419s that look like being logged out.
    $this->assertStringNotContainsString('"revision":null,"url":"/"', $contents);
    $this->assertStringContainsString('NetworkOnly', $contents);
}
```

- [ ] **Step 3: Configure the plugin**

In `vite.config.ts`, add to `plugins`:

```ts
        VitePWA({
            registerType: 'autoUpdate',
            outDir: 'public/build',
            manifest: {
                name: 'PatYourSelf',
                short_name: 'PatYourSelf',
                start_url: '/dashboard',
                display: 'standalone',
                background_color: '#ffffff',
                theme_color: '#ffffff',
                icons: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            workbox: {
                // Assets only. Documents are NetworkOnly on purpose: caching an
                // Inertia HTML response serves a stale CSRF token, and the 419s
                // that follow look like random logouts. Do not "optimise" this.
                globPatterns: ['**/*.{js,css,woff2,png,svg}'],
                navigateFallback: null,
                runtimeCaching: [
                    {
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkOnly',
                    },
                ],
            },
        }),
```

- [ ] **Step 4: Register the worker**

In `resources/js/app.tsx`, after the Inertia app is created:

```tsx
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/build/sw.js');
    });
}
```

- [ ] **Step 5: Add the icons**

Three PNGs derived from the existing Blob mark. The maskable one needs the safe-zone padding or Android crops it into a circle badly.

- [ ] **Step 6: Build and test**

```bash
npm run build
php artisan test --compact --filter=PwaManifestTest
npx vitest run
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json vite.config.ts resources/js/app.tsx public/icons tests/Feature/PwaManifestTest.php
git commit -m "feat(pwa): the notebook installs to the home screen"
```

> **Worktree trap:** `npm install` in a worktree rewrites `package-lock.json`'s `name` field to the worktree directory name. Revert that line before committing.

---

## Phase B self-review checklist

- [ ] `php artisan test --compact` — green
- [ ] `npx vitest run` — green
- [ ] `vendor/bin/pint --dirty --format agent` — clean
- [ ] `npm run build` succeeds and `public/build/sw.js` exists
- [ ] A reminder email received on a phone logs an outcome in one tap, and tapping twice does not double-log
- [ ] Changing the timezone in settings moves tomorrow's occasions and leaves yesterday's logged ones exactly where they were
