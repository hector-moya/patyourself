# Cue Delivery (SP3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When SP2 fires an action, deliver it as a persistent in-app cue — a Laravel database notification the user finds in a dedicated `/inbox` (with an unread badge in the bottom nav) and that clears when they log the action. No email, no web push (later channels on the same notification); no SP4 auto-revision.

**Architecture:** `TriggerEngine::fire()` raises an `ActionFired` event on its owning guarded transition (exactly-once). An auto-discovered `SendDueNotification` listener calls `$user->notify(new ActionDueNotification($action))`, persisted via the built-in `database` channel into the standard `notifications` table. `LogAction` marks that action's unread notification read on any outcome. `HandleInertiaRequests` shares an `unread_notifications_count`; the bottom nav renders it as a badge on a new Inbox tab; `/inbox` (an `InboxController` + Inertia page) lists notifications with tap-to-read and mark-all-read.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit 12, Pint, Inertia v3 + React 19 + TypeScript, Tailwind v4, Vitest. App served by Herd (never `php artisan serve`); frontend tooling needs Node 22.

---

## Architecture notes (read once before starting)

- **Storage = Laravel database notifications.** One `ActionDueNotification` with `via()=['database']`. Email/push later are extra strings in `via()`, not new plumbing. `User` already uses the `Notifiable` trait, so `$user->notifications`, `$user->unreadNotifications`, and `markAsRead()` are available. `DatabaseNotification` casts its `data` column to an array by default.
- **Exactly-once delivery rides SP2.** `TriggerEngine::fire()` already returns `true` only for the run whose `UPDATE … WHERE id=? AND status='pending'` affects 1 row. The event is dispatched **only** in that branch, so each occurrence produces exactly one notification. No extra dedupe.
- **Event payload must be fresh.** `fire()` updates the row via the query builder, leaving the in-memory `$action` stale. `refresh()` the model before dispatching so the notification reads the new `status` and `metadata.fired_at`.
- **Lifecycle.** Logging an action (completed / skipped / **failed** — any outcome answers the cue) marks that action's unread notification(s) read. A recurring action that re-arms produces a fresh notification on its next fire.
- **Listener is synchronous** (no `ShouldQueue`) — a single DB insert; the Herd dev env runs no queue worker.
- **Badge is not real-time** — the shared count refreshes on Inertia navigation, not via websockets.
- **Scope:** in-app inbox only. No email/push, no broadcasting, no preferences, no retention policy, no SP4.

## File structure

**Create**
- `database/migrations/2026_06_15_000001_create_notifications_table.php` — the standard Laravel notifications table.
- `app/Notifications/ActionDueNotification.php` — `database` channel notification; `toArray()` payload.
- `app/Events/ActionFired.php` — immutable event carrying the fired `Action`.
- `app/Listeners/SendDueNotification.php` — notifies the action's owner on `ActionFired`.
- `app/Http/Controllers/InboxController.php` — `index`, `markRead`, `markAllRead`.
- `resources/js/pages/inbox.tsx` — the inbox list page.
- `resources/js/pages/inbox.test.tsx` — inbox page tests.
- `resources/js/patyourself/bottom-nav.test.tsx` — bottom-nav tab/badge tests.
- `tests/Feature/Notifications/ActionDueNotificationTest.php`
- `tests/Feature/Notifications/SendDueNotificationTest.php`
- `tests/Feature/Inbox/InboxControllerTest.php`
- `tests/Feature/Inbox/UnreadCountSharedPropTest.php`

**Modify**
- `app/Services/Scheduling/TriggerEngine.php` — eager-load `intention.user`; on the owning fire, `refresh()` + dispatch `ActionFired`.
- `app/Actions/LogAction.php` — mark the action's unread notification read after logging.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `unread_notifications_count`.
- `routes/web.php` — inbox routes.
- `resources/js/patyourself/primitives.tsx` — add the `bell` icon.
- `resources/js/patyourself/bottom-nav.tsx` — Inbox tab + unread badge.
- `resources/js/types/global.d.ts` — add `unread_notifications_count` to shared page props.
- `resources/js/patyourself/types.ts` — add `NotificationData`.
- `tests/Feature/Scheduling/TriggerEngineTest.php` — `ActionFired` dispatch tests.
- `tests/Feature/Actions/LogActionTest.php` — mark-read-on-log tests.

