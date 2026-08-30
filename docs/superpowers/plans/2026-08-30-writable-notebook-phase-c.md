# Writable Notebook — Phase C Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the record portable, searchable, and self-reporting — you can take it out, find things in it, and hear about it when the machinery breaks.

**Architecture:** Three unrelated mechanisms. `RecordExport` builds one payload from the user's whole record and two small formatters render it, so the service is the unit worth testing and the formatters are rendering. The loops index gains the `?status=` filter the JSON API already has plus a `?q=` search over the chain. An hourly command mails the owner about failed jobs, sending synchronously because an alert about a broken queue that is itself queued is not an alert.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3 + React 19, PHPUnit 12, Vitest 4. **No new dependencies.**

## Global Constraints

- **No new dependencies.** Phase C authorises none. If a task seems to need one, stop and ask the owner.
- **Text the user writes is stored verbatim** — never trimmed, never sentence-cased. `bootstrap/app.php` carries a `trimStrings` exception list keyed on REQUEST FIELD NAMES for this. The export must carry that text out byte-for-byte; a failure reason with leading whitespace still has it after a round trip.
- **Failure language is about the strategy, never the user.**
- **No gamification. Nothing congratulates. No numeric targets.** The Markdown export is a lab notebook, not a report card — no streaks, no completion percentages, no scores.
- **The notebook never nags.** An open-ended experiment must never render as a countdown, in the export or the index.
- **History is append-only.** Nothing in this phase writes to a loop, strategy, action, occurrence, outcome, note or reflection.
- **No importer, ever in this phase.** Export is read-only.
- **No change to the MCP surface.**
- Pint after PHP changes: `vendor/bin/pint --dirty --format agent`.
- `php artisan wayfinder:generate --with-form` after any route change. The flag is mandatory; its output under `resources/js/routes` and `resources/js/actions` is gitignored.
- Herd serves the app at https://patyourself.test. **Never run `php artisan serve`.**
- Tests are PHPUnit, not Pest: `php artisan test --compact --filter=Name`.

### Traps this codebase has already sprung on people

- **Laravel Boost's `database-schema` tool reads a stale database.** It is missing `occurrences` and `notes` entirely. Read `database/migrations/` instead — that is the source of truth.
- **`Action::schedule_kind` and `Action::anchor` are NOT columns.** They live in the `metadata` JSON. Passing them as top-level factory attributes throws a SQL error; nest them under `metadata`.
- **`Occurrence` exposes `log()`, not `actionLog()`.** `isLogged()` already exists.
- **Every feature test that renders a view must call `$this->withoutVite()` in `setUp()`.** `public/build` is gitignored, so the suite has to stay green on an unbuilt checkout.
- **Laravel's mail Markdown has no autolink extension.** A bare URL in `->line()` renders as a paragraph, not a link. Use `[label](url)` syntax. (Not needed this phase unless you touch mail copy.)

### Baseline

`main` at `407720b`: **728 PHP tests, 207 JS tests passing. Pint clean. Exactly 2 TypeScript errors** — `catch-up.tsx:132` and `loops/index.tsx:104`. Task 3 edits `loops/index.tsx`, so it fixes that one and the count drops to 1. `catch-up.tsx:132` stays out of scope.

---

### Task 1: `RecordExport` — the payload

The service that walks a user's whole record into one array. No route, no formatting, no HTTP — just the payload and its unit test. This is the piece worth testing; Task 2's formatters are rendering.

**Files:**
- Create: `app/Services/Export/RecordExport.php`
- Test: `tests/Unit/Export/RecordExportTest.php`

**Interfaces:**
- Produces: `RecordExport::forUser(User $user): array` — the complete record as a nested array. Consumed by Task 2's formatters and controller.

The shape it returns, which Task 2 depends on:

```
[
    'exported_at' => string,          // ISO-8601
    'user' => ['name' => string, 'email' => string, 'timezone' => ?string],
    'loops' => [
        [
            'title' => string, 'description' => ?string,
            'type' => string, 'status' => string,
            'chain' => ['cue' => ?string, 'craving' => ?string, 'response' => ?string, 'reward' => ?string],
            'created_at' => ?string,
            'strategies' => [[
                'version' => int, 'status' => string,
                'intervention_point' => ?string, 'approach' => ?string, 'rationale' => ?string,
                'change_reason' => ?string, 'superseded_reason' => ?string,
                'review_at' => ?string, 'verdict' => ?string, 'verdict_note' => ?string,
                'created_at' => ?string,
            ]],
            'actions' => [[
                'title' => string, 'description' => ?string,
                'recurrence' => ?string, 'status' => string,
                'series_started_at' => ?string,
                'occurrences' => [[
                    'scheduled_for' => ?string, 'fired_at' => ?string,
                    'outcome' => null | [
                        'outcome' => string, 'reason' => ?string,
                        // `context` is free text the user wrote about the mechanics of what
                        // happened — LogOutcomeTool calls it "the primary record". Verbatim,
                        // like `reason`. `context_fields` is an array cast; export the decoded
                        // structure, never a re-encoded string.
                        'context' => ?string, 'context_fields' => ?array,
                        'logged_at' => ?string,
                    ],
                ]],
            ]],
            'notes' => [['body' => string, 'noted_at' => ?string]],
            'reflections' => [[
                'scope' => ?string, 'content' => ?string,
                'window_start' => ?string, 'window_end' => ?string,
                'events_count' => ?int, 'created_at' => ?string,
            ]],
        ]
    ],
]
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Export;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Export\RecordExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_carries_a_failure_reason_out_byte_for_byte(): void
    {
        // Leading and trailing whitespace on purpose: the app stores what the
        // user wrote, and the export is not allowed to tidy it.
        $reason = '  the cue never fired, and I did not notice until bedtime  ';

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);
        $occurrence = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $occurrence->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'failed',
            'reason' => $reason,
            'logged_at' => now()->subDay(),
        ]);

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame(
            $reason,
            $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['reason'],
        );
    }

    public function test_it_carries_the_chain_and_every_strategy_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'cue' => 'the kettle clicks off',
            'craving' => 'something to do with my hands',
            'response' => 'ten press-ups',
            'reward' => 'the coffee tastes earned',
        ]);
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'verdict' => 'abandoned',
            'verdict_note' => 'the kettle is not a reliable cue on weekends',
        ]);
        Strategy::factory()->for($loop, 'intention')->create(['version' => 2, 'verdict' => null]);

        $record = app(RecordExport::class)->forUser($user);
        $loopRecord = $record['loops'][0];

        $this->assertSame('the kettle clicks off', $loopRecord['chain']['cue']);
        $this->assertSame('the coffee tastes earned', $loopRecord['chain']['reward']);
        $this->assertCount(2, $loopRecord['strategies']);
        $this->assertSame(1, $loopRecord['strategies'][0]['version']);
        $this->assertSame('abandoned', $loopRecord['strategies'][0]['verdict']);
        $this->assertSame(
            'the kettle is not a reliable cue on weekends',
            $loopRecord['strategies'][0]['verdict_note'],
        );
    }

    public function test_it_carries_notes_and_reflections(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        Note::factory()->for($loop, 'intention')->create([
            'body' => 'easier on days I sleep badly, which I did not expect',
            'noted_at' => now()->subDays(2),
        ]);
        $loop->summaries()->create([
            'user_id' => $user->id,
            'scope' => 'loop',
            'content' => 'three weeks in, the cue is the problem and not the response',
            'events_count' => 12,
        ]);

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame(
            'easier on days I sleep badly, which I did not expect',
            $record['loops'][0]['notes'][0]['body'],
        );
        $this->assertSame(
            'three weeks in, the cue is the problem and not the response',
            $record['loops'][0]['reflections'][0]['content'],
        );
    }

    public function test_another_users_record_never_appears(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        Intention::factory()->for($mine)->create(['title' => 'mine']);
        Intention::factory()->for($theirs)->create(['title' => 'theirs']);

        $record = app(RecordExport::class)->forUser($mine);

        $this->assertCount(1, $record['loops']);
        $this->assertSame('mine', $record['loops'][0]['title']);
    }

    public function test_an_empty_account_produces_a_valid_document(): void
    {
        $user = User::factory()->create();

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame([], $record['loops']);
        $this->assertSame($user->email, $record['user']['email']);
        $this->assertNotEmpty($record['exported_at']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RecordExportTest`
Expected: FAIL — `Target class [App\Services\Export\RecordExport] does not exist`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services\Export;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds one user's whole record as a plain array.
 *
 * The app's claim is that it is the record; a record you cannot take out is a
 * record someone else is holding. This is the single source of that payload —
 * both formatters render what this returns, so "is everything in the export?"
 * is a question about one class.
 *
 * Nothing here formats, rounds, summarises or scores. Text the user wrote is
 * copied out exactly as stored, whitespace and all.
 */
