# Action Authoring (SP1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn a Strategy into a concrete, scheduled (or anchored) Action the user can see and edit, so the app finally has time-bound things to do — without firing anything yet (that is SP2).

**Architecture:** The `IntentionAuthor` and `Strategist` agents gain an `action` block in their structured output (title + schedule). A pure `app/Services/Scheduling` layer (`Recurrence` enum + `Schedule` value object) converts an authored local `HH:MM` + recurrence into a UTC `scheduled_for`, and exposes the `advance()` math SP2 will reuse. `AuthorIntention` persists the first Action bound to Strategy v1; `ReviseStrategy` archives the prior open Action and authors a fresh one bound to the new Strategy version (using the agent's re-proposed schedule when present, otherwise inheriting the prior cadence). A `PATCH /actions/{action}` endpoint lets the user re-schedule. The card surfaces the schedule and an inline editor.

**Tech Stack:** Laravel 13 / PHP 8.4, Laravel AI SDK (Anthropic, Haiku agents with `::fake()` in tests), Inertia v3 + React 19 + TypeScript, Tailwind v4, PHPUnit, Vitest, Pint.

---

## Architecture notes (read once before starting)

- **Invariant:** exactly one active Action per active Strategy. Enforced only in the two write paths below (the sole Action authors).
- **Schedule storage:** `actions.scheduled_for` (UTC datetime, nullable) = next concrete fire time. `actions.recurrence` (string, nullable) = `daily|weekdays|weekly`, or **null for one-off** (`once`) and for **anchored** actions. Both columns already exist + are nullable; `scheduled_for` is indexed.
- **Three action states:** scheduled-recurring (`scheduled_for` set + `recurrence` set), one-off (`scheduled_for` set + `recurrence` null), anchored/un-fired (`scheduled_for` null + `recurrence` null; the `cue` text + `metadata.anchor` carry "after morning coffee").
- **New action status:** authored as `pending`. The card still renders + logs it (`Intention::activeAction()` includes `pending`). SP2 flips `pending`→`active` at fire time.
- **Revision fallback:** on revise, if the `Strategist` agent supplies a usable `action` block, use it; otherwise inherit the prior active Action's `scheduled_for`/`recurrence` and title from the new strategy's `approach`. (A stale inherited `scheduled_for` is fine here — SP1 never fires; SP2 recomputes via `Schedule::advance()` on activation.)
- **Timezone:** "what time" needs the user's IANA tz. Add `users.timezone`, captured once from the browser. All `HH:MM`↔UTC conversion goes through `Schedule` using that tz.
- **No firing in SP1:** no scheduler, no notifications, no recurrence spawning. SP1 stores schedule data and lets the user edit it.

## File structure

**Create**
- `database/migrations/2026_06_13_000001_add_timezone_to_users_table.php` — `users.timezone` column.
- `app/Services/Scheduling/Recurrence.php` — recurrence enum + token mapping.
- `app/Services/Scheduling/Schedule.php` — first/next occurrence math (UTC ↔ local).
- `app/Services/Coach/Authoring/AuthoredAction.php` — validated authored-action VO.
- `app/Actions/RescheduleAction.php` — recompute + persist a schedule edit.
- `app/Http/Requests/RescheduleActionRequest.php` — validate the edit payload.
- `app/Http/Controllers/ActionController.php` — web reschedule endpoint.
- `app/Http/Controllers/Api/ActionController.php` — API reschedule endpoint.
- `app/Http/Controllers/Settings/TimezoneController.php` — one-time tz capture.
- Tests: `tests/Unit/Scheduling/RecurrenceTest.php`, `tests/Unit/Scheduling/ScheduleTest.php`, `tests/Unit/Coach/AuthoredActionTest.php`, `tests/Feature/Actions/RescheduleActionWebTest.php`, `tests/Feature/Settings/TimezoneCaptureTest.php`.

**Modify**
- `app/Ai/Agents/IntentionAuthor.php` — `action` block (schema + prompt).
- `app/Ai/Agents/Strategist.php` — optional `action` block (schema + prompt).
- `app/Services/Coach/Authoring/AuthoredIntention.php` — carry `AuthoredAction`.
- `app/Actions/AuthorIntention.php` — persist the Action.
- `app/Actions/ReviseStrategy.php` — archive old + author new Action.
- `app/Policies/ActionPolicy.php` — `update` ability.
- `app/Http/Resources/IntentionResource.php` — serialize the schedule.
- `app/Models/User.php` — `timezone` fillable.
- `database/factories/ActionFactory.php` — `metadata.schedule_kind` for realistic seeds.
- `routes/web.php`, `routes/api.php`, `routes/settings.php` — new routes.
- `resources/js/patyourself/types.ts` — `ActiveActionData` schedule fields.
- `resources/js/patyourself/chat/action-card.tsx` — schedule chip + editor.
- `resources/js/patyourself/chat/coach-client.ts` — `rescheduleAction` + `patch` helper.
- `resources/js/patyourself/chat/chat-home.tsx` — wire `reschedule`.
- `resources/js/pages/coach.tsx` — one-time tz capture.
- Existing tests touched: `tests/Feature/AuthorIntentionTest.php`, `tests/Feature/ReviseStrategyTest.php`, `resources/js/patyourself/chat/action-card.test.tsx`.

---

## Task 1: `users.timezone` column

**Files:**
- Create: `database/migrations/2026_06_13_000001_add_timezone_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Settings/TimezoneCaptureTest.php` (written in Task 11; here just migrate + fillable)

- [ ] **Step 1: Create the migration**

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
            // IANA timezone (e.g. "America/New_York"); null until the browser
            // reports it on first authenticated load. Used to localise action
            // schedules. See app/Services/Scheduling/Schedule.php.
            $table->string('timezone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
```

- [ ] **Step 2: Add `timezone` to the User model's fillable**

Open `app/Models/User.php`. If it declares a `protected $fillable = [...]` array, add `'timezone'` to it. If it uses the `#[Fillable([...])]` attribute (as other models here do), add `'timezone'` to that list. Match whichever style the file already uses.

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: "DONE" for `add_timezone_to_users_table`.

- [ ] **Step 4: Verify the column exists**

Run: `php artisan tinker --execute 'echo Schema::hasColumn("users", "timezone") ? "yes" : "no";'`
Expected: `yes`

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_13_000001_add_timezone_to_users_table.php app/Models/User.php
git commit -m "feat(users): add timezone column for action scheduling"
```

---

## Task 2: `Recurrence` enum

**Files:**
- Create: `app/Services/Scheduling/Recurrence.php`
- Test: `tests/Unit/Scheduling/RecurrenceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Recurrence;
use PHPUnit\Framework\TestCase;

class RecurrenceTest extends TestCase
{
    public function test_maps_known_tokens(): void
    {
        $this->assertSame(Recurrence::Daily, Recurrence::tryFromToken('daily'));
        $this->assertSame(Recurrence::Weekdays, Recurrence::tryFromToken('weekdays'));
        $this->assertSame(Recurrence::Weekly, Recurrence::tryFromToken('weekly'));
    }

    public function test_once_null_and_unknown_tokens_are_one_off(): void
    {
        $this->assertNull(Recurrence::tryFromToken('once'));
        $this->assertNull(Recurrence::tryFromToken(null));
        $this->assertNull(Recurrence::tryFromToken('fortnightly'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RecurrenceTest`
Expected: FAIL — "Class App\Services\Scheduling\Recurrence not found".

- [ ] **Step 3: Implement the enum**

```php
<?php

namespace App\Services\Scheduling;

/**
 * The recurrence rules SP1 supports. The authoring layer also accepts the token
 * "once" (and null), which both mean a one-off action — represented as a null
 * recurrence (a set scheduled_for with no repeat rule), so they map to null here.
 */
enum Recurrence: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';

    /**
     * Map an authored recurrence token to a case, or null for a one-off
     * ("once" / null / anything not a recurring rule).
     */
    public static function tryFromToken(?string $token): ?self
    {
        return match ($token) {
            'daily' => self::Daily,
            'weekdays' => self::Weekdays,
            'weekly' => self::Weekly,
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=RecurrenceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scheduling/Recurrence.php tests/Unit/Scheduling/RecurrenceTest.php
git commit -m "feat(scheduling): recurrence enum with token mapping"
```

---

## Task 3: `Schedule` value object

**Files:**
- Create: `app/Services/Scheduling/Schedule.php`
- Test: `tests/Unit/Scheduling/ScheduleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    public function test_first_daily_occurrence_today_when_time_is_ahead(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 06:00:00'), '07:00', Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-13 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_first_daily_occurrence_rolls_to_tomorrow_when_time_passed(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 08:00:00'), '07:00', Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_first_weekday_occurrence_skips_the_weekend(): void
    {
        $friday = $this->at('2026-06-12 08:00:00');
        $this->assertTrue($friday->isFriday()); // self-documenting anchor

        $next = (new Schedule)->firstOccurrence($friday, '07:00', Recurrence::Weekdays, 'UTC');

        $this->assertSame('2026-06-15 07:00:00', $next->utc()->format('Y-m-d H:i:s')); // Monday
    }

    public function test_first_occurrence_converts_local_time_to_utc(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 00:00:00'), '07:00', Recurrence::Daily, 'America/New_York');

        // 07:00 EDT (UTC-4) the next NY morning == 11:00 UTC.
        $this->assertSame('2026-06-13 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_anchored_action_has_no_occurrence(): void
    {
        $this->assertNull((new Schedule)->firstOccurrence($this->at('2026-06-13 06:00:00'), null, null, 'UTC'));
    }

    public function test_advance_rolls_each_recurrence_forward(): void
    {
        $schedule = new Schedule;
        $current = $this->at('2026-06-12 11:00:00'); // Fri 07:00 EDT

        $this->assertSame('2026-06-13 11:00:00', $schedule->advance($current, Recurrence::Daily, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 11:00:00', $schedule->advance($current, Recurrence::Weekdays, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-19 11:00:00', $schedule->advance($current, Recurrence::Weekly, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($schedule->advance($current, null, 'America/New_York'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: FAIL — "Class App\Services\Scheduling\Schedule not found".

- [ ] **Step 3: Implement the value object**

```php
<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Pure schedule math for action triggers. Turns an authored local time-of-day +
 * recurrence into the first UTC fire time, and rolls a recurring action forward
 * to its next fire time. Stored datetimes are UTC; the user's IANA timezone
 * localises them. SP2's trigger engine reuses advance() after firing.
 */
final readonly class Schedule
{
    /**
     * The first fire time at or after `now`, in UTC. Null when there is no clock
     * time (an anchored action the scheduler never fires).
     */
    public function firstOccurrence(CarbonImmutable $now, ?string $localTime, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        if ($localTime === null) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $localTime));

        $local = $now->setTimezone($timezone);
        $candidate = $local->setTime($hour, $minute, 0);

        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate = $candidate->addDay();
        }

        if ($recurrence === Recurrence::Weekdays) {
            $candidate = $this->skipWeekend($candidate);
        }

        return $candidate->utc();
    }

    /**
     * The next fire time after a recurring action fires, in UTC. Null for a
     * one-off (no recurrence). Weekday math is evaluated in the user's timezone.
     */
    public function advance(CarbonImmutable $current, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        $local = $current->setTimezone($timezone);

        return match ($recurrence) {
            Recurrence::Daily => $local->addDay()->utc(),
            Recurrence::Weekdays => $this->skipWeekend($local->addDay())->utc(),
            Recurrence::Weekly => $local->addWeek()->utc(),
            null => null,
        };
    }

    private function skipWeekend(CarbonImmutable $date): CarbonImmutable
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scheduling/Schedule.php tests/Unit/Scheduling/ScheduleTest.php
git commit -m "feat(scheduling): Schedule VO for first/next occurrence math"
```

---

## Task 4: `AuthoredAction` value object

**Files:**
- Create: `app/Services/Coach/Authoring/AuthoredAction.php`
- Test: `tests/Unit/Coach/AuthoredActionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Coach\Exceptions\CoachException;
use PHPUnit\Framework\TestCase;

class AuthoredActionTest extends TestCase
{
    public function test_parses_a_clock_action(): void
    {
        $action = AuthoredAction::fromStructured([
            'title' => 'Set your shoes by the door',
            'description' => 'A visible cue.',
            'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
        ]);

        $this->assertSame('Set your shoes by the door', $action->title);
        $this->assertSame('clock', $action->kind);
        $this->assertSame('07:00', $action->time);
        $this->assertSame('daily', $action->recurrence);
        $this->assertNull($action->anchor);
    }

    public function test_parses_an_anchored_action(): void
    {
        $action = AuthoredAction::fromStructured([
            'title' => 'Do ten push-ups',
            'schedule' => ['kind' => 'anchored', 'anchor' => 'after morning coffee'],
        ]);

        $this->assertSame('anchored', $action->kind);
        $this->assertSame('after morning coffee', $action->anchor);
        $this->assertNull($action->time);
        $this->assertNull($action->recurrence);
    }

    public function test_absent_block_returns_null(): void
    {
        $this->assertNull(AuthoredAction::fromStructured(null));
        $this->assertNull(AuthoredAction::fromStructured([]));
    }

    public function test_rejects_a_bad_clock_time(): void
    {
        $this->expectException(CoachException::class);

        AuthoredAction::fromStructured([
            'title' => 'x',
            'schedule' => ['kind' => 'clock', 'time' => '7am', 'recurrence' => 'daily'],
        ]);
    }

    public function test_rejects_anchored_without_anchor(): void
    {
        $this->expectException(CoachException::class);

        AuthoredAction::fromStructured(['title' => 'x', 'schedule' => ['kind' => 'anchored']]);
    }

    public function test_try_from_structured_swallows_malformed(): void
    {
        $this->assertNull(AuthoredAction::tryFromStructured(['title' => 'x', 'schedule' => ['kind' => 'nope']]));
        $this->assertNotNull(AuthoredAction::tryFromStructured([
            'title' => 'ok',
            'schedule' => ['kind' => 'clock', 'time' => '08:30', 'recurrence' => 'once'],
        ]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=AuthoredActionTest`
Expected: FAIL — "Class App\Services\Coach\Authoring\AuthoredAction not found".

- [ ] **Step 3: Implement the value object**

```php
<?php

namespace App\Services\Coach\Authoring;

use App\Services\Coach\Exceptions\CoachException;

/**
 * The concrete, schedulable action the coach proposes alongside a strategy: what
 * to do and when. A "clock" action carries a local HH:MM + recurrence the
 * scheduler can fire; an "anchored" action carries an event phrase ("after
 * coffee") and is stored but never auto-fired. Carries no persistence concerns;
 * AuthorIntention / ReviseStrategy turn it into an Action row.
 */
final readonly class AuthoredAction
{
    private const KINDS = ['clock', 'anchored'];

    private const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];

    public function __construct(
        public string $title,
        public ?string $description,
        public string $kind,
        public ?string $time,
        public ?string $recurrence,
        public ?string $anchor,
    ) {}

    /**
     * Build from the agent's `action` sub-array. Returns null when the block is
     * absent. Throws when it is present but structurally invalid, so a malformed
     * response writes nothing (consistent with the other authoring guards).
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws CoachException
     */
    public static function fromStructured(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '') {
            throw CoachException::emptyResponse('intention-author');
        }

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $kind = is_string($schedule['kind'] ?? null) ? trim($schedule['kind']) : '';
        if (! in_array($kind, self::KINDS, true)) {
            throw CoachException::emptyResponse('intention-author');
        }

        $time = null;
        $recurrence = null;
        $anchor = null;

        if ($kind === 'clock') {
            $time = is_string($schedule['time'] ?? null) ? trim($schedule['time']) : '';
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw CoachException::emptyResponse('intention-author');
            }

            $recurrence = is_string($schedule['recurrence'] ?? null) ? trim($schedule['recurrence']) : 'once';
            if (! in_array($recurrence, self::RECURRENCES, true)) {
                throw CoachException::emptyResponse('intention-author');
            }
        } else {
            $anchor = is_string($schedule['anchor'] ?? null) ? trim($schedule['anchor']) : '';
            if ($anchor === '') {
                throw CoachException::emptyResponse('intention-author');
            }
        }

        return new self(
            title: $title,
            description: isset($data['description']) ? (($d = trim((string) $data['description'])) !== '' ? $d : null) : null,
            kind: $kind,
            time: $time !== '' ? $time : null,
            recurrence: $recurrence,
            anchor: $anchor !== '' ? $anchor : null,
        );
    }

    /**
     * Lenient variant for the revision path: returns null instead of throwing on
     * a malformed or partial block, so ReviseStrategy can fall back to inheriting
     * the prior cadence.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function tryFromStructured(?array $data): ?self
    {
        try {
            return self::fromStructured($data);
        } catch (CoachException) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AuthoredActionTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Coach/Authoring/AuthoredAction.php tests/Unit/Coach/AuthoredActionTest.php
git commit -m "feat(coach): AuthoredAction VO validates authored schedules"
```

---

## Task 5: `IntentionAuthor` agent emits an `action` block

**Files:**
- Modify: `app/Ai/Agents/IntentionAuthor.php`

This task has no standalone test — its output is exercised end-to-end in Task 7 (the `::fake()` payload includes the `action` block). Treat Task 7's tests as the verification.

- [ ] **Step 1: Add `action` to the schema**

In `app/Ai/Agents/IntentionAuthor.php`, inside `schema()`, append an `action` key after the `strategy` key:

```php
            'action' => $schema->object(fn ($schema) => [
                'title' => $schema->string()->max(255)->required(),
                'description' => $schema->string()->max(2000),
                'schedule' => $schema->object(fn ($schema) => [
                    'kind' => $schema->string()->enum(['clock', 'anchored'])->required(),
                    'time' => $schema->string(),
                    'recurrence' => $schema->string()->enum(['once', 'daily', 'weekdays', 'weekly']),
                    'anchor' => $schema->string()->max(255),
                ])->required(),
            ])->required(),
```

- [ ] **Step 2: Extend the prompt's JSON contract**

In `instructions()`, inside the returned JSON object (after the `strategy` block), add the `action` field, and append the authoring rules. The JSON contract gains:

```text
          "action": {            // the single concrete thing to do, and when
            "title":       string,  // imperative, e.g. "Set your shoes by the door"
            "description": string,  // optional one-liner
            "schedule": {
              "kind":       "clock | anchored",
              "time":       "HH:MM",   // 24h local time, when kind=clock
              "recurrence": "once | daily | weekdays | weekly",  // when kind=clock
              "anchor":     string     // event phrase, when kind=anchored, e.g. "after morning coffee"
            }
          }
```

And add this paragraph just above "Return ONE JSON object":

```text
        Also propose the first concrete action and WHEN to do it:
        - If the user states or clearly implies a clock time, set schedule.kind
          to "clock" with that time and a recurrence.
        - If the habit is naturally anchored to an existing routine, set
          schedule.kind to "anchored" with a short anchor phrase and omit time.
        - Otherwise pick a sensible default time and "daily" — the user can adjust.
        The action.title is an imperative restatement of the strategy's approach.
```

- [ ] **Step 3: Verify the file still parses**

Run: `php -l app/Ai/Agents/IntentionAuthor.php`
Expected: "No syntax errors detected".

- [ ] **Step 4: Run Pint on the file**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean / formatted.

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Agents/IntentionAuthor.php
git commit -m "feat(ai): IntentionAuthor proposes a scheduled action"
```

---

## Task 6: `AuthoredIntention` carries the `AuthoredAction`

**Files:**
- Modify: `app/Services/Coach/Authoring/AuthoredIntention.php`
- Test: `tests/Unit/Coach/AuthoredIntentionActionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Authoring\AuthoredIntention;
use PHPUnit\Framework\TestCase;

class AuthoredIntentionActionTest extends TestCase
{
    public function test_parses_the_action_block(): void
    {
        $authored = AuthoredIntention::fromStructured([
            'title' => 'Morning walk',
            'type' => 'build',
            'cue' => 'Coffee finishes',
            'craving' => 'Feel awake',
            'response' => 'Walk 15 min',
            'reward' => 'Energy',
            'strategy' => ['intervention_point' => 'cue', 'approach' => 'Shoes by the door'],
            'action' => [
                'title' => 'Put shoes by the door',
                'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
            ],
        ], 'haiku', 'intention-authoring@1');

        $this->assertNotNull($authored->action);
        $this->assertSame('Put shoes by the door', $authored->action->title);
        $this->assertSame('07:00', $authored->action->time);
    }

    public function test_action_is_null_when_absent(): void
    {
        $authored = AuthoredIntention::fromStructured([
            'title' => 'Morning walk',
            'type' => 'build',
            'cue' => 'c', 'craving' => 'c', 'response' => 'r', 'reward' => 'r',
        ], 'haiku');

        $this->assertNull($authored->action);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=AuthoredIntentionActionTest`
Expected: FAIL — "Too few arguments" / "Undefined property ...->action".

- [ ] **Step 3: Add the `action` property + parsing**

In `app/Services/Coach/Authoring/AuthoredIntention.php`:

a) Add the import at the top: `use App\Services\Coach\Authoring\AuthoredAction;` is unnecessary (same namespace) — no import needed.

b) Add a constructor parameter as the **last** property (after `$promptVersion`):

```php
        public ?string $promptVersion = null,
        public ?AuthoredAction $action = null,
```

c) In `fromStructured()`, just before the final `return new self(`, build the action:

```php
        $authoredAction = AuthoredAction::fromStructured(
            is_array($data['action'] ?? null) ? $data['action'] : null,
        );
```

d) Pass it in the `return new self(...)` call (named arg, append at the end):

```php
            promptVersion: $promptVersion,
            action: $authoredAction,
        );
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AuthoredIntentionActionTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Coach/Authoring/AuthoredIntention.php tests/Unit/Coach/AuthoredIntentionActionTest.php
git commit -m "feat(coach): AuthoredIntention carries the authored action"
```

---

## Task 7: `AuthorIntention` persists the scheduled Action

**Files:**
- Modify: `app/Actions/AuthorIntention.php`
- Test: `tests/Feature/AuthorIntentionTest.php` (extend)

- [ ] **Step 1: Extend the existing test payload + add new tests**

In `tests/Feature/AuthorIntentionTest.php`, add an `action` block to `validPayload()` (so the happy path reflects reality), then add two tests. The `validPayload()` return array gains:

```php
            'action' => [
                'title' => 'Put walking shoes by the coffee machine',
                'description' => 'A visible cue the night before.',
                'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
            ],
```

Add these test methods (and `use App\Models\Action;` at the top):

```php
    public function test_authors_a_scheduled_action_bound_to_the_strategy(): void
    {
        IntentionAuthor::fake([$this->validPayload()]);
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(AuthorIntention::class)->handle($user, 'I want more energy');

        $action = $intention->actions()->first();
        $this->assertNotNull($action);
        $this->assertSame('Put walking shoes by the coffee machine', $action->title);
        $this->assertSame($intention->activeStrategy->id, $action->strategy_id);
        $this->assertSame(Action::STATUS_PENDING, $action->status);
        $this->assertSame('daily', $action->recurrence);
        $this->assertNotNull($action->scheduled_for);
        $this->assertSame('07:00', $action->scheduled_for->utc()->format('H:i'));
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_anchored_action_has_no_schedule(): void
    {
        $payload = $this->validPayload();
        $payload['action'] = [
            'title' => 'Do ten push-ups',
            'schedule' => ['kind' => 'anchored', 'anchor' => 'after morning coffee'],
        ];
        IntentionAuthor::fake([$payload]);
        $user = User::factory()->create(['timezone' => 'UTC']);

        $intention = app(AuthorIntention::class)->handle($user, 'goal');

        $action = $intention->actions()->first();
        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->recurrence);
        $this->assertSame('after morning coffee', $action->metadata['anchor']);
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test --compact --filter=AuthorIntentionTest`
Expected: FAIL — no Action is created yet (`assertNotNull($action)` fails).

- [ ] **Step 3: Persist the Action in `AuthorIntention::persist()`**

In `app/Actions/AuthorIntention.php`:

a) Add imports:

```php
use App\Models\Action;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
```

b) Rewrite the `if ($authored->strategy !== null) { ... }` block in `persist()` to capture the strategy and create the action:

```php
        if ($authored->strategy !== null) {
            $strategy = $intention->strategies()->create([
                'version' => 1,
                'status' => Strategy::STATUS_ACTIVE,
                'intervention_point' => $authored->strategy->interventionPoint,
                'approach' => $authored->strategy->approach,
                'rationale' => $authored->strategy->rationale,
                'change_reason' => Strategy::REASON_INITIAL,
                'metadata' => array_filter(['prompt_version' => $authored->promptVersion]),
            ]);

            $intention->setRelation('activeStrategy', $intention->activeStrategy()->first());

            if ($authored->action !== null) {
                $this->persistAction($intention, $strategy, $user, $authored->action);
            }
        }

        return $intention;
    }

    private function persistAction(Intention $intention, Strategy $strategy, User $user, \App\Services\Coach\Authoring\AuthoredAction $action): void
    {
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $scheduledFor = (new Schedule)->firstOccurrence(
            CarbonImmutable::now(),
            $action->time,
            $recurrence,
            $timezone,
        );

        $intention->actions()->create([
            'strategy_id' => $strategy->id,
            'title' => $action->title,
            'description' => $action->description,
            'scheduled_for' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => array_filter([
                'schedule_kind' => $action->kind,
                'anchor' => $action->anchor,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
```

(Remove the old `return $intention;` that previously closed `persist()` — it now lives after the `if` block above.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AuthorIntentionTest`
Expected: PASS (all methods, including the two new ones).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/AuthorIntention.php tests/Feature/AuthorIntentionTest.php
git commit -m "feat(coach): AuthorIntention persists a scheduled action for strategy v1"
```

---

## Task 8: `Strategist` agent emits an optional `action` block

**Files:**
- Modify: `app/Ai/Agents/Strategist.php`

Verified by Task 9's tests. Mirrors Task 5 but the block is **not** required (revision may inherit the prior cadence).

- [ ] **Step 1: Add `action` to the schema**

In `app/Ai/Agents/Strategist.php`, inside `schema()`, append after `rationale`:

```php
            'action' => $schema->object(fn ($schema) => [
                'title' => $schema->string()->max(255),
                'description' => $schema->string()->max(2000),
                'schedule' => $schema->object(fn ($schema) => [
                    'kind' => $schema->string()->enum(['clock', 'anchored']),
                    'time' => $schema->string(),
                    'recurrence' => $schema->string()->enum(['once', 'daily', 'weekdays', 'weekly']),
                    'anchor' => $schema->string()->max(255),
                ]),
            ]),
```

- [ ] **Step 2: Extend the prompt's JSON contract**

In `instructions()`, add the optional action field to the JSON object and a guiding line. The JSON gains (after `rationale`):

```text
          "action": {           // OPTIONAL — only when the cadence should change
            "title":       string,
            "schedule": { "kind": "clock | anchored", "time": "HH:MM", "recurrence": "once | daily | weekdays | weekly", "anchor": string }
          }
```

And add this line below the RESTRATEGIZE paragraph:

```text
        If (and only if) the failure was about timing — the user tried at the
        wrong moment — propose a new action.schedule. Otherwise omit action and
        the existing cadence is kept.
```

- [ ] **Step 3: Verify syntax + format**

Run: `php -l app/Ai/Agents/Strategist.php && vendor/bin/pint --dirty --format agent`
Expected: "No syntax errors detected" + clean.

- [ ] **Step 4: Commit**

```bash
git add app/Ai/Agents/Strategist.php
git commit -m "feat(ai): Strategist may re-propose an action schedule on revision"
```

---

## Task 9: `ReviseStrategy` archives the old Action and authors a new one

**Files:**
- Modify: `app/Actions/ReviseStrategy.php`
- Test: `tests/Feature/ReviseStrategyTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReviseStrategyTest.php` (add `use App\Models\Action;` at the top):

```php
    public function test_revision_archives_the_old_action_and_inherits_the_cadence(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        $oldAction = Action::factory()->for($current->intention)->create([
            'strategy_id' => $current->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => now()->addDay(),
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        Strategist::fake([$this->revision(Strategy::POINT_CUE, 'Lay shoes out the night before.')]);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure($current, 'Too tired after work');

        $oldAction->refresh();
        $this->assertSame(Action::STATUS_ARCHIVED, $oldAction->status);

        $newAction = $current->intention->actions()
            ->where('status', Action::STATUS_PENDING)->first();
        $this->assertNotNull($newAction);
        $this->assertSame($next->id, $newAction->strategy_id);
        $this->assertSame('daily', $newAction->recurrence); // inherited
        $this->assertStringContainsString('Lay shoes', $newAction->title); // from the new approach

        // One active Action per active Strategy.
        $this->assertSame(1, $current->intention->actions()
            ->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])->count());
    }

    public function test_revision_uses_a_reproposed_schedule_when_given(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        Action::factory()->for($current->intention)->create([
            'strategy_id' => $current->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
        ]);

        Strategist::fake([[
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Walk in the morning instead.',
            'rationale' => 'Mornings have more energy.',
            'action' => [
                'title' => 'Morning walk',
                'schedule' => ['kind' => 'clock', 'time' => '06:30', 'recurrence' => 'weekdays'],
            ],
        ]]);

        app(ReviseStrategy::class)->restrategizeOnFailure($current, 'No energy in the evening');

        $newAction = $current->intention->actions()
            ->where('status', Action::STATUS_PENDING)->first();
        $this->assertSame('weekdays', $newAction->recurrence); // re-proposed, not inherited
        $this->assertSame('Morning walk', $newAction->title);
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test --compact --filter=ReviseStrategyTest`
Expected: FAIL — no Action is archived/created yet.

- [ ] **Step 3: Author the Action inside `supersedeAndCreate()`**

In `app/Actions/ReviseStrategy.php`:

a) Add imports:

```php
use App\Models\Action;
use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
```

b) In `revise()`, after building `$interventionPoint`/`$approach`, also parse the optional action and thread it through. Change the `revise()` return to include the authored action. The simplest approach: have `revise()` store the parsed action on a property the caller reads. Add a private nullable property and set it:

```php
    private ?AuthoredAction $revisedAction = null;
```

At the end of `revise()`, before `return new AuthoredStrategy(...)`, add:

```php
        $this->revisedAction = AuthoredAction::tryFromStructured(
            is_array($response->structured['action'] ?? null) ? $response->structured['action'] : null,
        );
```

c) In `supersedeAndCreate()`, after creating the new strategy and before returning it, archive open actions and author the new one. Replace the `return $current->intention->strategies()->create([...]);` with:

```php
        $newStrategy = $current->intention->strategies()->create([
            'version' => $nextVersion,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => $next->interventionPoint,
            'approach' => $next->approach,
            'rationale' => $next->rationale,
            'parent_strategy_id' => $current->id,
            'change_reason' => $changeReason,
            'metadata' => array_filter([
                'previous_point' => $current->intervention_point,
                'direction' => BehavioralChain::direction(
                    $current->intervention_point,
                    $next->interventionPoint,
                ),
                'prompt_version' => $next->promptVersion,
            ], static fn ($value): bool => $value !== null),
        ]);

        $this->authorActionFor($current->intention, $newStrategy, $next);

        return $newStrategy;
    }

    private function authorActionFor(\App\Models\Intention $intention, Strategy $strategy, AuthoredStrategy $next): void
    {
        $prior = $intention->activeAction;

        $intention->actions()
            ->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])
            ->update(['status' => Action::STATUS_ARCHIVED]);

        $action = $this->revisedAction;
        $timezone = $intention->user?->timezone ?? (string) config('app.timezone');

        if ($action !== null) {
            $recurrence = Recurrence::tryFromToken($action->recurrence);
            $scheduledFor = (new Schedule)->firstOccurrence(CarbonImmutable::now(), $action->time, $recurrence, $timezone);
            $title = $action->title;
            $metadata = array_filter(['schedule_kind' => $action->kind, 'anchor' => $action->anchor], static fn ($v): bool => $v !== null);
        } else {
            // Inherit the prior cadence; retitle from the new tactic.
            $scheduledFor = $prior?->scheduled_for;
            $recurrence = Recurrence::tryFromToken($prior?->recurrence);
            $title = Str::limit($next->approach, 250, '');
            $metadata = array_filter([
                'schedule_kind' => $prior?->metadata['schedule_kind'] ?? null,
                'anchor' => $prior?->metadata['anchor'] ?? null,
                'inherited_from_action_id' => $prior?->id,
            ], static fn ($v): bool => $v !== null);
        }

        $strategy->actions()->create([
            'intention_id' => $intention->id,
            'title' => $title,
            'description' => $next->rationale,
            'scheduled_for' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => $metadata,
        ]);
    }
```

(Note: the `$this->revise()` call must run before `supersedeAndCreate()` so `$revisedAction` is set. It already does in both `stackOnSuccess`/`restrategizeOnFailure`, because `$next ??= $this->revise(...)` runs before the `DB::transaction(...)`. When a pre-authored `$next` is passed, `revise()` is skipped and `$revisedAction` stays null → inherit path. That is the intended behaviour.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ReviseStrategyTest`
Expected: PASS (all methods, old and new).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/ReviseStrategy.php tests/Feature/ReviseStrategyTest.php
git commit -m "feat(coach): ReviseStrategy archives old action and authors a new one"
```

---

## Task 10: Reschedule endpoint (policy, request, action, controllers, routes)

**Files:**
- Modify: `app/Policies/ActionPolicy.php`
- Create: `app/Http/Requests/RescheduleActionRequest.php`
- Create: `app/Actions/RescheduleAction.php`
- Create: `app/Http/Controllers/ActionController.php`
- Create: `app/Http/Controllers/Api/ActionController.php`
- Modify: `routes/web.php`, `routes/api.php`
- Test: `tests/Feature/Actions/RescheduleActionWebTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescheduleActionWebTest extends TestCase
{
    use RefreshDatabase;

    private function actionFor(User $user): Action
    {
        $intention = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        return Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
        ]);
    }

    public function test_owner_can_reschedule_to_a_clock_recurrence(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'time' => '06:30',
                'recurrence' => 'weekdays',
            ])
            ->assertRedirect();

        $action->refresh();
        $this->assertSame('weekdays', $action->recurrence);
        $this->assertNotNull($action->scheduled_for);
        $this->assertSame('06:30', $action->scheduled_for->utc()->format('H:i'));
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_owner_can_set_an_anchored_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'anchored',
                'anchor' => 'after lunch',
            ])
            ->assertRedirect();

        $action->refresh();
        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->recurrence);
        $this->assertSame('after lunch', $action->metadata['anchor']);
    }

    public function test_a_stranger_cannot_reschedule(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $action = $this->actionFor($owner);

        $this->actingAs($stranger)
            ->patch("/actions/{$action->id}", ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'])
            ->assertForbidden();
    }

    public function test_clock_requires_a_valid_time(): void
    {
        $user = User::factory()->create();
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", ['kind' => 'clock', 'time' => '7am', 'recurrence' => 'daily'])
            ->assertSessionHasErrors('time');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RescheduleActionWebTest`
Expected: FAIL — route `/actions/{id}` (PATCH) does not exist (404/MethodNotAllowed).

- [ ] **Step 3: Add the `update` policy ability**

In `app/Policies/ActionPolicy.php`, add:

```php
    public function update(User $user, Action $action): bool
    {
        return $action->intention->user_id === $user->id;
    }
```

- [ ] **Step 4: Create the Form Request**

`app/Http/Requests/RescheduleActionRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the ActionPolicy
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Create the `RescheduleAction`**

`app/Actions/RescheduleAction.php`:

```php
<?php

namespace App\Actions;

use App\Models\Action;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;

/**
 * Recomputes and persists an Action's schedule from a user edit. Clock edits
 * derive a fresh UTC scheduled_for in the user's timezone; anchored edits clear
 * the schedule and record the anchor phrase. The only place a reschedule writes.
 */
final readonly class RescheduleAction
{
    public function handle(Action $action, string $kind, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
    {
        $rule = $kind === 'clock' ? Recurrence::tryFromToken($recurrence) : null;

        $scheduledFor = $kind === 'clock'
            ? (new Schedule)->firstOccurrence(CarbonImmutable::now(), $time, $rule, $timezone)
            : null;

        $metadata = array_merge($action->metadata ?? [], [
            'schedule_kind' => $kind,
            'anchor' => $kind === 'anchored' ? $anchor : null,
        ]);

        $action->update([
            'scheduled_for' => $scheduledFor,
            'recurrence' => $rule?->value,
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
        ]);

        return $action->refresh();
    }
}
```

- [ ] **Step 6: Create the web controller**

`app/Http/Controllers/ActionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\RescheduleAction;
use App\Http\Requests\RescheduleActionRequest;
use App\Models\Action;
use Illuminate\Http\RedirectResponse;

class ActionController extends Controller
{
    public function update(RescheduleActionRequest $request, Action $action, RescheduleAction $reschedule): RedirectResponse
    {
        $this->authorize('update', $action);

        $reschedule->handle(
            $action,
            $request->validated('kind'),
            $request->validated('time'),
            $request->validated('recurrence'),
            $request->validated('anchor'),
            $request->user()->timezone ?? (string) config('app.timezone'),
        );

        return back();
    }
}
```

- [ ] **Step 7: Create the API controller**

`app/Http/Controllers/Api/ActionController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Actions\RescheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescheduleActionRequest;
use App\Models\Action;
use Illuminate\Http\JsonResponse;

class ActionController extends Controller
{
    public function update(RescheduleActionRequest $request, Action $action, RescheduleAction $reschedule): JsonResponse
    {
        $this->authorize('update', $action);

        $action = $reschedule->handle(
            $action,
            $request->validated('kind'),
            $request->validated('time'),
            $request->validated('recurrence'),
            $request->validated('anchor'),
            $request->user()->timezone ?? (string) config('app.timezone'),
        );

        return response()->json([
            'id' => $action->id,
            'scheduled_for' => $action->scheduled_for,
            'recurrence' => $action->recurrence,
            'status' => $action->status,
        ]);
    }
}
```

- [ ] **Step 8: Register the routes**

In `routes/web.php`, add inside the `['auth', 'verified']` group (after the logs route) and add `use App\Http\Controllers\ActionController;` at the top:

```php
    // Edit an action's schedule (time + recurrence, or an anchored cue).
    Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');
```

In `routes/api.php`, add inside the `auth:sanctum` group and add `use App\Http\Controllers\Api\ActionController;` at the top:

```php
        Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=RescheduleActionWebTest`
Expected: PASS (4 tests).

- [ ] **Step 10: Run Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/ActionPolicy.php app/Http/Requests/RescheduleActionRequest.php app/Actions/RescheduleAction.php app/Http/Controllers/ActionController.php app/Http/Controllers/Api/ActionController.php routes/web.php routes/api.php tests/Feature/Actions/RescheduleActionWebTest.php
git commit -m "feat(actions): reschedule endpoint for editing an action's schedule"
```

---

## Task 11: One-time timezone capture

**Files:**
- Create: `app/Http/Controllers/Settings/TimezoneController.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/TimezoneCaptureTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_a_valid_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->patch('/settings/timezone', ['timezone' => 'America/New_York'])
            ->assertRedirect();

        $this->assertSame('America/New_York', $user->fresh()->timezone);
    }

    public function test_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->patch('/settings/timezone', ['timezone' => 'Mars/Phobos'])
            ->assertSessionHasErrors('timezone');

        $this->assertNull($user->fresh()->timezone);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=TimezoneCaptureTest`
Expected: FAIL — route `/settings/timezone` does not exist.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/Settings/TimezoneController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Captures the browser-reported IANA timezone once, so action schedules localise
 * correctly. The frontend PATCHes this on first authenticated load when the
 * user's timezone is still null.
 */
class TimezoneController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'timezone'],
        ]);

        $request->user()->update(['timezone' => $validated['timezone']]);

        return back();
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/settings.php`, add inside the first `['auth']` group and add `use App\Http\Controllers\Settings\TimezoneController;` at the top:

```php
    Route::patch('settings/timezone', [TimezoneController::class, 'update'])->name('timezone.update');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TimezoneCaptureTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Pass the user's timezone to the coach page + capture it client-side**

In `app/Http/Controllers/ChatController.php`, in `home()`, add `'userTimezone' => $user->timezone,` to the `Inertia::render('coach', [...])` props array.

In `resources/js/pages/coach.tsx`, add a one-time capture effect. Near the top of the page component (which already receives Inertia props), add:

```tsx
import { useEffect } from 'react';
import { router } from '@inertiajs/react';

// ...inside the component, with `userTimezone` read from props:
useEffect(() => {
    if (userTimezone) {
        return;
    }
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz) {
        router.patch('/settings/timezone', { timezone: tz }, { preserveScroll: true, preserveState: true });
    }
}, [userTimezone]);
```

Add `userTimezone?: string | null` to the page's props type and destructure it.

- [ ] **Step 7: Type-check the frontend**

Run: `npm run types:check`
Expected: no errors.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Settings/TimezoneController.php routes/settings.php app/Http/Controllers/ChatController.php resources/js/pages/coach.tsx tests/Feature/Settings/TimezoneCaptureTest.php
git commit -m "feat(settings): capture the browser timezone for scheduling"
```

---

## Task 12: Serialize the schedule on the loop resource

**Files:**
- Modify: `app/Http/Resources/IntentionResource.php`
- Modify: `app/Http/Controllers/Api/IntentionController.php` (eager-load `activeAction` on show)
- Modify: `resources/js/patyourself/types.ts`
- Modify: `database/factories/ActionFactory.php`
- Test: new `tests/Feature/Actions/ActiveActionResourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveActionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_show_embeds_the_action_schedule(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => now()->addDay(),
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->actingAs($user)
            ->getJson("/api/intentions/{$intention->id}")
            ->assertOk()
            ->assertJsonPath('data.active_action.recurrence', 'daily')
            ->assertJsonPath('data.active_action.schedule_kind', 'clock');
    }
}
```

(Confirmed shape: `routes/api.php` is `/api`-prefixed and `IntentionController::show()` returns `new IntentionResource(...)`, which `JsonResource` wraps under `data`. So `/api/intentions/{id}` and the `data.active_action.*` paths are correct.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ActiveActionResourceTest`
Expected: FAIL — `active_action.recurrence` path is missing.

- [ ] **Step 3: Extend the resource's `active_action` block**

In `app/Http/Resources/IntentionResource.php`, replace the `active_action` closure body with:

```php
            'active_action' => $this->whenLoaded('activeAction', fn () => $this->activeAction === null ? null : [
                'id' => $this->activeAction->id,
                'title' => $this->activeAction->title,
                'description' => $this->activeAction->description,
                'status' => $this->activeAction->status,
                'scheduled_for' => $this->activeAction->scheduled_for,
                'recurrence' => $this->activeAction->recurrence,
                'schedule_kind' => $this->activeAction->metadata['schedule_kind'] ?? null,
                'anchor' => $this->activeAction->metadata['anchor'] ?? null,
            ]),
```

Then make the API `show()` eager-load the action. In `app/Http/Controllers/Api/IntentionController.php`, change line 53 from `return new IntentionResource($intention->load('activeStrategy'));` to:

```php
        return new IntentionResource($intention->load(['activeStrategy', 'activeAction']));
```

- [ ] **Step 4: Update the TypeScript shape**

In `resources/js/patyourself/types.ts`, replace `ActiveActionData` with:

```ts
/** The loggable action embedded in an IntentionResource (the card's quick-log target). */
export interface ActiveActionData {
    id: number;
    title: string;
    description: string | null;
    status: string;
    scheduled_for: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
}
```

- [ ] **Step 5: Align the factory's metadata**

In `database/factories/ActionFactory.php`, change the `metadata` default so seeds carry a schedule kind:

```php
            'metadata' => ['schedule_kind' => 'clock', 'card' => ['style' => 'default']],
```

- [ ] **Step 6: Run the test + type-check**

Run: `php artisan test --compact --filter=ActiveActionResourceTest && npm run types:check`
Expected: PASS + no type errors.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Resources/IntentionResource.php app/Http/Controllers/Api/IntentionController.php resources/js/patyourself/types.ts database/factories/ActionFactory.php tests/Feature/Actions/ActiveActionResourceTest.php
git commit -m "feat(api): embed the action schedule in the loop resource"
```

---

## Task 13: Schedule chip on the action card

**Files:**
- Modify: `resources/js/patyourself/chat/action-card.tsx`
- Test: `resources/js/patyourself/chat/action-card.test.tsx` (extend)

- [ ] **Step 1: Write the failing test**

Add to `resources/js/patyourself/chat/action-card.test.tsx` (follow the file's existing render/import style; it already builds an `IntentionData`). Add a helper for `active_action` and two cases:

```tsx
import { render, screen } from '@testing-library/react';
import { ActionCard } from './action-card';
import type { IntentionData } from '@/patyourself/types';

function intentionWith(active_action: IntentionData['active_action']): IntentionData {
    return {
        id: 1, title: 'Morning walk', description: null, type: 'build', status: 'active',
        cue: 'Coffee finishes', craving: 'Energy', response: 'Walk', reward: 'Momentum',
        metadata: null, created_at: null, updated_at: null, strategy: null, active_action,
    };
}

test('renders a recurring clock schedule chip', () => {
    render(<ActionCard intention={intentionWith({
        id: 5, title: 'Walk', description: null, status: 'pending',
        scheduled_for: '2026-06-15T11:00:00.000000Z', recurrence: 'daily',
        schedule_kind: 'clock', anchor: null,
    })} />);

    expect(screen.getByText(/Daily/)).toBeInTheDocument();
});

test('renders an anchored schedule chip', () => {
    render(<ActionCard intention={intentionWith({
        id: 6, title: 'Push-ups', description: null, status: 'pending',
        scheduled_for: null, recurrence: null, schedule_kind: 'anchored', anchor: 'after coffee',
    })} />);

    expect(screen.getByText(/after coffee/)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test -- action-card`
Expected: FAIL — no "Daily" / "after coffee" text rendered.

- [ ] **Step 3: Render the chip**

In `resources/js/patyourself/chat/action-card.tsx`, add a `ScheduleChip` and render it under the header (after the `</header>` close, before the `<dl>`):

```tsx
{intention.active_action && <ScheduleChip action={intention.active_action} />}
```

Add the component + a formatter at the bottom of the file:

```tsx
const RECURRENCE_LABEL: Record<string, string> = {
    daily: 'Daily',
    weekdays: 'Weekdays',
    weekly: 'Weekly',
};

function formatSchedule(action: NonNullable<IntentionData['active_action']>): string | null {
    if (action.schedule_kind === 'anchored' || (!action.scheduled_for && action.anchor)) {
        return action.anchor ?? null;
    }

    if (!action.scheduled_for) {
        return null;
    }

    const when = new Date(action.scheduled_for);
    const time = when.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    const cadence = action.recurrence
        ? RECURRENCE_LABEL[action.recurrence] ?? action.recurrence
        : when.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

    return `${cadence} · ${time}`;
}

function ScheduleChip({ action }: { action: NonNullable<IntentionData['active_action']> }) {
    const label = formatSchedule(action);
    if (!label) {
        return null;
    }
    return (
        <p className="mt-2 inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
            {label}
        </p>
    );
}
```

Ensure `IntentionData` is imported (it already is via the `type` import at the top).

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test -- action-card`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/patyourself/chat/action-card.tsx resources/js/patyourself/chat/action-card.test.tsx
git commit -m "feat(ui): show the action's schedule on the card"
```

---

## Task 14: Inline schedule editor + client wiring

**Files:**
- Modify: `resources/js/patyourself/chat/coach-client.ts`
- Modify: `resources/js/patyourself/chat/chat-home.tsx`
- Modify: `resources/js/patyourself/chat/action-card.tsx`
- Test: `resources/js/patyourself/chat/action-card.test.tsx` (extend)

- [ ] **Step 1: Add `rescheduleAction` to the client**

In `resources/js/patyourself/chat/coach-client.ts`:

a) Add a `patch` helper next to `post`:

```ts
async function patch(url: string, body: Record<string, unknown>): Promise<Response> {
    const response = await fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Request to ${url} failed: ${response.status}`);
    }

    return response;
}
```

b) Define the payload type and extend the interface:

```ts
export interface ReschedulePayload {
    kind: 'clock' | 'anchored';
    time?: string | null;
    recurrence?: string | null;
    anchor?: string | null;
}
```

Add to `CoachClient`:

```ts
    rescheduleAction(actionId: number, schedule: ReschedulePayload): Promise<void>;
```

c) Implement it in `httpCoachClient`:

```ts
    async rescheduleAction(actionId, schedule) {
        await patch(`/actions/${actionId}`, {
            kind: schedule.kind,
            time: schedule.time ?? null,
            recurrence: schedule.recurrence ?? null,
            anchor: schedule.anchor ?? null,
        });
    },
```

- [ ] **Step 2: Add a `reschedule` callback to `useChatThread`**

In `resources/js/patyourself/chat/chat-home.tsx`, import `ReschedulePayload` and add a `reschedule` callback alongside `log`, then return it:

```tsx
import type { CoachClient, ReschedulePayload } from './coach-client';

// inside useChatThread, after `log`:
    const reschedule = useCallback(
        async (intention: IntentionData, schedule: ReschedulePayload): Promise<void> => {
            const action = intention.active_action;
            if (!action) {
                return;
            }
            await client.rescheduleAction(action.id, schedule);
        },
        [client],
    );

    return { messages, send, log, reschedule };
```

(Update the `ChatThread` usage so the card receives `onReschedule`. In `ChatThread`'s props add `onReschedule?: (intention: IntentionData, schedule: ReschedulePayload) => void;` and pass it to `<ActionCard onReschedule={...} />` in the `card` branch, mirroring how `onLog` is passed. The `coach.tsx` page that consumes `useChatThread` should thread `reschedule` into `<ChatThread onReschedule={reschedule} />`.)

- [ ] **Step 3: Write the failing editor test**

Add to `action-card.test.tsx`:

```tsx
import userEvent from '@testing-library/user-event';

test('submitting the editor calls onReschedule', async () => {
    const onReschedule = vi.fn();
    const intention = intentionWith({
        id: 7, title: 'Walk', description: null, status: 'pending',
        scheduled_for: '2026-06-15T11:00:00.000000Z', recurrence: 'daily',
        schedule_kind: 'clock', anchor: null,
    });

    render(<ActionCard intention={intention} onReschedule={onReschedule} />);

    await userEvent.click(screen.getByRole('button', { name: /edit time/i }));
    await userEvent.click(screen.getByRole('button', { name: /save time/i }));

    expect(onReschedule).toHaveBeenCalledWith(intention, expect.objectContaining({ kind: 'clock' }));
});
```

(Use the test file's existing imports for `vi`/`userEvent`; add them if absent. `@testing-library/user-event` is already a dev dependency in Inertia React starters — if `npm run test` reports it missing, add the editor test using `fireEvent` from `@testing-library/react` instead.)

- [ ] **Step 4: Run it to verify it fails**

Run: `npm run test -- action-card`
Expected: FAIL — no "Edit time" button.

- [ ] **Step 5: Add the editor to `ActionCard`**

In `resources/js/patyourself/chat/action-card.tsx`:

a) Add `onReschedule` to the props and `useState` import:

```tsx
import { useState } from 'react';
import type { IntentionData, LogOutcome } from '@/patyourself/types';
import type { ReschedulePayload } from './coach-client';

export function ActionCard({
    intention,
    onLog,
    onReschedule,
}: {
    intention: IntentionData;
    onLog?: (outcome: LogOutcome) => void;
    onReschedule?: (intention: IntentionData, schedule: ReschedulePayload) => void;
}) {
```

b) Render an "Edit time" toggle next to the chip when `onReschedule` is set, and a small form. Add inside the card body (after the `ScheduleChip` line):

```tsx
{onReschedule && intention.active_action && (
    <ScheduleEditor
        action={intention.active_action}
        onSave={(schedule) => onReschedule(intention, schedule)}
    />
)}
```

c) Implement `ScheduleEditor` at the bottom of the file:

```tsx
function ScheduleEditor({
    action,
    onSave,
}: {
    action: NonNullable<IntentionData['active_action']>;
    onSave: (schedule: ReschedulePayload) => void;
}) {
    const [open, setOpen] = useState(false);
    const initialTime = action.scheduled_for
        ? new Date(action.scheduled_for).toLocaleTimeString(undefined, { hour12: false, hour: '2-digit', minute: '2-digit' })
        : '07:00';
    const [time, setTime] = useState(initialTime);
    const [recurrence, setRecurrence] = useState(action.recurrence ?? 'daily');

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-2 text-xs font-medium text-muted-foreground hover:text-foreground"
            >
                Edit time
            </button>
        );
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-xl border border-border p-2">
            <input
                type="time"
                aria-label="Action time"
                value={time}
                onChange={(event) => setTime(event.target.value)}
                className="h-8 rounded-lg border border-border bg-background px-2 text-sm"
            />
            <select
                aria-label="Recurrence"
                value={recurrence}
                onChange={(event) => setRecurrence(event.target.value)}
                className="h-8 rounded-lg border border-border bg-background px-2 text-sm"
            >
                <option value="once">Once</option>
                <option value="daily">Daily</option>
                <option value="weekdays">Weekdays</option>
                <option value="weekly">Weekly</option>
            </select>
            <button
                type="button"
                onClick={() => {
                    onSave({ kind: 'clock', time, recurrence });
                    setOpen(false);
                }}
                className="h-8 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground"
            >
                Save time
            </button>
        </div>
    );
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `npm run test -- action-card`
Expected: PASS.

- [ ] **Step 7: Type-check + commit**

```bash
npm run types:check
git add resources/js/patyourself/chat/coach-client.ts resources/js/patyourself/chat/chat-home.tsx resources/js/patyourself/chat/action-card.tsx resources/js/patyourself/chat/action-card.test.tsx
git commit -m "feat(ui): inline schedule editor wired to the reschedule endpoint"
```

---

## Task 15: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHP suite**

Run: `php artisan test --compact`
Expected: all green. If any pre-existing test broke because of the new Action authoring (e.g. a chat/ChatEndpoint test that asserts on cards), fix the assertion to account for the now-present `active_action` schedule.

- [ ] **Step 2: Run the full frontend suite**

Run: `npm run test`
Expected: all green.

- [ ] **Step 3: Type-check, lint, format**

Run: `npm run types:check && npm run lint:check && npm run format:check`
Expected: no errors. Run `npm run lint` / `npm run format` to auto-fix if needed.

- [ ] **Step 4: Pint the whole change set**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 5: Build the frontend**

Run: `npm run build`
Expected: builds without errors.

- [ ] **Step 6: Final commit (if Step 1/3 required fixes)**

```bash
git add -A
git commit -m "test(sp1): align suite with action authoring"
```

---

## Self-review checklist (completed by the plan author)

- **Spec coverage:** schedule model (Tasks 2–3), AI authoring extension (Tasks 5, 8), `AuthoredAction` (Task 4) + carried on intention (Task 6), persistence in AuthorIntention (Task 7) and ReviseStrategy (Task 9), one-active invariant (Task 9 assertion), editing route + policy (Task 10), timezone (Tasks 1, 11), card serialization + chip + editor (Tasks 12–14). Scope boundary (no firing) respected throughout.
- **Type consistency:** `firstOccurrence(now, localTime, recurrence, timezone)` and `advance(current, recurrence, timezone)` used identically in Tasks 3, 7, 9, 10. `AuthoredAction` props (`title, description, kind, time, recurrence, anchor`) consistent across Tasks 4, 6, 7, 9. `ReschedulePayload` (`kind, time, recurrence, anchor`) consistent across Tasks 10, 14. `active_action` fields consistent across Tasks 12–14.
- **No placeholders:** every code step contains complete code; commands have expected output.
```