**Commands reference**
- PHP tests: `php artisan test --compact --filter=<name>`
- Pint (after any PHP edit): `vendor/bin/pint --dirty --format agent`
- Frontend (needs Node 22). Prefix every npm/npx command with the Node 22 path:
  `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test`
  Type-check: `… npm run types:check` · Lint: `… npm run lint:check`
  Format **touched files only** (never the whole tree): `… npx prettier --write <file> [<file> …]`

> **Worktree setup (do once before Task 1):** this is a fresh git worktree with gitignored deps absent. Make the toolchain work by providing real `vendor/` and the generated frontend assets from the main checkout (`../../..` is the main repo root): copy `.env`, copy `vendor/`, symlink `node_modules`, symlink `public/build`, and copy `resources/js/{actions,routes,wayfinder}`. Verify with `php artisan test --compact tests/Feature/Scheduling/TriggerEngineTest.php` (should pass on the SP2 baseline) before starting.

---

## Task 1: Notifications table migration

**Files:**
- Create: `database/migrations/2026_06_15_000001_create_notifications_table.php`

- [ ] **Step 1: Write the migration**

There is no `notifications:table` generator in this app, so write the standard schema by hand. Create `database/migrations/2026_06_15_000001_create_notifications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `notifications` table created (no errors).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_15_000001_create_notifications_table.php
git commit -m "$(cat <<'EOF'
feat(notifications): add the notifications table

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `ActionDueNotification`

**Files:**
- Create: `app/Notifications/ActionDueNotification.php`
- Test: `tests/Feature/Notifications/ActionDueNotificationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Notifications/ActionDueNotificationTest.php`:

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

    private function firedAction(): Action
    {
        $intention = Intention::factory()
            ->for(User::factory())
            ->create(['title' => 'Meditate daily', 'status' => Intention::STATUS_ACTIVE]);

        return Action::factory()->for($intention)->create([
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['fired_at' => '2026-06-15T07:00:00+00:00'],
        ]);
    }

    public function test_it_uses_only_the_database_channel(): void
    {
        $notification = new ActionDueNotification($this->firedAction());

        $this->assertSame(['database'], $notification->via(new User));
    }

    public function test_to_array_carries_the_inbox_payload(): void
    {
        $action = $this->firedAction();

        $payload = (new ActionDueNotification($action))->toArray(new User);

        $this->assertSame([
            'action_id' => $action->id,
            'intention_id' => $action->intention_id,
            'title' => 'Meditate daily',
            'fired_at' => '2026-06-15T07:00:00+00:00',
        ], $payload);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=ActionDueNotificationTest`
Expected: FAIL — class `App\Notifications\ActionDueNotification` not found.

- [ ] **Step 3: Write the notification**

Create `app/Notifications/ActionDueNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Action;
use Illuminate\Notifications\Notification;

/**
 * The cue: an action's scheduled moment has arrived (SP2 fired it). Delivered
 * in-app via the database channel and surfaced in the inbox. Email and web push
 * are future channels that attach here by extending via() — no other plumbing.
 */
class ActionDueNotification extends Notification
{
    public function __construct(private readonly Action $action) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{action_id: int, intention_id: int, title: string, fired_at: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'action_id' => $this->action->id,
            'intention_id' => $this->action->intention_id,
            'title' => $this->action->intention->title,
            'fired_at' => $this->action->metadata['fired_at'] ?? null,
        ];
    }
}
```

- [ ] **Step 4: Run it to confirm it passes**

Run: `php artisan test --compact --filter=ActionDueNotificationTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/ActionDueNotification.php tests/Feature/Notifications/ActionDueNotificationTest.php
git commit -m "$(cat <<'EOF'
feat(notifications): ActionDueNotification (database channel)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `ActionFired` event, listener, and the fire → notify wiring

**Files:**
- Create: `app/Events/ActionFired.php`
- Create: `app/Listeners/SendDueNotification.php`
- Modify: `app/Services/Scheduling/TriggerEngine.php`
- Test: `tests/Feature/Notifications/SendDueNotificationTest.php`
- Test: `tests/Feature/Scheduling/TriggerEngineTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Notifications/SendDueNotificationTest.php`:

```php
<?php

namespace Tests\Feature\Notifications;

use App\Events\ActionFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The fire -> cue delivery path: an ActionFired event notifies the action's
 * owner, and the engine end-to-end persists exactly one database notification.
 */
class SendDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function dueAction(User $user): Action
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        return Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => null,
        ]);
    }

    public function test_action_fired_notifies_the_owner(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $action = $this->dueAction($user);

        event(new ActionFired($action));

        Notification::assertSentTo($user, ActionDueNotification::class);
    }

    public function test_action_fired_does_not_notify_other_users(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $action = $this->dueAction($owner);

        event(new ActionFired($action));

        Notification::assertNotSentTo($other, ActionDueNotification::class);
    }

    public function test_firing_the_engine_persists_one_notification_for_the_owner(): void
    {
        $user = User::factory()->create();
        $action = $this->dueAction($user);

        app(TriggerEngine::class)->fireDueActions();

        $user->refresh();
        $this->assertCount(1, $user->notifications);
        $this->assertSame($action->id, $user->notifications->first()->data['action_id']);
    }
}
```

Append these two methods to the existing `TriggerEngineTest` class in `tests/Feature/Scheduling/TriggerEngineTest.php` (before the closing `}`). They rely on the file's existing `dueAction()` helper:

```php
    public function test_firing_dispatches_action_fired_once(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ActionFired::class]);
        $action = $this->dueAction();

        app(\App\Services\Scheduling\TriggerEngine::class)->fireDueActions();

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\ActionFired::class, 1);
        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\ActionFired::class,
            fn (\App\Events\ActionFired $event): bool => $event->action->is($action),
        );
    }

    public function test_no_fire_dispatches_no_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ActionFired::class]);
        // A future pending action is not due, so nothing fires.
        $this->dueAction(['scheduled_for' => now()->addHour()]);

        app(\App\Services\Scheduling\TriggerEngine::class)->fireDueActions();

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\ActionFired::class);
    }
```

> Note: confirm the existing `dueAction()` helper signature accepts an overrides array (it does in the SP2 suite). If the existing helper takes no args, add an `array $overrides = []` parameter merged into its `create([...])` and keep existing callers working.

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter=SendDueNotificationTest`
Expected: FAIL — class `App\Events\ActionFired` not found.

- [ ] **Step 3: Write the event**

Create `app/Events/ActionFired.php`:

```php
<?php

namespace App\Events;

use App\Models\Action;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised by the trigger engine when (and only when) it owns the transition that
 * fires an action pending -> active. Carries the freshly-refreshed Action so
 * listeners see the new status and metadata.fired_at. SP3 delivers the cue from
 * it; SP4 can add its own listeners.
 */
class ActionFired
{
    use Dispatchable;

    public function __construct(public readonly Action $action) {}
}
```

- [ ] **Step 4: Write the listener**

Create `app/Listeners/SendDueNotification.php`. It is auto-discovered by Laravel's event discovery (it lives in `app/Listeners` and type-hints the event), so no manual registration is needed.

```php
<?php

namespace App\Listeners;

use App\Events\ActionFired;
use App\Notifications\ActionDueNotification;

/**
 * Delivers the in-app cue when an action fires: notifies the action's owner via
 * the database channel. Synchronous — a single insert; no queue worker needed.
 */
class SendDueNotification
{
    public function handle(ActionFired $event): void
    {
        $action = $event->action;

        $action->intention->user->notify(new ActionDueNotification($action));
    }
}
```

- [ ] **Step 5: Wire the engine to dispatch**

In `app/Services/Scheduling/TriggerEngine.php`, eager-load the owner (avoids an N+1 in the listener) and dispatch the event on the owning fire.

Add the import near the top:

```php
use App\Events\ActionFired;
```

In `fireDueActions()`, add `->with('intention.user')` to the query:

```php
        $due = Action::query()
            ->with('intention.user')
            ->where('status', Action::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->whereHas('intention', function (Builder $query): void {
                $query->where('status', Intention::STATUS_ACTIVE);
            })
            ->get();
```

Replace the body of `fire()` so the owning run refreshes the model and dispatches the event:

```php
    private function fire(Action $action): bool
    {
        $metadata = array_merge($action->metadata ?? [], [
            'fired_at' => now()->toIso8601String(),
        ]);

        $affected = Action::query()
            ->whereKey($action->getKey())
            ->where('status', Action::STATUS_PENDING)
            ->update([
                'status' => Action::STATUS_ACTIVE,
                'metadata' => json_encode($metadata),
            ]);

        if ($affected === 1) {
            $action->refresh();
            ActionFired::dispatch($action);

            return true;
        }

        return false;
    }
```