final readonly class RecordExport
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $loops = $user->intentions()
            ->with([
                'strategies' => fn ($query) => $query->orderBy('version'),
                'actions.occurrences.log',
                'notes' => fn ($query) => $query->orderBy('noted_at'),
                'summaries' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->orderBy('created_at')
            ->get();

        return [
            'exported_at' => Carbon::now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
            ],
            'loops' => $loops->map(fn (Intention $loop): array => $this->loop($loop))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loop(Intention $loop): array
    {
        return [
            'title' => $loop->title,
            'description' => $loop->description,
            'type' => $loop->type,
            'status' => $loop->status,
            'chain' => [
                'cue' => $loop->cue,
                'craving' => $loop->craving,
                'response' => $loop->response,
                'reward' => $loop->reward,
            ],
            'created_at' => $this->timestamp($loop->created_at),
            'strategies' => $loop->strategies->map(fn (Strategy $s): array => $this->strategy($s))->all(),
            'actions' => $loop->actions->map(fn (Action $a): array => $this->action($a))->all(),
            'notes' => $loop->notes->map(fn (Note $n): array => [
                'body' => $n->body,
                'noted_at' => $this->timestamp($n->noted_at),
            ])->all(),
            'reflections' => $loop->summaries->map(fn (Summary $s): array => [
                'scope' => $s->scope,
                'content' => $s->content,
                'window_start' => $this->timestamp($s->window_start),
                'window_end' => $this->timestamp($s->window_end),
                'events_count' => $s->events_count,
                'created_at' => $this->timestamp($s->created_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strategy(Strategy $strategy): array
    {
        return [
            'version' => (int) $strategy->version,
            'status' => $strategy->status,
            'intervention_point' => $strategy->intervention_point,
            'approach' => $strategy->approach,
            'rationale' => $strategy->rationale,
            'change_reason' => $strategy->change_reason,
            'superseded_reason' => $strategy->superseded_reason,
            'review_at' => $this->timestamp($strategy->review_at),
            'verdict' => $strategy->verdict,
            'verdict_note' => $strategy->verdict_note,
            'created_at' => $this->timestamp($strategy->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(Action $action): array
    {
        return [
            'title' => $action->title,
            'description' => $action->description,
            'recurrence' => $action->recurrence,
            'status' => $action->status,
            'series_started_at' => $this->timestamp($action->series_started_at),
            'occurrences' => $action->occurrences
                ->sortBy('scheduled_for')
                ->values()
                ->map(fn (Occurrence $occurrence): array => [
                    'scheduled_for' => $this->timestamp($occurrence->scheduled_for),
                    'fired_at' => $this->timestamp($occurrence->fired_at),
                    'outcome' => $occurrence->log === null ? null : $this->outcome($occurrence->log),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(ActionLog $log): array
    {
        return [
            'outcome' => $log->outcome,
            // Verbatim. The reason is the user's own words about why a strategy
            // did not hold, and it is the most important text in the record.
            'reason' => $log->reason,
            'logged_at' => $this->timestamp($log->logged_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : null;
    }
}
```

If `Intention` has no `notes()` relation yet, add it beside `summaries()` in `app/Models/Intention.php`:

```php
    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
```

Check first — `NoteController` already writes notes against a loop, so it probably exists.

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact --filter=RecordExportTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Export/RecordExport.php app/Models/Intention.php tests/Unit/Export
git commit -m "feat(export): the record becomes one payload"
```

---

### Task 2: The `/export` endpoint and its two formatters

**Files:**
- Create: `app/Services/Export/JsonRecordFormatter.php`
- Create: `app/Services/Export/MarkdownRecordFormatter.php`
- Create: `app/Http/Controllers/ExportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Export/ExportEndpointTest.php`, `tests/Unit/Export/MarkdownRecordFormatterTest.php`

**Interfaces:**
- Consumes: `RecordExport::forUser(User $user): array` (Task 1).
- Produces: route `export.show` at `GET /export`, inside the `auth`+`verified` group.
- Produces: `JsonRecordFormatter::render(array $record): string` and `MarkdownRecordFormatter::render(array $record): string`.

- [ ] **Step 1: Write the failing endpoint test**

```php
<?php

namespace Tests\Feature\Export;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_it_downloads_the_record_as_json_by_default(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'morning press-ups']);

        $response = $this->actingAs($user)->get(route('export.show'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('morning press-ups', $record['loops'][0]['title']);
    }

    public function test_it_downloads_the_record_as_markdown_when_asked(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'morning press-ups']);

        $response = $this->actingAs($user)->get(route('export.show', ['format' => 'md']));

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', $response->headers->get('content-type'));
        $this->assertStringContainsString('morning press-ups', $response->streamedContent());
    }

    public function test_an_unknown_format_falls_back_to_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('export.show', ['format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_verbatim_failure_reason_survives_the_round_trip(): void
    {
        $reason = '  I skipped it and told myself it was fine  ';

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = \App\Models\Action::factory()->for($loop, 'intention')->create();
        $occurrence = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $occurrence->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'failed',
            'reason' => $reason,
            'logged_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('export.show'));

        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($reason, $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['reason']);
    }

    public function test_a_guest_cannot_export(): void
    {
        $this->get(route('export.show'))->assertRedirect(route('login'));
    }

    public function test_an_empty_account_exports_a_valid_document(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('export.show'));

        $response->assertOk();
        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $record['loops']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ExportEndpointTest`
Expected: FAIL — `Route [export.show] not defined`.

- [ ] **Step 3: Write the JSON formatter**

```php
<?php

namespace App\Services\Export;

/**
 * The complete machine-readable dump. Pretty-printed because a record you
 * cannot read in a text editor is only half portable, and with unescaped
 * slashes and unicode so the user's own words come out looking like their
 * own words.
 */
final readonly class JsonRecordFormatter
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function render(array $record): string
    {
        return json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
```

- [ ] **Step 4: Write the failing Markdown formatter test**

```php
<?php

namespace Tests\Unit\Export;

use App\Services\Export\MarkdownRecordFormatter;
use Tests\TestCase;

class MarkdownRecordFormatterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return [
            'exported_at' => '2026-08-30T09:00:00+00:00',
            'user' => ['name' => 'Hector', 'email' => 'h@example.com', 'timezone' => 'Europe/London'],
            'loops' => [[
                'title' => 'morning press-ups',
                'description' => null,
                'type' => 'build',
                'status' => 'active',
                'chain' => [
                    'cue' => 'the kettle clicks off',
                    'craving' => 'something to do with my hands',
                    'response' => 'ten press-ups',
                    'reward' => 'the coffee tastes earned',
                ],
                'created_at' => '2026-08-01T07:00:00+00:00',
                'strategies' => [
                    [
                        'version' => 1, 'status' => 'superseded',
                        'intervention_point' => 'cue', 'approach' => 'put the mat by the kettle',
                        'rationale' => 'the cue was invisible', 'change_reason' => 'abandoned',
                        'superseded_reason' => 'the kettle is not reliable at weekends',
                        'review_at' => null, 'verdict' => 'abandoned',
                        'verdict_note' => 'the kettle is not a reliable cue on weekends',
                        'created_at' => '2026-08-01T07:00:00+00:00',
                    ],
                    [
                        'version' => 2, 'status' => 'active',
                        'intervention_point' => 'response', 'approach' => 'two press-ups, not ten',
                        'rationale' => 'ten was the barrier', 'change_reason' => null,
                        'superseded_reason' => null, 'review_at' => null,
                        'verdict' => null, 'verdict_note' => null,
                        'created_at' => '2026-08-15T07:00:00+00:00',
                    ],
                ],
                'actions' => [[
                    'title' => 'press-ups', 'description' => null,
                    'recurrence' => 'daily', 'status' => 'active',
                    'series_started_at' => '2026-08-15T07:00:00+00:00',
                    'occurrences' => [[
                        'scheduled_for' => '2026-08-16T07:00:00+00:00',
                        'fired_at' => '2026-08-16T07:00:00+00:00',
                        'outcome' => [
                            'outcome' => 'failed',
                            'reason' => '  slept through it  ',
                            'context' => 'alarm went off, I turned it off in my sleep',
                            'context_fields' => ['mood' => 'tired'],
                            'logged_at' => '2026-08-16T20:00:00+00:00',
                        ],
                    ]],
                ]],
                'notes' => [['body' => 'easier on days I sleep well', 'noted_at' => '2026-08-17T09:00:00+00:00']],
                'reflections' => [[
                    'scope' => 'loop', 'content' => 'the cue is the problem, not the response',
                    'window_start' => null, 'window_end' => null,
                    'events_count' => 12, 'created_at' => '2026-08-20T09:00:00+00:00',
                ]],
            ]],
        ];
    }

    public function test_it_renders_a_loop_with_two_versions_and_a_verdict(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        $this->assertStringContainsString('# morning press-ups', $markdown);
        $this->assertStringContainsString('the kettle clicks off', $markdown);
        $this->assertStringContainsString('Version 1', $markdown);
        $this->assertStringContainsString('Version 2', $markdown);
        $this->assertStringContainsString('abandoned', $markdown);
        $this->assertStringContainsString('the kettle is not a reliable cue on weekends', $markdown);
    }

    public function test_it_carries_a_failure_reason_verbatim(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // The stored text had surrounding whitespace and keeps it.
        $this->assertStringContainsString('  slept through it  ', $markdown);
    }

    public function test_it_carries_the_outcome_context(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // `context` is what LogOutcomeTool calls "the primary record" — the
        // mechanics of what happened. Prose that drops it is not the notebook.
        $this->assertStringContainsString(
            'alarm went off, I turned it off in my sleep',
            $markdown,
        );
    }

    public function test_it_never_scores_the_record(): void
    {
        $markdown = strtolower((new MarkdownRecordFormatter)->render($this->record()));

        // No gamification: the notebook reports what happened, it does not grade it.
        foreach (['streak', 'completion rate', '% complete', 'score', 'well done', 'great job'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $markdown);
        }
    }

    public function test_an_empty_record_renders_a_valid_document(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render([
            'exported_at' => '2026-08-30T09:00:00+00:00',
            'user' => ['name' => 'Hector', 'email' => 'h@example.com', 'timezone' => null],
            'loops' => [],
        ]);

        $this->assertStringContainsString('PatYourSelf', $markdown);
        $this->assertStringContainsString('No loops', $markdown);
    }
}
```

Run: `php artisan test --compact --filter=MarkdownRecordFormatterTest`
Expected: FAIL — `Class "App\Services\Export\MarkdownRecordFormatter" not found`.

- [ ] **Step 5: Write the Markdown formatter**

```php
<?php

namespace App\Services\Export;

/**
 * The record as prose — the lab notebook you would actually read back.
 *
 * It reports and never grades: no streaks, no completion rates, no scores.
 * An experiment with no planned length is described as open-ended rather than
 * as a countdown, and the user's own words are reproduced exactly, including
 * whatever whitespace they typed.
 */
final readonly class MarkdownRecordFormatter
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function render(array $record): string
    {
        $lines = [
            '# PatYourSelf — the record',
            '',
            "Exported {$record['exported_at']} for {$record['user']['name']} <{$record['user']['email']}>.",
            '',
        ];

        if ($record['loops'] === []) {
            $lines[] = 'No loops yet.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($record['loops'] as $loop) {
            $lines = [...$lines, ...$this->loop($loop)];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $loop
     * @return array<int, string>
     */
    private function loop(array $loop): array
    {
        $lines = [
            "# {$loop['title']}",
            '',
            "{$loop['type']} · {$loop['status']}",
            '',
        ];

        if ($loop['description'] !== null) {
            $lines[] = $loop['description'];
            $lines[] = '';
        }

        $lines[] = '## The chain';
        $lines[] = '';
        foreach (['cue' => 'Cue', 'craving' => 'Craving', 'response' => 'Response', 'reward' => 'Reward'] as $key => $label) {
            $lines[] = "- **{$label}:** ".($loop['chain'][$key] ?? '—');
        }
        $lines[] = '';

        if ($loop['strategies'] !== []) {
            $lines[] = '## Experiments';
            $lines[] = '';
            foreach ($loop['strategies'] as $strategy) {
                $lines = [...$lines, ...$this->strategy($strategy)];
            }
        }

        foreach ($loop['actions'] as $action) {
            $lines = [...$lines, ...$this->action($action)];
        }

        if ($loop['notes'] !== []) {
            $lines[] = '## Notes';
            $lines[] = '';
            foreach ($loop['notes'] as $note) {
                $lines[] = "- {$note['noted_at']} — {$note['body']}";
            }
            $lines[] = '';
        }

        if ($loop['reflections'] !== []) {
            $lines[] = '## Reflections';
            $lines[] = '';
            foreach ($loop['reflections'] as $reflection) {
                $lines[] = "### {$reflection['created_at']}";
                $lines[] = '';
                $lines[] = (string) $reflection['content'];
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @return array<int, string>
     */
    private function strategy(array $strategy): array
    {
        $lines = [
            "### Version {$strategy['version']} — intervening at ".($strategy['intervention_point'] ?? 'an unrecorded point'),
            '',
            '- **Approach:** '.($strategy['approach'] ?? '—'),
            '- **Rationale:** '.($strategy['rationale'] ?? '—'),
        ];

        // An open-ended experiment is described, never counted down.
        $lines[] = '- **Review:** '.($strategy['review_at'] ?? 'open-ended');

        if ($strategy['verdict'] !== null) {
            $lines[] = "- **Verdict:** {$strategy['verdict']}";
            if ($strategy['verdict_note'] !== null) {
                $lines[] = "- **In their words:** {$strategy['verdict_note']}";
            }
        }

        if ($strategy['superseded_reason'] !== null) {
            $lines[] = "- **Superseded because:** {$strategy['superseded_reason']}";
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<int, string>
     */
    private function action(array $action): array
    {
        $lines = [
            "## Action: {$action['title']}",
            '',
            '- **Recurrence:** '.($action['recurrence'] ?? 'one-off'),
            '- **Status:** '.$action['status'],
            '',
        ];

        if ($action['occurrences'] === []) {
            return $lines;
        }

        $lines[] = '### What happened';
        $lines[] = '';

        foreach ($action['occurrences'] as $occurrence) {
            $outcome = $occurrence['outcome'];

            if ($outcome === null) {
                $lines[] = "- {$occurrence['scheduled_for']} — not logged";

                continue;
            }

            $line = "- {$occurrence['scheduled_for']} — {$outcome['outcome']}";

            if ($outcome['reason'] !== null && $outcome['reason'] !== '') {
                $line .= " — {$outcome['reason']}";
            }

            $lines[] = $line;

            // The mechanics of what happened, in the user's own words. Indented
            // under its occasion rather than flattened into the line above, so a
            // long account stays readable. Verbatim, like the reason.
            if (($outcome['context'] ?? null) !== null && $outcome['context'] !== '') {
                $lines[] = "  - {$outcome['context']}";
            }
        }

        $lines[] = '';

        return $lines;
    }
}
```

Run: `php artisan test --compact --filter=MarkdownRecordFormatterTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\Export\JsonRecordFormatter;
use App\Services\Export\MarkdownRecordFormatter;
use App\Services\Export\RecordExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands the user their own record, in full.
 *
 * Two formats from one payload: JSON is the complete machine-readable dump,
 * Markdown is the notebook as prose. Read-only — there is deliberately no
 * importer, because a round trip means identity collisions, versioning and
 * partial-failure semantics, and nothing needs it.
 *
 * An unknown format answers JSON rather than erroring: someone hand-editing a
 * URL should get their record, not a 422.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly RecordExport $export,
        private readonly JsonRecordFormatter $json,
        private readonly MarkdownRecordFormatter $markdown,
    ) {}

    public function show(Request $request): StreamedResponse
    {
        $markdown = $request->query('format') === 'md';
        $record = $this->export->forUser($request->user());

        $body = $markdown
            ? $this->markdown->render($record)
            : $this->json->render($record);

        $filename = 'patyourself-'.now()->format('Y-m-d').($markdown ? '.md' : '.json');

        return response()->streamDownload(
            function () use ($body): void {
                echo $body;
            },
            $filename,
            ['Content-Type' => $markdown ? 'text/markdown; charset=utf-8' : 'application/json'],
        );
    }
}
```

- [ ] **Step 7: Add the route**

In `routes/web.php`, inside the existing `Route::middleware(['auth', 'verified'])->group(...)`:

```php
    // The record, in full, in the user's hands. Read-only by design — see
    // ExportController for why there is no importer.
    Route::get('export', [ExportController::class, 'show'])->name('export.show');
```

Add `use App\Http\Controllers\ExportController;` to the imports at the top.

- [ ] **Step 8: Run everything and commit**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=ExportEndpointTest
php artisan test --compact --filter=MarkdownRecordFormatterTest
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Services/Export app/Http/Controllers/ExportController.php routes/web.php tests
git commit -m "feat(export): the record can be taken out, as JSON or as prose"
```

---

### Task 3: The loops index gets `?status=` and `?q=`

The JSON API's `index()` already supports `?status=`; the web index has neither filter nor search. This closes a gap that exists only because the two surfaces were written at different times.

**Files:**
- Modify: `app/Http/Controllers/IntentionController.php` (`index()` only)
- Modify: `resources/js/pages/loops/index.tsx`
- Test: `tests/Feature/Loops/LoopsIndexFilterTest.php`

**Interfaces:**
- Produces: `loops.index` accepts `?status=` (one of `Intention::STATUSES`) and `?q=`. The Inertia page gains `filters: { status: string|null, q: string|null }`.

**Search covers the title and all four chain fields** — the cue is often what you remember.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Loops;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LoopsIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function titles(AssertableInertia $page): array
    {
        return array_column($page->toArray()['props']['intentions'], 'title');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'running', 'status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['title' => 'resting', 'status' => Intention::STATUS_PAUSED]);

        $this->actingAs($user)->get(route('loops.index', ['status' => 'paused']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', 'paused')
                ->has('intentions', 1)
                ->where('intentions.0.title', 'resting'));
    }

    public function test_search_matches_the_chain_not_just_the_title(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'press-ups', 'cue' => 'the kettle clicks off']);
        Intention::factory()->for($user)->create(['title' => 'reading', 'cue' => 'getting into bed']);

        $this->actingAs($user)->get(route('loops.index', ['q' => 'kettle']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'press-ups'));
    }

    public function test_the_filters_compose(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'press-ups', 'cue' => 'the kettle clicks off', 'status' => Intention::STATUS_ACTIVE,
        ]);
        Intention::factory()->for($user)->create([
            'title' => 'tea', 'cue' => 'the kettle clicks off', 'status' => Intention::STATUS_PAUSED,
        ]);

        $this->actingAs($user)->get(route('loops.index', ['q' => 'kettle', 'status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('intentions', 1)
                ->where('intentions.0.title', 'press-ups'));
    }

    public function test_a_percent_in_the_search_is_a_literal_not_a_wildcard(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'give 100% at the gym']);
        Intention::factory()->for($user)->create(['title' => 'read before bed']);

        // A bare `%` would wildcard and match both rows.
        $this->actingAs($user)->get(route('loops.index', ['q' => '100%']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 1));
    }

    public function test_an_unknown_status_is_ignored_rather_than_erroring(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'running']);

        $this->actingAs($user)->get(route('loops.index', ['status' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', null)
                ->has('intentions', 1));
    }

    public function test_another_users_loops_are_never_searchable(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        Intention::factory()->for($theirs)->create(['title' => 'their kettle loop', 'cue' => 'the kettle clicks off']);

        $this->actingAs($mine)->get(route('loops.index', ['q' => 'kettle']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('intentions', 0));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=LoopsIndexFilterTest`
Expected: FAIL — `Property [filters] does not exist` / all loops returned unfiltered.

- [ ] **Step 3: Filter and search in `index()`**

Replace the body of `IntentionController::index()`:

```php
    public function index(Request $request): Response
    {
        // Only a known status filters; anything else is ignored rather than
        // erroring, so a hand-edited URL still shows the user their loops.
        $status = in_array($request->query('status'), Intention::STATUSES, true)
            ? (string) $request->query('status')
            : null;

        $term = trim((string) $request->query('q', ''));
        $search = $term === '' ? null : $term;

        $intentions = $request->user()->intentions()
            ->with('activeStrategy')
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->matchTitleOrChain($inner, $search),
            ))
            ->latest()
            ->get()
            // Surface the loops the user is actively working first; the rest
            // (paused / completed / archived) settle below, newest-first within.
            ->sortBy(fn (Intention $intention): int => $intention->status === Intention::STATUS_ACTIVE ? 0 : 1)
            ->values();

        return Inertia::render('loops/index', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
            'filters' => ['status' => $status, 'q' => $search],
        ]);
    }

    /**
     * The cue is often what you remember, so search covers the whole chain and
     * not just the title.
     *
     * The term is bound, never interpolated. `%`, `_` and `\` are escaped so a
     * literal percent in a title is a percent rather than a wildcard — the
     * column list is a hardcoded allowlist, which is the only reason it is safe
     * to interpolate the column name into the raw fragment.
     */
    private function matchTitleOrChain(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        foreach (['title', 'cue', 'craving', 'response', 'reward'] as $column) {
            $query->orWhereRaw("{$column} LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
        }
    }
```

`Builder` and `Intention` are already imported in this file.

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact --filter=LoopsIndexFilterTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Add the controls to the screen**

In `resources/js/pages/loops/index.tsx`, widen the props and add a filter bar above the list:

```tsx
interface LoopsIndexProps {
    intentions: IntentionData[];
    filters: { status: string | null; q: string | null };
}

const STATUSES = ['active', 'paused', 'completed', 'archived'] as const;

/**
 * Status chips and a search box. Search covers the whole chain server-side —
 * the cue is often what you remember about a loop.
 */
function FilterBar({ filters }: { filters: LoopsIndexProps['filters'] }) {
    const [term, setTerm] = useState(filters.q ?? '');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            '/loops',
            {
                ...(filters.status ? { status: filters.status } : {}),
                ...(term.trim() ? { q: term.trim() } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <div className="mb-3 flex flex-col gap-2">
            <form onSubmit={submit}>
                <input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Search the title or the chain"
                    aria-label="Search loops"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                />
            </form>
            <div className="flex flex-wrap gap-1.5">
                <FilterChip label="All" href={hrefFor(null, filters.q)} active={filters.status === null} />
                {STATUSES.map((status) => (
                    <FilterChip
                        key={status}
                        label={status}
                        href={hrefFor(status, filters.q)}
                        active={filters.status === status}
                    />
                ))}
            </div>
        </div>
    );
}

function hrefFor(status: string | null, q: string | null): string {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (q) params.set('q', q);
    const query = params.toString();

    return query ? `/loops?${query}` : '/loops';
}

function FilterChip({ label, href, active }: { label: string; href: string; active: boolean }) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'rounded-full border px-2.5 py-1 text-xs capitalize transition-colors',
                active
                    ? 'border-foreground/30 bg-accent text-foreground'
                    : 'border-border text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
        </Link>
    );
}
```

Add `useState` to the React import and `router` to the `@inertiajs/react` import.

In the default export, render `<FilterBar filters={filters} />` above the list, and make the empty state honest about which empty it is:

```tsx
    const filtering = filters.status !== null || filters.q !== null;

    return (
        <CoachLayout title="Loops" bottomNav={<BottomNav />} wide>
            <FilterBar filters={filters} />
            {intentions.length === 0 ? (
                filtering ? <NoMatches /> : <EmptyState />
            ) : (
                /* ...existing list markup, unchanged... */
            )}
        </CoachLayout>
    );
```

```tsx
function NoMatches() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 text-center">
            <p className="text-sm text-muted-foreground">
                No loops match that.
            </p>
            <Link href="/loops" className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground">
                Clear the filters
            </Link>
        </div>
    );
}
```

The existing `EmptyState` stays exactly as it is — its copy tells a first-time user how loops get created, which is the wrong thing to say to someone whose search just missed.

**The `Button` primitive from `@/patyourself/primitives` takes no `className`.** Wrap it in a div if you need positioning. (The markup above uses `Link` and a bare `input`, so this only bites if you add one.)

**While you are in this file, fix the pre-existing TypeScript error at line 104.** `loop.strategy` is `ActiveStrategySummary | null | undefined` but `experimentState()` takes `ActiveStrategySummary | null`. Widen the parameter to accept `undefined`, or pass `loop.strategy ?? null`. The repo's tsc error count drops from 2 to 1 — the remaining one is `catch-up.tsx:132`, which stays out of scope.

- [ ] **Step 6: Run everything and commit**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact --filter=LoopsIndexFilterTest
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/IntentionController.php resources/js/pages/loops/index.tsx tests/Feature/Loops
git commit -m "feat(loops): the index gains the filter the API already had, and a search over the chain"
```

`npx tsc --noEmit` should now report **1** error, not 2.

---

### Task 4: The failure alert that does not ride the queue that failed

`DigestDispatcher` stamps `digest_last_sent_on` immediately after `notify()`, which only *enqueues*. If the job exhausts its retries the user loses that day's digest and nothing says so. The spec that introduced the digest named a `failed_jobs` alert as the mitigation and did not build it.

**Files:**
- Create: `app/Services/Alerts/FailedJobsAlert.php`
- Create: `app/Notifications/FailedJobsNotification.php`
- Create: `app/Console/Commands/AlertFailedJobs.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Alerts/FailedJobsAlertTest.php`

**Interfaces:**
- Produces: `FailedJobsAlert::sendIfAny(): int` — returns how many newly-failed jobs were reported (0 when there is nothing to say).
- Produces: `php artisan jobs:alert-failed`, scheduled hourly.

**Two decisions, both load-bearing:**

1. **It sends synchronously**, not through the queue. An alert about a broken queue that is itself queued is not an alert. `FailedJobsNotification` pins mail to the `sync` connection via `viaConnections()`, the same mechanism `ActionDueNotification` uses to pin its database channel.
2. **The high-water mark is a cache key**, not a new table. If the cache is cleared the worst case is one duplicate alert, which is the right direction to fail in.

**The recipient** is the first registered user — this is a single-user app in practice and the owner is account one. It is spelled out in the service's docblock so it is a stated assumption rather than a silent one; if the app ever becomes multi-user this needs an explicit owner flag.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FailedJobsAlertTest extends TestCase
{
    use RefreshDatabase;

    private function recordFailure(string $uuid = 'a-uuid'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: the worker died',
            'failed_at' => now(),
        ]);
    }

    public function test_it_alerts_the_owner_when_a_job_has_failed(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $this->recordFailure();

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(1, $reported);
        Notification::assertSentTo($owner, FailedJobsNotification::class);
    }

    public function test_it_does_not_re_alert_on_the_next_tick(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        app(FailedJobsAlert::class)->sendIfAny();
        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(0, $reported);
        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    public function test_it_says_nothing_when_no_job_has_failed(): void
    {
        Notification::fake();
        User::factory()->create();

        $this->assertSame(0, app(FailedJobsAlert::class)->sendIfAny());

        Notification::assertNothingSent();
    }

    public function test_it_does_not_advance_the_mark_when_sending_throws(): void
    {
        $owner = User::factory()->create();
        $this->recordFailure();

        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        try {
            app(FailedJobsAlert::class)->sendIfAny();
        } catch (\RuntimeException) {
            // expected
        }

        // The mark did not move, so the next tick still sees the failure.
        Notification::fake();
        $this->assertSame(1, app(FailedJobsAlert::class)->sendIfAny());
    }

    public function test_the_notification_sends_on_the_sync_connection(): void
    {
        // An alert about a broken queue that is itself queued is not an alert.
        $this->assertSame(
            ['mail' => 'sync'],
            (new FailedJobsNotification(1, 'RuntimeException: the worker died'))->viaConnections(),
        );
    }

    public function test_the_command_runs(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        $this->artisan('jobs:alert-failed')->assertSuccessful();

        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=FailedJobsAlertTest`
Expected: FAIL — `Target class [App\Services\Alerts\FailedJobsAlert] does not exist`.

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner that background jobs have failed since the last check.
 *
 * Deliberately NOT queued, and pinned to the sync connection: this is an alert
 * about the queue, so routing it through the queue would mean the failure that
 * matters most is the one that never gets reported.
 */
class FailedJobsNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $count,
        public readonly ?string $latestException,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['mail' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $noun = $this->count === 1 ? 'job has' : 'jobs have';

        return (new MailMessage)
            ->subject("PatYourSelf: {$this->count} background {$noun} failed")
            ->line("{$this->count} background {$noun} failed since the last check.")
            ->line('Reminders and digests ride that queue, so some may not have been delivered.')
            ->line('Most recent exception:')
            ->line($this->latestException ?? 'No exception was recorded.');
    }
}
```

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Services\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Mails the owner when a background job has failed since the last check.
 *
 * The digest stamps `digest_last_sent_on` straight after `notify()`, which only
 * enqueues — so an exhausted job silently costs a user that day's digest. This
 * is the mitigation the digest spec named and did not build.
 *
 * The high-water mark is a cache key rather than a new table: if the cache is
 * cleared the worst case is one duplicate alert, which is the right direction
 * to fail in. The mark only advances after the notification is actually sent,
 * so a mail failure means the next tick tries again instead of swallowing it.
 *
 * The recipient is the first registered account. This app is single-user in
 * practice and the owner is account one; a multi-user future needs an explicit
 * owner flag rather than this assumption.
 */
final readonly class FailedJobsAlert
{
    private const MARK = 'alerts.failed-jobs.high-water-mark';

    public function sendIfAny(): int
    {
        $since = Cache::get(self::MARK);
        $checkedAt = Carbon::now();

        $failures = DB::table('failed_jobs')
            ->when($since !== null, fn ($query) => $query->where('failed_at', '>', $since))
            ->orderBy('failed_at')
            ->get();

        if ($failures->isEmpty()) {
            Cache::forever(self::MARK, $checkedAt);

            return 0;
        }

        $owner = User::query()->oldest('id')->first();

        if ($owner === null) {
            return 0;
        }

        Notification::send(
            $owner,
            new FailedJobsNotification($failures->count(), $failures->last()->exception),
        );

        // Only now. If sending threw, the mark is untouched and the next tick
        // reports the same failures rather than losing them.
        Cache::forever(self::MARK, $checkedAt);

        return $failures->count();
    }
}
```

- [ ] **Step 5: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Console\Command;

/**
 * Hourly check for failed background jobs. All logic lives in the service so it
 * can be feature-tested directly, matching SendReminderDigests.
 */
class AlertFailedJobs extends Command
{
    protected $signature = 'jobs:alert-failed';

    protected $description = 'Mail the owner if background jobs have failed since the last check';

    public function handle(FailedJobsAlert $alert): int
    {
        $reported = $alert->sendIfAny();

        $this->components->info(
            $reported === 0 ? 'No new failed jobs.' : "Reported {$reported} failed job(s).",
        );

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Schedule it**

In `routes/console.php`, add the import and the schedule beside the existing two:

```php
use App\Console\Commands\AlertFailedJobs;
```

```php
// The queue's own smoke alarm. Hourly is often enough to matter and rare
// enough not to nag; it sends synchronously, because an alert about a broken
// queue that is itself queued would never arrive.
Schedule::command(AlertFailedJobs::class)->hourly()->withoutOverlapping();
```

- [ ] **Step 7: Run everything and commit**

```bash
php artisan test --compact --filter=FailedJobsAlertTest
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add app/Services/Alerts app/Notifications/FailedJobsNotification.php app/Console/Commands/AlertFailedJobs.php routes/console.php tests/Feature/Alerts
git commit -m "feat(alerts): the queue gets a smoke alarm that does not ride the queue"
```

---

## Phase C self-review checklist

- [ ] `php artisan test --compact` — green, 728 baseline plus the new tests
- [ ] `npx vitest run` — 207 green
- [ ] `npx tsc --noEmit` — **1** error (`catch-up.tsx:132`), down from 2
- [ ] `vendor/bin/pint --dirty --format agent` — clean
- [ ] `GET /export` downloads a JSON file; `?format=md` downloads Markdown; `?format=pdf` downloads JSON
- [ ] A failure reason typed with surrounding whitespace comes out of the export with that whitespace intact
- [ ] The loops index filters by status, searches the cue, and treats `%` as a literal
- [ ] `php artisan jobs:alert-failed` mails on a new failure and stays quiet on the next run
