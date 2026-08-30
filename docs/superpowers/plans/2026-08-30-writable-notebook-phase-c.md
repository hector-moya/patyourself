# Writable Notebook — Phase C Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the record portable, make the loops list navigable, and make a broken queue say so.

**Architecture:** One read service builds the export payload and two small formatters render it, so the thing worth testing is separated from the thing worth eyeballing. The loops index gains the `?status=` filter the API already has, plus a search across the title and the four chain fields. The failed-job alert is a scheduled command that mails synchronously, because an alert about a broken queue must not ride the queue.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3 + React 19, PHPUnit 12, Vitest 4.

## Global Constraints

- Everything in Phase A's Global Constraints still applies — verbatim text, no gamification, no numeric targets, the notebook never nags, append-only, Pint after PHP changes, `php artisan wayfinder:generate --with-form` after route changes.
- **No new dependencies in this phase.**
- **Verbatim survives the export.** A failure reason must come out byte-for-byte identical to what went in. That is the one property of the export worth guarding hard.
- **`q` is always a bound parameter**, never interpolated into SQL.
- Herd serves the app at https://patyourself.test.

---

### Task 1: The export payload

**Files:**
- Create: `app/Services/Export/RecordExport.php`
- Test: `tests/Feature/Export/RecordExportTest.php`

**Interfaces:**
- Produces: `RecordExport::forUser(User $user): array` — the complete record as a nested array, consumed by Task 2's formatters and the controller.

Shape:

```
[
  'exported_at' => string (ISO-8601),
  'user' => ['name' => string, 'email' => string, 'timezone' => string],
  'loops' => [
    [
      'id', 'title', 'type', 'status', 'created_at',
      'chain' => ['cue', 'craving', 'response', 'reward'],
      'experiments' => [
        ['version', 'status', 'intervention_point', 'approach', 'rationale',
         'change_reason', 'superseded_reason', 'review_at', 'planned_days',
         'verdict', 'verdict_note', 'created_at'],
      ],
      'actions' => [['id', 'title', 'schedule_kind', 'time', 'recurrence', 'anchor', 'status']],
      'outcomes' => [['occasion', 'logged_at', 'outcome', 'reason', 'context', 'strategy_version']],
      'notes' => [['body', 'noted_at']],
      'reflections' => [['content', 'window_start', 'window_end', 'events_count', 'created_at']],
    ],
  ],
]
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Export;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use App\Services\Export\RecordExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_only_the_users_own_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'Mine']);
        Intention::factory()->for(User::factory())->create(['title' => 'Someone else’s']);

        $export = app(RecordExport::class)->forUser($user);

        $titles = array_column($export['loops'], 'title');
        $this->assertSame(['Mine'], $titles);
    }

    public function test_a_failure_reason_survives_byte_for_byte(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $reason = "  Two spaces.  MIXED case.\nAnd a newline.  ";
        ActionLog::factory()->for($loop->actions()->first() ?? $loop->actions()->create([
            'title' => 'Check in',
            'schedule_kind' => 'anchored',
            'anchor' => 'after dinner',
            'status' => 'active',
        ]), 'action')->create([
            'user_id' => $user->id,
            'outcome' => 'failed',
            'reason' => $reason,
        ]);

        $export = app(RecordExport::class)->forUser($user);

        $this->assertSame($reason, $export['loops'][0]['outcomes'][0]['reason']);
    }

    public function test_an_account_with_no_loops_exports_a_valid_empty_document(): void
    {
        $export = app(RecordExport::class)->forUser(User::factory()->create());

        $this->assertSame([], $export['loops']);
        $this->assertArrayHasKey('exported_at', $export);
    }

    public function test_every_strategy_version_is_present_with_its_verdict(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $loop->strategies()->create([
            'version' => 1, 'status' => 'superseded', 'intervention_point' => 'cue',
            'approach' => 'First try', 'verdict' => 'failed', 'verdict_note' => 'The cue was unavoidable.',
        ]);
        $loop->strategies()->create([
            'version' => 2, 'status' => 'active', 'intervention_point' => 'craving',
            'approach' => 'Second try',
        ]);

        $export = app(RecordExport::class)->forUser($user);

        $this->assertCount(2, $export['loops'][0]['experiments']);
        $this->assertSame('The cue was unavoidable.', $export['loops'][0]['experiments'][0]['verdict_note']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RecordExportTest`