Also update the class docblock's final line — change "rich notification delivery is SP3" to note SP3 now rides the `ActionFired` event:

```php
 * SP2 does nothing beyond this in-app state transition. Recurrence roll-forward
 * happens when an occurrence is resolved (see App\Actions\LogAction). Firing
 * raises App\Events\ActionFired, on which SP3 delivers the in-app cue.
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter=SendDueNotificationTest`
Run: `php artisan test --compact tests/Feature/Scheduling/TriggerEngineTest.php`
Expected: PASS (SendDueNotification: 3; TriggerEngine: existing SP2 tests + 2 new, all green).

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Events/ActionFired.php app/Listeners/SendDueNotification.php app/Services/Scheduling/TriggerEngine.php tests/Feature/Notifications/SendDueNotificationTest.php tests/Feature/Scheduling/TriggerEngineTest.php
git commit -m "$(cat <<'EOF'
feat(notifications): deliver the in-app cue when an action fires

TriggerEngine raises ActionFired on the owning transition; SendDueNotification
notifies the owner via the database channel (exactly-once per occurrence).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `LogAction` marks the cue read on log

**Files:**
- Modify: `app/Actions/LogAction.php`
- Test: `tests/Feature/Actions/LogActionTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

Append these methods to the existing `LogActionTest` class in `tests/Feature/Actions/LogActionTest.php` (before the closing `}`). They use the file's existing helpers (`recurringAction()`, `action()`) and resolve `LogAction` from the container. Add `use App\Notifications\ActionDueNotification;` to the file's imports if absent.

```php
    public function test_logging_marks_the_actions_unread_notification_read(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);   // one-off; mark-read applies to any shape
        $user->notify(new ActionDueNotification($action));
        $this->assertCount(1, $user->unreadNotifications);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_logging_a_failure_also_marks_the_cue_read(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        $user->notify(new ActionDueNotification($action));

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Ran out of time',
        ]);

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_logging_leaves_other_actions_notifications_unread(): void
    {
        $user = User::factory()->create();
        $logged = $this->action($user);
        $other = $this->action($user);
        $user->notify(new ActionDueNotification($logged));
        $user->notify(new ActionDueNotification($other));

        app(LogAction::class)->handle($user, $logged, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(1, $user->fresh()->unreadNotifications);
    }

    public function test_logging_does_not_touch_another_users_notifications(): void
    {
        $owner = User::factory()->create();
        $action = $this->action($owner);
        $other = User::factory()->create();
        $other->notify(new ActionDueNotification($action));

        app(LogAction::class)->handle($owner, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(1, $other->fresh()->unreadNotifications);
    }

    public function test_logging_without_a_notification_still_succeeds(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $log = app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $log->outcome);
    }
```

> Note: the existing `action(User $user)` helper (pinned to a one-off in the SP2 suite) and `recurringAction(User $user)` helper both create an Action whose Intention belongs to `$user`. `ActionDueNotification` reads `$action->intention->title`, so the Intention must exist — it does via those helpers.

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: FAIL — the new tests expect `unreadNotifications` to drop to 0, but `LogAction` does not yet mark anything read.

- [ ] **Step 3: Implement mark-read in `LogAction`**

In `app/Actions/LogAction.php`, add the import:

```php
use Illuminate\Notifications\DatabaseNotification;
```

In `handle()`, after the `closeOrRearm` block and before `return $log;`, mark the cue answered:

```php
            $status = $this->actionStatusFor($data['outcome']);

            if ($status !== null) {
                $this->closeOrRearm($user, $action, $status);
            }

            $this->markCueAnswered($user, $action);

            return $log;
```

Add the private method (filter in memory rather than a JSON `where`, so it is driver-agnostic):

```php
    /**
     * Logging any outcome answers the "do this now" cue, so mark this action's
     * unread notification(s) read. Filtered in memory (unread sets are tiny) to
     * stay portable across database drivers.
     */
    private function markCueAnswered(User $user, Action $action): void
    {
        $user->unreadNotifications
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['action_id'] ?? null) === $action->id)
            ->each->markAsRead();
    }
```

Update the class docblock to note the new behaviour (one line is enough):

```php
 * Logging an outcome also marks the action's in-app "due now" notification read
 * (the cue is answered). It remains free of LLM side-effects.
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter=LogActionTest`
Expected: PASS (existing SP2 re-arm tests + 5 new).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/LogAction.php tests/Feature/Actions/LogActionTest.php
git commit -m "$(cat <<'EOF'
feat(notifications): logging an action marks its due cue read

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Share the unread count

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/Inbox/UnreadCountSharedPropTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Inbox/UnreadCountSharedPropTest.php`:

```php
<?php

namespace Tests\Feature\Inbox;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnreadCountSharedPropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notifyUser(User $user): void
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($intention)->create(['status' => Action::STATUS_ACTIVE]);
        $user->notify(new ActionDueNotification($action));
    }

    public function test_it_shares_the_authenticated_users_unread_count(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 1));
    }

    public function test_reading_the_notification_drops_the_shared_count(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);
        $user->unreadNotifications->markAsRead();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 0));
    }

    public function test_a_guest_sees_a_zero_count(): void
    {
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('unread_notifications_count', 0));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --compact --filter=UnreadCountSharedPropTest`
Expected: FAIL — `unread_notifications_count` prop is absent.

- [ ] **Step 3: Share the prop**

In `app/Http/Middleware/HandleInertiaRequests.php`, add the shared prop inside `share()`:

```php
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'unread_notifications_count' => fn (): int => $request->user()?->unreadNotifications()->count() ?? 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php artisan test --compact --filter=UnreadCountSharedPropTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Inbox/UnreadCountSharedPropTest.php
git commit -m "$(cat <<'EOF'
feat(notifications): share unread_notifications_count with Inertia

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `InboxController` + routes

**Files:**
- Create: `app/Http/Controllers/InboxController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Inbox/InboxControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Inbox/InboxControllerTest.php`:

```php
<?php

namespace Tests\Feature\Inbox;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notify(User $user, string $title = 'Meditate'): Action
    {
        $intention = Intention::factory()->for($user)->create([
            'title' => $title,
            'status' => Intention::STATUS_ACTIVE,
        ]);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['fired_at' => '2026-06-15T07:00:00+00:00'],
        ]);
        $user->notify(new ActionDueNotification($action));

        return $action;
    }

    public function test_index_lists_only_the_users_own_notifications(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify(User::factory()->create()); // another user's

        $this->actingAs($user)
            ->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox')
                ->has('notifications', 1)
                ->where('notifications.0.title', 'Meditate')
            );
    }

    public function test_index_lists_newest_first(): void
    {
        $user = User::factory()->create();
        $this->travelTo(now()->subMinute());
        $this->notify($user, 'Older');
        $this->travelBack();
        $this->notify($user, 'Newer');

        $this->actingAs($user)
            ->get('/inbox')
            ->assertInertia(fn (Assert $page) => $page->where('notifications.0.title', 'Newer'));
    }

    public function test_mark_read_marks_a_single_notification_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $id = $user->notifications()->first()->id;

        $this->actingAs($user)->patch("/inbox/{$id}/read")->assertRedirect();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_mark_read_404s_for_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $this->notify($owner);
        $id = $owner->notifications()->first()->id;

        $this->actingAs(User::factory()->create())
            ->patch("/inbox/{$id}/read")
            ->assertNotFound();

        $this->assertCount(1, $owner->fresh()->unreadNotifications);
    }

    public function test_mark_all_read_marks_every_notification_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);

        $this->actingAs($user)->patch('/inbox/read-all')->assertRedirect();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_guests_cannot_reach_the_inbox(): void
    {
        $this->get('/inbox')->assertRedirect();
        $this->patch('/inbox/read-all')->assertRedirect();
    }
}
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --compact --filter=InboxControllerTest`
Expected: FAIL — no `/inbox` route / `InboxController` not found.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/InboxController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app inbox: the user's delivered cues (database notifications raised when
 * their actions fired). Read endpoints are scoped to the authenticated user's own
 * notifications, so one user can never view or mutate another's.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'action_id' => $notification->data['action_id'] ?? null,
                'intention_id' => $notification->data['intention_id'] ?? null,
                'title' => $notification->data['title'] ?? null,
                'fired_at' => $notification->data['fired_at'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('inbox', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import at the top:

```php
use App\Http\Controllers\InboxController;
```

Inside the `Route::middleware(['auth', 'verified'])->group(...)` body, add (place `read-all` before the parameterised route):

```php
    // The in-app inbox: delivered cues + read state.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::patch('inbox/read-all', [InboxController::class, 'markAllRead'])->name('inbox.read-all');
    Route::patch('inbox/{notification}/read', [InboxController::class, 'markRead'])->name('inbox.read');
