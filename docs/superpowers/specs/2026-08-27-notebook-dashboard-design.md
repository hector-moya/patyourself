# The notebook dashboard

Date: 2026-08-27
Status: designed

## Problem

`/dashboard` is the app's daily-driver screen — Fortify sends every login there —
and it renders the loops index. `routes/web.php` points `dashboard` and
`loops.index` at the same `IntentionController@index`.

So the first screen after login answers "what loops do I have", not "what am I
doing today".

The read model for the second question is already built:

| Service | What it answers | Called by |
| --- | --- | --- |
| `TodaysOccasions::for($user)` | the occasions in the user's local day | `TodayActionsTool` — **Claude only** |

Same shape as the lab-record work: Claude can see what is due today; the owner
opens the app and sees a list of loops. `TodaysOccasion` already carries
`action`, `occurrence`, `scheduledFor` and `due` as
`due_now | upcoming | anchored`.

## Scope

The `/dashboard` notebook screen, with logging inline.

**`/log` is dropped.** The reframe spec listed it as a separate fast-capture
screen; with logging on the dashboard it would be a fourth surface — after the
dashboard, `/catch-up` and the MCP tools — where the verbatim-reason rule has to
be got right independently. Decided with the owner.

Out of scope: the `/progress` index (untouched), `/loops` (already redesigned),
and any change to `/catch-up`.

## Design

### The screen

```
Wednesday 27 August

DUE NOW
○ Lunch without bread                 12:30
○ Evening walk                     anchored

LATER TODAY
○ Reading                             21:00

READY FOR A VERDICT
Morning walk · v1 · day 15 of 14         →

Nothing older appears here.
```

Three sections, each omitted entirely when empty. No counts, no badges, no
progress bar toward a daily total — a day with two occasions and a day with six
are not scored against each other.

**Today means the user's local day and only that.** An occasion missed on an
earlier day never appears here. It stays loggable forever on `/catch-up`, and the
dashboard does not link to it with a number attached.

### Logging inline

Reuses the interaction `/catch-up` already has: outcome radios per row, and a
`reason` field revealed only for `failed`. The tool boundary already requires a
reason for a failed outcome; the UI must not disagree with it.

**Two endpoints, chosen per row.** `TodaysOccasion::$occurrence` is nullable:

- occurrence present → `POST occurrences/{occurrence}/logs`, which logs that
  exact occasion.
- occurrence absent (an anchored action with no materialised slot) →
  `POST actions/{action}/logs`, which logs the live slot.

Getting this wrong is silent: posting an anchored action to the occurrence route
is impossible (there is no id), and routing everything to the action route would
log the live slot rather than the occasion on screen. The row picks its endpoint
from whether it has an `occurrence_id`.

Both endpoints already return `back()`, so Inertia re-renders the dashboard. No
new endpoint is added.

`TodaysOccasions::for()` returns **unlogged** occasions only, so a logged row
does not become a tick — it leaves the screen. That is the right behaviour here
and it is why there is no "done" state to design: the dashboard shows what is
still open today, and what has been dealt with stops asking. The loop's own
record keeps the outcome.

### Ready for a verdict

Active loops whose active strategy `isUnderReview()` — running, has a
`review_at`, and it has passed. Each links to its lab record, where
`conclude-experiment`'s evidence now lives.

This section is **not** a nag. It states that a decision is available, in the
same weight as the rest of the screen: no red, no count, no "overdue". A version
past its review date is not late; nothing in this app is.

### Empty states

- No occasions today, nothing to review → "Nothing due today." Stated as a fact.
  A day with nothing scheduled is a normal day, not an empty one.
- No loops at all → the existing loops-index empty state's wording, pointing at
  the connector.

Neither empty state suggests the user has fallen behind, and neither offers to
start an experiment.

### Routing

`dashboard` is repointed from `IntentionController@index` to a new
`NotebookController@index`. **The route name must survive** —
`config/fortify.php` sets `'home' => '/dashboard'`, so renaming it breaks login.

`loops.index` keeps `IntentionController@index`. The two stop being aliases.

Any nav entry currently sending "Loops" to `/dashboard` is repointed at `/loops`,
so the two tabs mean two different things.

## Testing

PHPUnit:

- `/dashboard` renders `dashboard` with occasions grouped by `due`.
- An occasion from an earlier day is **absent** — the discriminating case, and
  the one that keeps the backlog off this screen.
- A loop whose active strategy is past `review_at` appears under review; one
  inside its window does not.
- A superseded strategy with a past `review_at` does **not** appear — `isUnderReview()`
  already excludes it, and the test pins that the controller does not re-derive it.
- Guests are redirected; another user's occasions never appear.

Vitest:

- Each `due` value renders in its own section, and an empty section is omitted
  rather than rendered with a zero.
- A row with an `occurrence_id` posts to the occurrence route; a row without one
  posts to the action route. **Asserted on the form action**, because the failure
  is silent.
- The reason field appears only for `failed`.
- The verdict section carries no count and no alarm styling.
- The empty state does not imply falling behind.

**Every load-bearing assertion is mutation-verified**, with particular attention
to the two that would pass against a broken implementation: the earlier-day
exclusion (seed an occasion from yesterday and confirm its absence is what fails)
and the endpoint selection.

## Constraints carried through

- The notebook never nags: no overdue counts, no red states, no backlog number.
- A failed outcome carries the user's reason, verbatim.
- `skipped` means the occasion never happened.
- No gamification: no daily completion score, no streak on this screen, no
  celebratory state on clearing the day.
- No quantities.