Expected: FAIL — `Target class [App\Services\Export\RecordExport] does not exist`.

- [ ] **Step 3: Write the service**

Eager-load in one pass — `intentions.strategies`, `.actions.occurrences`, `.actionLogs.occurrence`, `.notes`, `.summaries` — and map to the shape above. Outcomes are dated by occasion (`occurrence.scheduled_for ?? logged_at`), matching how `LoopOutcomesTool` and the lab record already date them, so the export reads in the same order the app shows.

Reasons, notes, verdict notes and reflections are copied through untouched. No trimming, no casting to a normalised case.

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --compact --filter=RecordExportTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Export/RecordExport.php tests/Feature/Export/RecordExportTest.php
git commit -m "feat(export): the record can be read out in one pass"
```

---

### Task 2: Download it as JSON or Markdown

**Files:**
- Create: `app/Services/Export/MarkdownFormatter.php`
- Create: `app/Http/Controllers/ExportController.php`
- Modify: `routes/web.php`, `resources/js/pages/settings/profile.tsx` (add the link)
- Test: `tests/Feature/Export/ExportDownloadTest.php`

**Interfaces:**
- Consumes: `RecordExport::forUser()` (Task 1).
- Produces: route `export.show` at `GET /export?format=json|md`.

- [ ] **Step 1: Write the failing test**

```php
public function test_json_downloads_as_an_attachment(): void
{
    $user = User::factory()->create();
    Intention::factory()->for($user)->create(['title' => 'Eating to 80%']);

    $response = $this->actingAs($user)->get(route('export.show', ['format' => 'json']));

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertHeader('content-disposition', 'attachment; filename=patyourself-record.json');

    $this->assertSame('Eating to 80%', $response->json('loops.0.title'));
}

public function test_markdown_renders_a_loop_with_its_experiments(): void
{
    $user = User::factory()->create();
    $loop = Intention::factory()->for($user)->create(['title' => 'Eating to 80%', 'cue' => 'Plate lands']);
    $loop->strategies()->create([
        'version' => 1, 'status' => 'active', 'intervention_point' => 'cue',
        'approach' => 'Serve from the stove', 'verdict' => 'worked',
    ]);

    $body = $this->actingAs($user)->get(route('export.show', ['format' => 'md']))->getContent();

    $this->assertStringContainsString('# Eating to 80%', $body);
    $this->assertStringContainsString('Plate lands', $body);
    $this->assertStringContainsString('v1', $body);
    $this->assertStringContainsString('worked', $body);
}