```

> The `guests_cannot_reach_the_inbox` test asserts a redirect; `auth` (with `verified`) redirects unauthenticated requests to login, which satisfies `assertRedirect()`.

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test --compact --filter=InboxControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/InboxController.php routes/web.php tests/Feature/Inbox/InboxControllerTest.php
git commit -m "$(cat <<'EOF'
feat(inbox): InboxController index + mark read/all-read routes

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Bottom-nav Inbox tab + unread badge

**Files:**
- Modify: `resources/js/patyourself/primitives.tsx` (add `bell` icon)
- Modify: `resources/js/types/global.d.ts` (shared prop type)
- Modify: `resources/js/patyourself/bottom-nav.tsx`
- Test: `resources/js/patyourself/bottom-nav.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `resources/js/patyourself/bottom-nav.test.tsx`:

```tsx
import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// usePage needs Inertia's context, which isn't mounted in a bare render. Stub it
// with a mutable page object (keeping the real Link so hrefs stay meaningful).
const page = { url: '/dashboard', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, usePage: () => page };
});

import { BottomNav } from './bottom-nav';

describe('BottomNav', () => {
    it('renders the Inbox tab', () => {
        page.props.unread_notifications_count = 0;
        render(<BottomNav />);

        expect(screen.getByText('Inbox')).toBeInTheDocument();
    });

    it('shows the unread badge when there are unread cues', () => {
        page.props.unread_notifications_count = 3;
        render(<BottomNav />);

        expect(screen.getByTestId('inbox-badge')).toHaveTextContent('3');
    });

    it('hides the badge when there are no unread cues', () => {
        page.props.unread_notifications_count = 0;
        render(<BottomNav />);

        expect(screen.queryByTestId('inbox-badge')).not.toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- bottom-nav`
Expected: FAIL — no "Inbox" tab, no `inbox-badge` testid.

- [ ] **Step 3: Add the `bell` icon**

In `resources/js/patyourself/primitives.tsx`, add `Bell` to the lucide import list and to the `ICONS` map:

```tsx
import {
    ArrowUp,
    Bell,
    Check,
    Footprints,
    GitBranch,
    MessageCircle,
    Minus,
    Moon,
    ShieldCheck,
    Sun,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
```

```tsx
const ICONS: Record<string, LucideIcon> = {
    'arrow-up': ArrowUp,
    bell: Bell,
    check: Check,
    footprints: Footprints,
    'git-branch': GitBranch,
    'message-circle': MessageCircle,
    minus: Minus,
    moon: Moon,
    'shield-check': ShieldCheck,
    sun: Sun,
    'trending-down': TrendingDown,
    'trending-up': TrendingUp,
};
```

- [ ] **Step 4: Type the shared prop**

In `resources/js/types/global.d.ts`, add `unread_notifications_count` to the shared page props so `usePage().props` is typed:

```ts
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            unread_notifications_count: number;
            [key: string]: unknown;
        };
    }
}
```

- [ ] **Step 5: Add the Inbox tab + badge**

Replace `resources/js/patyourself/bottom-nav.tsx` with:

```tsx
/**
 * PatYourSelf — the app's primary navigation. Renders inside CoachLayout's
 * reserved bottom-nav slot and links the app's screens: Coach (chat home),
 * Loops (the loops list), and Inbox (delivered cues, with an unread badge). The
 * loop-detail screen is reached from a loop, so it keeps the Loops tab active.
 */
import { Link, usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { Icon } from './primitives';

interface Tab {
    label: string;
    icon: string;
    href: string;
    /** A tab is active when the current path starts with one of these. */
    match: string[];
}

const TABS: Tab[] = [
    {
        label: 'Coach',
        icon: 'message-circle',
        href: '/dashboard',
        match: ['/dashboard'],
    },
    {
        label: 'Loops',
        icon: 'git-branch',
        href: '/intentions',
        match: ['/intentions'],
    },
    {
        label: 'Inbox',
        icon: 'bell',
        href: '/inbox',
        match: ['/inbox'],
    },
];

export function BottomNav() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const unread = props.unread_notifications_count ?? 0;

    return (
        <>
            {TABS.map((tab) => {
                const active = tab.match.some(
                    (m) => path === m || path.startsWith(`${m}/`),
                );
                const showBadge = tab.href === '/inbox' && unread > 0;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            'flex flex-1 flex-col items-center justify-center gap-0.5 text-xs font-medium transition-colors',
                            active
                                ? 'text-primary'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        aria-current={active ? 'page' : undefined}
                    >
                        <span className="relative">
                            <Icon name={tab.icon} size={20} />
                            {showBadge && (
                                <span
                                    data-testid="inbox-badge"
                                    className="absolute -top-1.5 -right-2.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                                >
                                    {unread > 9 ? '9+' : unread}
                                </span>
                            )}
                        </span>
                        <span>{tab.label}</span>
                    </Link>
                );
            })}
        </>
    );
}
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- bottom-nav`
Expected: PASS (3 tests).

