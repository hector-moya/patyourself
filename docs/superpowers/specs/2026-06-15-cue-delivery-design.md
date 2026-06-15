# SP3 — Cue Delivery (in-app inbox) (design)

**Date:** 2026-06-15
**Status:** Approved, ready for implementation planning
**Program slice:** SP3 of the "close the habit loop" decomposition (see SP1 spec,
`docs/superpowers/specs/2026-06-13-action-authoring-design.md`, and SP2 spec,
`docs/superpowers/specs/2026-06-14-trigger-engine-design.md`)

---

## App intent (one paragraph)

PatYourSelf is a conversational habit-change coach. It models every habit as an
_Atomic Habits_ loop — cue → craving → response → reward. SP1 authored a concrete,
scheduled `Action` per Strategy. SP2 built the engine that **fires** a due action
(`pending → active`, stamping `metadata.fired_at`) and rolls recurring actions
forward — but a fired action only changes state in the database and shows a "Due
now" badge if the user happens to be looking at its card. The whole point of a
habit app is to **deliver the cue at the right moment**. SP3 closes the **third
missing link**: it turns a fire into a delivered, persistent cue the user can find
and act on.

## The problem this slice solves

SP2 leaves a fired action as `active` with `metadata.fired_at` set, surfaced only
as a passive badge on whatever card is on screen. There is no record the user was
prompted, no list of outstanding cues, nothing that pulls attention to a habit when
its moment arrives. If the user is not on the action's card, the cue is invisible.

SP3 fixes this with an **in-app inbox**: when an action fires, the user receives a
persisted notification they can view in a dedicated inbox, with an unread badge in
the bottom nav drawing them in. Logging the action answers the cue and clears it.
SP3 is **in-app delivery only** — email and web push are deferred (they layer onto
the same notification as extra channels); auto-revision / summary on failure is SP4.

---

## Decisions (locked in brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Channel scope | **In-app inbox only.** No email, no web push this slice — they bolt onto the same `ActionDueNotification` later as extra channels. |
| 2 | Storage | **Laravel `database` notifications** — built-in `Notifiable` + `notifications` table + one `ActionDueNotification`. Idiomatic; future channels = `via()` additions, not new plumbing. |
| 3 | Fire → notify wiring | **`ActionFired` domain event + `SendDueNotification` listener.** TriggerEngine raises the event only on the owning guarded transition (exactly-once). Engine stays free of notification concerns; SP4 can add listeners. |
| 4 | Lifecycle | **Auto-mark read on log + manual.** Logging an action (any outcome) marks its unread notification read — the cue is answered. UI also offers tap-to-read and mark-all-read. |
| 5 | UI placement | **Third bottom-nav tab** (`Inbox`, bell icon, unread badge) → full `/inbox` page listing notifications. |

### Why Laravel database notifications over a custom inbox model

The custom model gives a typed `action_id` FK and full domain control, but it
reinvents what `Notifiable` already provides (`$user->notifications`,
`unreadNotifications`, `markAsRead()`, the built-in migration) and — critically —
makes the deferred email / web-push channels bespoke senders instead of one extra
string in `via()`. Decision 1 (inbox now, email/push later on the same
notification) only stays cheap if SP3 uses the channel system. The single cost — the
`action_id` lives inside the `data` JSON rather than a real FK — is fine for an
inbox list and is queryable via `where('data->action_id', …)`.

---

## Design

### 1. Components

| Unit | Type | Responsibility | Depends on |
|---|---|---|---|
| `App\Events\ActionFired` | event (new) | Immutable carrier of the just-fired `Action`. | `Action` |
| `App\Services\Scheduling\TriggerEngine::fire()` | service (modify) | After the guarded flip affects exactly 1 row, `event(new ActionFired($action))`. No other change. | `ActionFired` |
| `App\Listeners\SendDueNotification` | listener (new) | On `ActionFired`, notify the action's owner: `$action->intention->user->notify(new ActionDueNotification($action))`. Auto-discovered (Laravel event discovery). Synchronous. | `ActionDueNotification` |
| `App\Notifications\ActionDueNotification` | notification (new) | `via() = ['database']`; `toArray()` payload for the inbox row. The single place email/push channels attach later. | `Action` |
| `App\Actions\LogAction` | action (modify) | After logging (any outcome), mark the owner's unread notification(s) for this `action_id` read. | `DatabaseNotification` |
| `App\Http\Controllers\InboxController` | controller (new) | `index` (render inbox), `markRead` (one), `markAllRead`. All scoped to the authenticated user. | `Notifiable`, Inertia |
| `routes/web.php` | wiring | `/inbox`, `/inbox/{notification}/read`, `/inbox/read-all` under `auth`. | — |
| `App\Http\Middleware\HandleInertiaRequests` | middleware (modify) | Share `unread_notifications_count` for the bottom-nav badge on every page. | `Notifiable` |
| `notifications` table | migration (new) | Built-in `php artisan notifications:table`. | — |
| `resources/js/pages/inbox.tsx` | page (new) | List notifications: unread (dot/bold) vs read, relative fire time, link to `/intentions/{id}`; "mark all read". | shared props |
| `resources/js/patyourself/bottom-nav.tsx` | UI (modify) | Add `Inbox` tab (bell icon) + unread badge from shared prop. | shared props |
| Wayfinder generated helpers | regenerate | Typed route helpers for the new inbox routes. | — |