public function test_an_unknown_format_falls_back_to_json(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('export.show', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/json');
}

public function test_a_guest_cannot_export(): void
{
    $this->get(route('export.show'))->assertRedirect(route('login'));
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ExportDownloadTest`
Expected: FAIL — `Route [export.show] not defined`.

- [ ] **Step 3: Write the Markdown formatter**

One heading per loop, the chain as a four-line block, experiments oldest-first with their verdict and note, then outcomes with their verbatim reasons, then notes, then the reflections. Raw counts, never percentages — `8/11`, not `73%`. An open-ended experiment prints "open-ended", never a day count against a target that does not exist.

- [ ] **Step 4: Write the controller and route**

```php
Route::get('export', ExportController::class)->name('export.show');
```

inside the `auth`+`verified` group. The controller resolves the format (anything other than `md` is JSON), builds the payload once, and returns a download response.

- [ ] **Step 5: Add the link**

On `settings/profile.tsx`, beside the delete-account section: "Download your record" with both formats. The copy says what the file contains and that it is yours.

- [ ] **Step 6: Run tests and commit**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=ExportDownloadTest
vendor/bin/pint --dirty --format agent
git add app/Services/Export app/Http/Controllers/ExportController.php routes/web.php resources/js resources/js/routes tests/Feature/Export
git commit -m "feat(export): the record downloads as JSON or as a notebook"
```

---

### Task 3: Filter and search the loops index

**Files:**
- Modify: `app/Http/Controllers/IntentionController.php` (`index`), `resources/js/pages/loops/index.tsx`
- Test: `tests/Feature/Loops/LoopsIndexFilterTest.php`, `resources/js/pages/loops/index.test.tsx`

**Interfaces:**
- Produces: `GET /loops?status=<active|paused|archived|completed|all>&q=<string>`; the page receives `filters: { status: string, q: string }` alongside `intentions`.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_status_filter_narrows_the_list(): void
{
    $user = User::factory()->create();
    Intention::factory()->for($user)->create(['title' => 'Running', 'status' => Intention::STATUS_ACTIVE]);
    Intention::factory()->for($user)->create(['title' => 'Resting', 'status' => Intention::STATUS_PAUSED]);

    $this->actingAs($user)->get(route('loops.index', ['status' => 'paused']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('intentions', 1)
            ->where('intentions.0.title', 'Resting'));
}

public function test_search_matches_the_chain_not_only_the_title(): void
{
    $user = User::factory()->create();
    Intention::factory()->for($user)->create(['title' => 'Evenings', 'cue' => 'the plate lands']);
    Intention::factory()->for($user)->create(['title' => 'Mornings', 'cue' => 'alarm goes off']);

    $this->actingAs($user)->get(route('loops.index', ['q' => 'plate']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('intentions', 1)
            ->where('intentions.0.title', 'Evenings'));
}

public function test_a_percent_sign_in_the_query_is_a_literal(): void
{
    $user = User::factory()->create();
    Intention::factory()->for($user)->create(['title' => 'Eating to 80%']);
    Intention::factory()->for($user)->create(['title' => 'Walking']);

    $this->actingAs($user)->get(route('loops.index', ['q' => '80%']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 1));
}

public function test_filters_compose(): void
{
    $user = User::factory()->create();
    Intention::factory()->for($user)->create(['title' => 'Evening walk', 'status' => Intention::STATUS_ACTIVE]);
    Intention::factory()->for($user)->create(['title' => 'Evening read', 'status' => Intention::STATUS_PAUSED]);

    $this->actingAs($user)->get(route('loops.index', ['status' => 'active', 'q' => 'Evening']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 1));
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=LoopsIndexFilterTest`
Expected: FAIL — every loop still returned regardless of the query string.

- [ ] **Step 3: Implement the filter**

In `IntentionController::index`, before the existing `latest()`:

```php
        $status = $request->string('status')->toString();
        $q = $request->string('q')->toString();

        $intentions = $request->user()->intentions()
            ->with('activeStrategy')
            ->when($status !== '' && $status !== 'all',
                fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q): void {
                // Escaped and bound. A title like "Eating to 80%" is a literal
                // search term, not a wildcard.
                $term = '%'.addcslashes($q, '%_\\').'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('title', 'like', $term)
                        ->orWhere('cue', 'like', $term)
                        ->orWhere('craving', 'like', $term)
                        ->orWhere('response', 'like', $term)
                        ->orWhere('reward', 'like', $term);
                });
            })
            ->latest()
            ->get()
            // ... existing active-first sort, unchanged
```

and pass `'filters' => ['status' => $status, 'q' => $q]` to the Inertia render.

- [ ] **Step 4: Add the controls**

On `loops/index.tsx`: a status segmented control and a search box, submitting via `router.get` with `preserveState` so typing does not lose focus. The empty state stays neutral — "Nothing matches that" — never a nag and never a suggestion to try harder.

- [ ] **Step 5: Run both suites**

```bash
php artisan test --compact --filter=LoopsIndexFilterTest
npx vitest run resources/js/pages/loops/index.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/IntentionController.php resources/js/pages/loops tests/Feature/Loops
git commit -m "feat(loops): the index can be filtered and searched"
```

---

### Task 4: Say when a job dies

**Files:**
- Create: `app/Console/Commands/AlertFailedJobs.php`
- Create: `app/Notifications/FailedJobsNotification.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/AlertFailedJobsTest.php`

**Interfaces:**
- Produces: `php artisan jobs:alert-failed`, scheduled hourly.

The digest stamps `digest_last_sent_on` immediately after `notify()`, which only *enqueues*. If the job exhausts its retries the user loses that day's digest and nothing says so. This is the mitigation the email-reminders spec named and did not build.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    private function recordFailure(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: SMTP timed out',
            'failed_at' => now(),
        ]);
    }

    public function test_it_alerts_once_for_a_new_failure(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        $this->artisan('jobs:alert-failed')->assertExitCode(0);

        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    public function test_it_does_not_re_alert_for_the_same_failure(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        $this->artisan('jobs:alert-failed');
        $this->artisan('jobs:alert-failed');

        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    public function test_it_says_nothing_when_nothing_failed(): void
    {
        Notification::fake();
        User::factory()->create();

        $this->artisan('jobs:alert-failed');

        Notification::assertNothingSent();
    }

    public function test_the_high_water_mark_does_not_advance_when_sending_throws(): void
    {
        User::factory()->create();
        $this->recordFailure();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('mail down'));

        try {
            $this->artisan('jobs:alert-failed');
        } catch (\Throwable) {
            // expected
        }

        $this->assertNull(Cache::get('jobs.alert-failed.high-water'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=AlertFailedJobsTest`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A queued job failed.
 *
 * Deliberately NOT ShouldQueue. An alert about a broken queue that is itself
 * queued is not an alert — it is a second thing that silently does not arrive.
 */
class FailedJobsNotification extends Notification
{
    /**
     * @param  array<int, string>  $exceptions  First line of each failure.
     */
    public function __construct(private readonly int $count, private readonly array $exceptions) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("PatYourSelf: {$this->count} background job(s) failed")
            ->line('A background job failed since the last check. Reminder emails and cue delivery both run on the queue, so they may not be arriving.');

        foreach ($this->exceptions as $exception) {
            $mail->line($exception);
        }

        return $mail->line('Check the queue worker on Forge, then clear failed_jobs once it is healthy.');
    }
}
```

- [ ] **Step 4: Write the command**

Read `failed_jobs` where `failed_at` is newer than the cached high-water mark (or all rows on first run), send the notification to the account owner on the **sync** connection, and only then advance the mark. A cache key rather than a table: if the cache is cleared, the worst case is one duplicate alert, which is the right direction to fail in.

- [ ] **Step 5: Schedule it**

In `routes/console.php`:

```php
// The digest stamps digest_last_sent_on right after notify(), which only
// enqueues — so a job that dies in the queue loses that day's digest silently.
// Hourly is soon enough to notice and rare enough not to nag.
Schedule::command(AlertFailedJobs::class)->hourly()->withoutOverlapping();
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact --filter=AlertFailedJobsTest
php artisan test --compact --filter=ScheduledCommandsTest
```

Expected: PASS. `ScheduledCommandsTest` asserts the schedule's contents — add the new command to its expectations.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/AlertFailedJobs.php app/Notifications/FailedJobsNotification.php routes/console.php tests/Feature/Console
git commit -m "feat(ops): a job that dies in the queue now says so"
```

---

## Phase C self-review checklist

- [ ] `php artisan test --compact` — green
- [ ] `npx vitest run` — green
- [ ] `vendor/bin/pint --dirty --format agent` — clean
- [ ] An export of a real account opens, reads correctly, and a failure reason in it matches the app character for character
- [ ] `php artisan schedule:list` shows `jobs:alert-failed` hourly
- [ ] Searching `80%` on the loops index finds the loop called "Eating to 80%" and not everything else