- [ ] **Step 7: Types/lint + commit**

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run types:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run lint:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/patyourself/bottom-nav.tsx resources/js/patyourself/bottom-nav.test.tsx resources/js/patyourself/primitives.tsx resources/js/types/global.d.ts
git add resources/js/patyourself/bottom-nav.tsx resources/js/patyourself/bottom-nav.test.tsx resources/js/patyourself/primitives.tsx resources/js/types/global.d.ts
git commit -m "$(cat <<'EOF'
feat(inbox): bottom-nav Inbox tab with unread badge

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: The `/inbox` page

**Files:**
- Modify: `resources/js/patyourself/types.ts` (add `NotificationData`)
- Create: `resources/js/pages/inbox.tsx`
- Test: `resources/js/pages/inbox.test.tsx`

- [ ] **Step 1: Add the `NotificationData` type**

Append to `resources/js/patyourself/types.ts`:

```ts
/** One delivered cue in the inbox (mirrors InboxController's mapped payload). */
export interface NotificationData {
    id: string;
    action_id: number | null;
    intention_id: number | null;
    title: string | null;
    fired_at: string | null;
    read_at: string | null;
}
```

- [ ] **Step 2: Write the failing test**

Create `resources/js/pages/inbox.test.tsx`:

```tsx
import * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { NotificationData } from '@/patyourself/types';

// CoachLayout's <Head> and BottomNav's usePage need Inertia context; stub them.
const page = { url: '/inbox', props: { unread_notifications_count: 1 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Inbox from './inbox';

function notif(overrides: Partial<NotificationData> = {}): NotificationData {
    return {
        id: 'n1',
        action_id: 5,
        intention_id: 9,
        title: 'Meditate',
        fired_at: '2026-06-15T07:00:00Z',
        read_at: null,
        ...overrides,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Inbox', () => {
    it('renders an unread cue with its title and an unread marker', () => {
        render(<Inbox notifications={[notif()]} />);

        expect(screen.getByText(/Meditate/)).toBeInTheDocument();
        expect(screen.getByTestId('unread-dot')).toBeInTheDocument();
    });

    it('renders a read cue without an unread marker', () => {
        render(
            <Inbox
                notifications={[notif({ read_at: '2026-06-15T08:00:00Z' })]}
            />,
        );

        expect(screen.getByText(/Meditate/)).toBeInTheDocument();
        expect(screen.queryByTestId('unread-dot')).not.toBeInTheDocument();
    });

    it('shows an empty state when there are no cues', () => {
        render(<Inbox notifications={[]} />);

        expect(screen.getByText(/no cues yet/i)).toBeInTheDocument();
    });

    it('marks all read via the inbox endpoint', async () => {
        const patch = vi
            .spyOn(InertiaReact.router, 'patch')
            .mockImplementation(() => {});
        render(<Inbox notifications={[notif()]} />);

        await userEvent.click(screen.getByText(/mark all read/i));

        expect(patch.mock.calls[0][0]).toBe('/inbox/read-all');
    });
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- inbox`
Expected: FAIL — `./inbox` page does not exist.

- [ ] **Step 4: Write the page**

Create `resources/js/pages/inbox.tsx`:

```tsx
import { Link, router } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import type { NotificationData } from '@/patyourself/types';

interface InboxProps {
    notifications: NotificationData[];
}

/**
 * The inbox — delivered cues (notifications raised when the user's actions
 * fired). Unread cues lead with a dot; tapping one marks it read and opens its
 * loop. "Mark all read" clears the lot. Read state drives the bottom-nav badge.
 */
export default function Inbox({ notifications }: InboxProps) {
    const hasUnread = notifications.some(
        (notification) => !notification.read_at,
    );

    return (
        <CoachLayout
            title="Inbox"
            bottomNav={<BottomNav />}
            headerActions={
                hasUnread ? (
                    <button
                        type="button"
                        onClick={() =>
                            router.patch(
                                '/inbox/read-all',
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="text-xs font-medium text-primary"
                    >
                        Mark all read
                    </button>
                ) : undefined
            }
        >
            {notifications.length === 0 ? (
                <EmptyState />
            ) : (
                <ul className="flex flex-col gap-2">
                    {notifications.map((notification) => (
                        <li key={notification.id}>
                            <InboxItem notification={notification} />
                        </li>
                    ))}
                </ul>
            )}
        </CoachLayout>
    );
}

function InboxItem({ notification }: { notification: NotificationData }) {
    const unread = !notification.read_at;

    return (
        <Link
            href={`/intentions/${notification.intention_id}`}
            onClick={() => {
                if (unread) {
                    router.patch(
                        `/inbox/${notification.id}/read`,
                        {},
                        { preserveScroll: true, preserveState: true },
                    );
                }
            }}
            className={cn(
                'flex items-center gap-3 rounded-xl border border-border bg-card p-3 transition-colors hover:border-foreground/20 hover:bg-accent/40',
                !unread && 'opacity-70',
            )}
        >
            {unread && (
                <span
                    data-testid="unread-dot"
                    aria-label="Unread"
                    className="size-2 shrink-0 rounded-full bg-primary"
                />
            )}
            <span
                className={cn(
                    'flex-1 text-sm text-foreground',
                    unread && 'font-medium',
                )}
            >
                {notification.title ?? 'Action'} — due now
            </span>
            <span className="shrink-0 text-xs text-muted-foreground">
                {formatFiredAt(notification.fired_at)}
            </span>
        </Link>
    );
}

function formatFiredAt(firedAt: string | null): string {
    if (!firedAt) {
        return '';
    }

    return new Date(firedAt).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center">
            <p className="text-sm font-medium text-foreground">No cues yet</p>
            <p className="mt-1 text-xs text-muted-foreground">
                When an action’s time arrives, it’ll show up here.
            </p>
        </div>
    );
}
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test -- inbox`
Expected: PASS (4 tests).

- [ ] **Step 6: Full frontend checks + commit**

```bash
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run types:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run lint:check
PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npx prettier --write resources/js/pages/inbox.tsx resources/js/pages/inbox.test.tsx resources/js/patyourself/types.ts
git add resources/js/pages/inbox.tsx resources/js/pages/inbox.test.tsx resources/js/patyourself/types.ts
git commit -m "$(cat <<'EOF'
feat(inbox): the /inbox page listing delivered cues

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Final verification (after all tasks)

- [ ] **Full PHP suite:** `php artisan test --compact` — all green (SP1 + SP2 + SP3).
- [ ] **Full frontend suite:** `PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" npm run test` — all green.
- [ ] **Types + lint:** `… npm run types:check` and `… npm run lint:check` — clean.
- [ ] **Pint:** `vendor/bin/pint --dirty --format agent` — clean.
- [ ] **Manual smoke (optional, dev):** create a due pending action, run `php artisan actions:fire`, confirm a row appears in `notifications` for the owner and `/inbox` lists it with an unread badge; log the action and confirm the badge clears.

---

## Success criteria (from the spec)

1. A fired action produces exactly one persisted database notification for its owner, visible at `/inbox` regardless of which screen they're on.
2. The bottom nav shows an unread badge that clears as cues are read.
3. Logging an action (any outcome) marks its unread notification read; the inbox also supports tap-to-read and mark-all-read.
4. A recurring action that re-arms yields a fresh notification on its next fire.
5. Notifications are strictly per-user — no cross-user view or mutation.
6. Re-running the engine never duplicates a notification (rides SP2's guarded fire).
7. All new/affected tests pass (PHPUnit + vitest); Pint, types, lint clean.

## Scope boundary — explicitly NOT in SP3

- No email and no web push (later `via()` channels on `ActionDueNotification`; no service worker, VAPID, mailer, or `push_subscriptions` table this slice).
- No SP4 auto-revision / rolling-summary on failure.
- No notification preferences / mute / per-channel settings.
- No retention / cleanup of old notifications.
- No real-time badge (no broadcasting; the count refreshes on Inertia navigation).
- No change to SP2's firing, re-arm, or DST logic beyond raising the event and eager-loading the owner.