Each unit is testable in isolation: the event/listener via `Event::fake` /
`Notification::fake`; the notification's `toArray` shape directly; `LogAction`'s
mark-read through the existing log flow; `InboxController` via HTTP feature tests.

### 2. Fire → notification (the delivery path)

SP2's `TriggerEngine::fire()` already returns `true` only for the run whose guarded
`UPDATE … WHERE id=? AND status='pending'` affects exactly 1 row. SP3 adds one line
inside that owning branch:

```php
// inside fire(), only when $affected === 1
event(new ActionFired($action));
```

`SendDueNotification` handles it:

```php
public function handle(ActionFired $event): void
{
    $action = $event->action;
    $action->intention->user->notify(new ActionDueNotification($action));
}
```

`ActionDueNotification`:

```php
public function via(object $notifiable): array
{
    return ['database'];
}

/** @return array{action_id:int, intention_id:int, title:string, fired_at:?string} */
public function toArray(object $notifiable): array
{
    return [
        'action_id' => $this->action->id,
        'intention_id' => $this->action->intention_id,
        'title' => $this->action->intention->title,
        'fired_at' => $this->action->metadata['fired_at'] ?? null,
    ];
}
```

Because the event is raised **only on the owning transition**, exactly one
notification is created per occurrence — no extra idempotency machinery is needed.
`withoutOverlapping()` and the guarded flip (both from SP2) already prevent
double-fire upstream.

**Listener is synchronous** (no `ShouldQueue`). Notifying is a single DB insert, and
the Herd dev environment runs no queue worker. When email / push land later, those
channels can move to a queued notification without touching this wiring.

### 3. Lifecycle — answering the cue (`LogAction`)

A notification is the "do this now" cue. When the user logs the action (completed,
skipped, **or** failed), the cue is answered, so its unread notification is marked
read. `LogAction`, after recording the `ActionLog` and the existing
close-or-re-arm transition (SP2), adds:

```php
$user->unreadNotifications()
    ->where('data->action_id', $action->id)
    ->get()
    ->each->markAsRead();
```

| Event | Inbox effect |
|---|---|
| Action fires (SP2 owning transition) | New unread notification created. |
| User logs the action (any outcome) | That action's unread notification(s) marked read. |
| Recurring action re-arms, fires again next occurrence | A **new** notification is created for the new occurrence. |
| User taps a row / "mark all read" in the inbox | Marked read via `InboxController`. |

At log time the only unread notification for that `action_id` is the current
occurrence's (the next one fires in the future), so marking by `action_id` is exact.

### 4. Inbox endpoints (`InboxController`, Inertia)

| Route | Method | Action | Behavior |
|---|---|---|---|
| `/inbox` | GET | `index` | `Inertia::render('inbox', ['notifications' => …])` — the auth user's notifications, newest first (recent N), each mapped to `{ id, action_id, intention_id, title, fired_at, read_at }`. |
| `/inbox/{notification}/read` | PATCH | `markRead` | Mark one notification read. 404 if it does not belong to the auth user. |
| `/inbox/read-all` | PATCH | `markAllRead` | Mark all the auth user's unread notifications read. |

All routes sit behind `auth`. `markRead` resolves the notification from the user's
own relation (`$user->notifications()->findOrFail($id)`) so cross-user access
returns 404. Mutations redirect back (Inertia), refreshing the shared unread count.

### 5. Unread badge (shared prop)

`HandleInertiaRequests::share` adds, for an authenticated user:

```php
'unread_notifications_count' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
```

This makes the count available to the bottom nav on every page. It refreshes on each
Inertia navigation / reload — **not real-time** (no broadcasting this slice).

### 6. Frontend

- **`resources/js/pages/inbox.tsx`** — a list under `CoachLayout`. Each item: the
  action title ("Meditate — due now"), the fire time as relative ("2m", "1h"), and a
  read/unread treatment (unread = leading dot + medium weight; read = muted). Tapping
  a row marks it read (PATCH) and links to `/intentions/{intention_id}`. A "mark all
  read" control hits `/inbox/read-all`. Empty state when there are no notifications.
