# Email Reminders — Design

**Date:** 2026-08-24
**Status:** Approved
**Goal:** Deliver habit cues by email, so a reminder reaches the user without them opening the
app — the gap that makes the current in-app-only notification useless as a nudge.

## Decisions made during brainstorming

- **Email only. No SMS, no WhatsApp.** Both were investigated and neither is free, which was the
  user's stated condition. WhatsApp utility templates (what a scheduled reminder is) bill from the
  first message with no free tier; they are free only inside a 24-hour service window the *user*
  opens by messaging first, which a scheduled reminder cannot rely on. Every SMS gateway bills per
  message, and the one historically free path — carrier email-to-SMS gateways — is gone: T-Mobile
  shut `tmomail.net` (Dec 2024), AT&T shut `txt.att.net` (Jun 2025), Verizon is phasing out by
  Mar 2027. Building on it would mean building on something already broken for most recipients.
- **Both cadences, user-selectable:** off / daily digest / every cue. Not a single fixed cadence.
- **Default is `digest` at 07:00.** New and existing users are enrolled in the low-volume option
  rather than opted out, because reminders are the product's purpose. Revisit if the app opens to
  a wider audience.
- **Emails link into the app; they do not log outcomes.** One-click Done/Skipped/Failed was
  considered and rejected for now: it needs a public state-changing endpoint with signed,
  expiring, single-use URLs, and a `failed` outcome requires the user's reason anyway, so it would
  land on a form regardless. Deferred, not designed out — the mail can gain buttons later without
  reworking anything here.
- **Notifications become queued.** SMTP inside the scheduler's minute would block `actions:fire`.

## Architecture

### Preferences

Migration adds three columns to `users` (which already carries `timezone`):

| Column | Type | Default | Notes |
|---|---|---|---|
| `email_reminders` | string | `digest` | one of `off`, `digest`, `every_cue` |
| `digest_time` | string | `07:00` | `HH:MM` in the user's own timezone |
| `digest_last_sent_on` | date, nullable | `null` | the user's local date; the double-send guard |

The three values are `User::EMAIL_REMINDERS_*` constants with a `User::EMAIL_REMINDER_MODES` list,
mirroring how `Intention::STATUSES` is declared, so validation and tests share one source.

### Shared "what is due today" query

`TodayActionsTool` currently builds its due-today query inline. The digest needs exactly the same
set, and two copies would silently diverge — the digest would tell the user one thing and Claude
another.

Extract that query into `App\Services\Scheduling\TodaysActions`, with a single method taking the
user and returning the ordered `Action` collection. `TodayActionsTool` becomes a caller and keeps
its existing JSON shape; the digest becomes the second caller. No behaviour change to the tool —
its existing tests must pass untouched, which is the proof the extraction was faithful.

### Per-cue email

`ActionDueNotification`:

- implements `ShouldQueue`
- `via()` returns `['database']`, plus `'mail'` when
  `$notifiable->email_reminders === User::EMAIL_REMINDERS_EVERY_CUE`
- gains `toMail()`: the action title, its loop, the cue text, a button to the loop, and a footer
  link to notification settings

The `database` channel stays unconditional. The in-app inbox is unaffected by the email
preference — turning email off must never cost the user their in-app cue.

`SendDueNotification` (the listener) is unchanged: it still calls `notify()`. Queuing is the
notification's concern, not the listener's.

### Daily digest

- `App\Notifications\DailyDigestNotification` — mail channel only, `ShouldQueue`. Lists today's
  actions grouped by loop, with times in the user's timezone.
- `App\Services\Reminders\DigestDispatcher` — holds the logic, so it is feature-testable without
  the console.
- `App\Console\Commands\SendReminderDigests` (`reminders:digest`) — a thin wrapper over the
  service, mirroring `FireDueActions`. Scheduled every minute in `routes/console.php` with
  `withoutOverlapping()`.

Each run selects users where all of:

- `email_reminders = 'digest'`
- the user's current local `H:i` is **at or past** `digest_time`
- `digest_last_sent_on` is not the user's local today

At-or-past rather than exactly-equals is deliberate. An exact match makes the whole feature depend
on one specific minute's run succeeding: a queue hiccup, a slow run, or a minute of scheduler
downtime would silently cost the user that day's digest with no error anywhere. At-or-past is
self-healing — the next successful minute catches up — and the not-sent-today guard still caps it
at one email per day. The cost is that a digest delayed by an outage arrives late rather than not
at all, which for a daily summary is the better failure.

then loads their due-today actions via `TodaysActions`, skips the user if the set is empty (never
send an empty digest), sends, and stamps `digest_last_sent_on` to the user's local date.

Running every minute rather than on a fixed hour is what lets each user pick their own time in
their own timezone; it is the same pattern `actions:fire` already uses.

### Settings

- `GET settings/notifications` -> `Settings\NotificationsController@edit`
- `PATCH settings/notifications` -> `@update`, via a `NotificationsUpdateRequest`

Both inside the existing `['auth']` settings group in `routes/settings.php`, following
`ProfileController`'s shape including the `Inertia::flash('toast', ...)` confirmation.

React page `resources/js/pages/settings/notifications.tsx` alongside profile/security/appearance:
three radios, with the time input rendered only when `digest` is selected.

Validation: `email_reminders` must be in `User::EMAIL_REMINDER_MODES`; `digest_time` is
`required_if:email_reminders,digest` and `date_format:H:i`.

## Error handling

- A user with `timezone = null` falls back to `config('app.timezone')`, as everywhere else.
- A mail send that throws is a queued-job failure and retries on the queue; it must not abort the
  digest run for other users, so each user is dispatched independently.
- `digest_last_sent_on` is stamped only after a successful dispatch, so a failed run retries on the
  next minute rather than silently skipping the day.

## Testing (PHPUnit + vitest)

- `via()` includes `mail` only for `every_cue`; never for `off` or `digest`.
- `database` is present for all three modes.
- The digest sends at the user's local `digest_time`, not UTC — asserted with two users in
  different timezones and the same wall-clock preference.
- The digest does not send before `digest_time`, and still sends when the run is late (local time
  already past `digest_time` and nothing sent today).
- The digest does not send twice in one local day, and does send again the next day.
- The digest skips users with nothing due, and skips `off` / `every_cue` users.
- `digest_last_sent_on` is not stamped when nothing was sent.
- `TodaysActions` returns the same set the tool returned before extraction — the existing
  `TodayActionsToolTest` must pass unmodified.
- Both mails render, including the settings link.
- Settings: each mode persists; `digest_time` is required for `digest` and rejected when malformed.
- vitest: the time input appears only for the digest mode, and the form submits the chosen mode.

## Out of scope (YAGNI)

- SMS and WhatsApp (see Decisions).
- One-click outcome logging from the email.
- Per-loop notification preferences — the setting is per user.
- Quiet hours, snooze, or per-cue email throttling.
- A weekly summary email.
- Unsubscribe tokens; the footer links to settings, which requires a login.