- **`resources/js/patyourself/bottom-nav.tsx`** — add a third tab `{ label: 'Inbox',
  icon: 'bell', href: '/inbox', match: ['/inbox'] }`, with a small count badge driven
  by the `unread_notifications_count` shared prop (hidden when 0).
- Route calls use Wayfinder-generated helpers (regenerated for the new routes).

---

## Testing

**Feature — fire raises the event (`tests/Feature/Scheduling/TriggerEngineTest.php`, extend):**
- Firing a due pending action dispatches `ActionFired` exactly once (`Event::fake`).
- No fire (future / anchored / paused intention / already active) → event **not** dispatched.
- Running the engine twice (idempotent) dispatches the event once.

**Feature — listener delivers (`tests/Feature/Notifications/SendDueNotificationTest.php`, new):**
- `ActionFired` → the action's owner receives an `ActionDueNotification` on the
  `database` channel (`Notification::fake`, `assertSentTo`).
- Notification is sent to the **owner**, not other users.

**Unit/Feature — notification payload (`tests/Feature/Notifications/ActionDueNotificationTest.php`, new):**
- `via()` returns `['database']`.
- `toArray()` shape: `action_id`, `intention_id`, `title`, `fired_at` (from metadata).

**Feature — log answers the cue (`tests/Feature/Actions/LogActionTest.php`, extend):**
- Logging (completed / skipped / failed) marks that action's unread notification read.
- It leaves unread notifications for **other** actions, and for **other users**, untouched.
- No notification present → logging still succeeds (no error).

**Feature — inbox endpoints (`tests/Feature/Inbox/InboxControllerTest.php`, new):**
- `index` renders the inbox with only the auth user's notifications, newest first.
- `markRead` marks one read; another user's notification → 404, stays unread.
- `markAllRead` marks all the user's unread read.
- Guests are unauthorized on all three.

**Feature — shared unread count (`tests/Feature/Inbox/UnreadCountSharedPropTest.php`, new):**
- An authenticated page response shares the correct `unread_notifications_count`;
  drops after the notification is read; `0` (or absent) for a guest.

**Frontend (vitest):**
- `inbox.tsx`: renders unread (dot/bold) vs read (muted) items; empty state; "mark all read" present.
- `bottom-nav.tsx`: Inbox tab renders; badge shows the count and is hidden when 0.

---

## Files touched (anticipated)

**New**
- `app/Events/ActionFired.php`
- `app/Listeners/SendDueNotification.php`
- `app/Notifications/ActionDueNotification.php`
- `app/Http/Controllers/InboxController.php`
- `database/migrations/*_create_notifications_table.php` (built-in generator)
- `resources/js/pages/inbox.tsx`
- `tests/Feature/Notifications/SendDueNotificationTest.php`
- `tests/Feature/Notifications/ActionDueNotificationTest.php`
- `tests/Feature/Inbox/InboxControllerTest.php`
- `tests/Feature/Inbox/UnreadCountSharedPropTest.php`

**Modified**
- `app/Services/Scheduling/TriggerEngine.php` — dispatch `ActionFired` on the owning fire.
- `app/Actions/LogAction.php` — mark the action's unread notification read after logging.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `unread_notifications_count`.
- `routes/web.php` — inbox routes.
- `resources/js/patyourself/bottom-nav.tsx` — Inbox tab + badge.
- `tests/Feature/Scheduling/TriggerEngineTest.php` — `ActionFired` dispatch tests.
- `tests/Feature/Actions/LogActionTest.php` — mark-read-on-log tests.

---

## Success criteria

1. When an action fires (SP2), its owner gets exactly one persisted database
   notification — visible in `/inbox` even when they are not on the action's card.
2. The bottom nav shows an unread badge reflecting outstanding cues; it clears as
   notifications are read.
3. Logging an action (any outcome) marks its unread notification read; the inbox also
   supports tap-to-read and mark-all-read.
4. A recurring action that re-arms produces a fresh notification on its next fire.
5. Notifications are strictly per-user: no user can view or mark another's.
6. Exactly-once delivery rides SP2's guarded fire — re-running the engine never
   duplicates a notification.
7. All new and affected tests pass (PHPUnit + vitest); `vendor/bin/pint` clean;
   types/lint clean.

## Scope boundary — explicitly NOT in SP3

- **No email and no web push** — deferred; they attach to `ActionDueNotification`
  later as additional `via()` channels (no service worker, VAPID, mailer, or
  `push_subscriptions` table this slice).
- No auto-revision or rolling-summary on failure (SP4).
- No notification preferences / mute / per-channel settings.
- No retention / cleanup / archival policy for old notifications.
- No real-time badge — no broadcasting / websockets; the count refreshes on Inertia
  navigation.
- No changes to SP2's firing, re-arm, or DST logic beyond raising the event.
