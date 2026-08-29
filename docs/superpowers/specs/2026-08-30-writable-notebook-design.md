# The writable notebook — nine app-side features

Date: 2026-08-30
Status: approved (design)

## Context

The Lab Notebook pivot moved the coach out of the app and into an MCP server, and it worked: the
app is the record, Claude is the coach, and the boundary is guarded by a test. What the pivot did
not do is give the app a write surface of its own. The complete list of things a logged-in user can
currently change is: log an outcome, reschedule an action, edit or delete a loop, mark the inbox
read, and change their settings.

Everything else moved to MCP and stayed there. `StartExperiment` and `ConcludeExperiment` have **no
HTTP route at all** — web or API. `LogNote` and `CreateAction` and `ArchiveAction` are MCP-only.
So the central act of the product, running an experiment on a habit, is reachable from exactly one
interface.

That became visible on 2026-08-29, when the claude.ai connector turned out to be advertising a
stale six-tool list: `log-action-outcome` (a name retired weeks earlier), and none of the sixteen
tools that came after it. Ten deployed tools were unreachable, and with them the ability to start or
conclude an experiment at all. The app could not cover, because the app cannot do it either.

There is also a smaller, sharper version of the same problem already on screen. `NotebookController`
computes a "due for review" section for the dashboard — it asks `isUnderReview()` and tells the user
which experiment has reached its review date. Then it offers no way to act on it. The app raises the
question and has no answer.

This spec covers nine features. They are not one feature, and they do not share a mechanism; what
they share is that each one is a thing the app should be able to do without asking Claude first.

## The through-line

**The app should be usable on its own.** Not equal to the coach — the coach still does the thinking,
the reading-back, the noticing. But the record should be writable by the person whose record it is,
and the app should not depend on a third-party connector being correctly configured in order to
function at all.

That is the test for every decision below: if the connector broke tomorrow, would this still work?

## Phases

Nine features is more than one branch should carry. They split cleanly into three, ordered by value:

| Phase | Name | Items |
| --- | --- | --- |
| A | The notebook becomes writable | experiment controls, notes, action add/retire |
| B | Reach — the app in your pocket | timezone UI, one-click log from email, PWA |
| C | The record and the machinery | export, loops filter/search, failed-job alerts |

Each phase is independently shippable and independently useful. Phase A is the one that closes the
dead-end; if only one phase gets built, it should be that one.

---

# Phase A — The notebook becomes writable

## Decisions

### The app can start and conclude experiments

The house rule is "the coach authors, the app records", and loop *creation* stays with Claude — the
`/progress` empty state says so out loud, and it is right to: a cue → craving → response → reward
chain that the user did not talk through is a chain that describes nothing.

An experiment is different, in both directions:

- **Concluding** is a judgment on your own week. The `review-experiment` prompt already ends by
  leaving the next experiment to the owner, and the dashboard already tells you one is due. The app
  should let you answer the question it asked.
- **Starting** is authoring, and the coach is better at it. But "better at it" is not "the only one
  allowed to do it", and the alternative today is tinker. The app offers the form; nothing stops a
  user writing a bad hypothesis in it, exactly as nothing stops them writing a bad one in chat.

Rejected: conclude-only. It leaves the user able to close an experiment and unable to start the next
one, which is a worse state to be stranded in than the current one — a loop with a concluded version
and no successor has no active experiment at all.

### `$revisedAction` becomes a visible choice, not a default

`StartExperiment::handle()`'s `$revisedAction` parameter is documented in its own docblock as the
least guessable part of the API: pass it and the action's cadence is re-proposed; omit it and the
prior action's cadence is inherited, retitled from the new approach. Passing null is not "no action".

A form that hides this behind a default will get it wrong. The UI asks the question directly — *keep
the current cadence, or set a new one* — with the current cadence shown in the label so the choice
is legible. Choosing "keep" passes null; choosing "change" reveals the schedule fields and passes an
`AuthoredAction`.

### Validation lives at the boundary, and there are now two boundaries

`ReviseStrategy::revise()` used to be the only guard on `intervention_point` and non-empty
`approach`; when it was deleted, `StartExperimentTool` took that job. `AuthoredStrategy` still has no
guard of its own.

So the web form needs its own equivalent guard, and the two must not drift. The rules are pinned in
`StoreExperimentRequest` against the model constants — `Strategy::INTERVENTION_POINTS` and
`Strategy::CHANGE_REASONS` — rather than against a literal list, so adding a point or a reason moves
both surfaces together. A test asserts the web request and the MCP tool accept and reject the same
values.

### Retiring an action is `DELETE`, and `DELETE` archives

`ArchiveAction` is deliberately not a delete: occurrences hang off an action and outcomes hang off
occurrences, so a real delete would cascade away the evidence the app exists to keep.

The route is still `DELETE /actions/{action}`, because that is the verb for "retire this" in a REST
surface and inventing `POST /actions/{action}/archive` to avoid a misreading is worse. The
controller docblock states plainly that this archives; the confirmation copy in the UI says
"retire", never "delete", and says that the history is kept.

### Notes are written from the loop record, verbatim

Notes already render on the lab record; they just have no writer outside MCP. A single textarea on
the record, posting to `LogNote`, stored **verbatim** — never trimmed, squished or sentence-cased,
the same rule that governs failure reasons and reflections.

Deliberately not added: editing or deleting a note. The record is append-only, and a note you wish
you had not written is still what you thought at the time.

## Architecture

New routes, all under `auth` + `verified`, all authorized through the owning loop:

| Route | Name | Writer |
| --- | --- | --- |
| `POST /loops/{intention}/experiments` | `loops.experiments.store` | `StartExperiment` |
| `POST /strategies/{strategy}/verdict` | `strategies.verdict.store` | `ConcludeExperiment` |
| `POST /loops/{intention}/notes` | `loops.notes.store` | `LogNote` |
| `POST /loops/{intention}/actions` | `loops.actions.store` | `CreateAction` |
| `DELETE /actions/{action}` | `actions.destroy` | `ArchiveAction` |

**No new Actions.** Every writer already exists and is already tested — this phase is routes,
requests, policy and forms. That is the whole reason it is small.

**`StrategyPolicy`** is new, alongside the existing `IntentionPolicy`, `ActionPolicy` and
`OccurrencePolicy`. `update()` returns true when the strategy's intention belongs to the user. It is
what gates the verdict route, which is keyed on a strategy rather than on a loop.

**Requests:** `StoreExperimentRequest`, `StoreVerdictRequest`, `StoreNoteRequest`,
`StoreActionRequest`. `authorize()` returns true in each and ownership is enforced in the controller
via the policy — the existing convention, stated in `RescheduleActionRequest`'s own comment.

**Controllers:** `ExperimentController` (`store`), `VerdictController` (`store`), `NoteController`
(`store`), and `store`/`destroy` added to the existing `ActionController`.

**UI**, all on `resources/js/pages/loops/show.tsx`, which is already the lab record:

- the active experiment card gains a **Conclude** control — verdict, and a note field that becomes
  required when the verdict is `failed`
- below the experiments ladder, a **Start the next experiment** form: intervention point, approach,
  why the current version is being superseded, optional planned length, and the keep-or-change
  cadence choice
- the notes list gains a compose box
- the actions list gains **Add an action** and a per-action **Retire**

`show.tsx` is already a large file and this adds four forms to it. Each form moves into its own
component under `resources/js/patyourself/loops/` and the page composes them, following how
`strategy-timeline.tsx` and the progress sub-components are already factored.

## Error handling

- **Not owned, or not found** — one generic 404 through the policy. No cross-user existence leak,
  matching the MCP tools.
- **`StrategyTransitionException`** (concluding an already-concluded version; starting an experiment
  from a non-active version) — caught and returned as a validation error on the form, not a 500. The
  realistic cause is two tabs, not a malformed request.
- **A loop with no active strategy version** cannot take a new action; `CreateAction` already throws
  for this. Surfaced as a validation error explaining that the loop needs an active experiment first.
- **`InvalidArgumentException` from a negative `review_after_days`** is prevented by validation
  (`integer`, `min:0`, nullable) before it can reach the Action.

## Constraints

These are the project's, not this feature's, and they bind every screen here:

- Reasons, notes and verdict notes are **verbatim**. Never trimmed, squished or sentence-cased.
- **Failure language is about the strategy, never the user.** No discipline, willpower or motivation
  framing anywhere in the copy, including validation messages.
- **No gamification.** A streak is a statistic. Nothing congratulates.
- **No numeric targets**, and no quantities on eating loops.
- **The notebook never nags.** `planned_days: null` is open-ended and must never render as a
  countdown. The review prompt states a fact; it does not chase.
- **Append-only.** A version supersedes by being newer. Nothing is edited in place, nothing is
  deleted.

## Testing

- `StoreExperimentRequest` / `StoreVerdictRequest`: accept and reject the same values as the MCP
  tools, asserted against the model constants rather than literals.
- Each route: happy path, another user's loop 404s, guest redirected.
- Conclude: `failed` without a note is rejected; note stored verbatim from a padded, mixed-case
  fixture.
- Start: the keep-cadence path passes null and the prior schedule survives; the change-cadence path
  re-proposes it. This is the pass-vs-omit distinction and it needs a test that would fail if the
  two were swapped.
- Retire: the action is archived, not deleted, and its occurrences and outcomes still exist
  afterwards.
- Component tests for each new form, including that the note field appears only for a `failed`
  verdict.
- Adding props to `loops/show` will break that page's existing component tests — expected, and they
  get updated in the same task.

---

# Phase B — Reach

## Decisions

### Timezone gets a settings screen, and changing it re-anchors

`PATCH /settings/timezone` exists but is write-only from JavaScript on first load. There is no way to
see your timezone, and no way to correct it — so a bad browser guess or a move is currently
unfixable from the UI, and every schedule in the app depends on it.

The screen shows the stored zone, the zone the browser currently reports, and a picker.

The harder half: **changing the zone must re-anchor future occurrences.** Occurrences store absolute
instants, so without re-anchoring a user who moves from +10 to +1 keeps getting cued at the old
local time forever, silently. Changing the zone purges unlogged future occurrences and re-anchors
each action's `series_started_at`, exactly as activating a paused loop already does.

**A ruling to respect while doing this:** the purge-and-re-anchor duplication between
`RescheduleAction` and `UpdateIntention` is *intentional*. Extraction was proposed during the
roll-forward work and the owner ruled the two call sites conceptually independent. This spec does
not overturn that — but a third copy is where "two independent call sites" becomes "we now maintain
three of these". The plan should extract it to a service that all three call, and that extraction
needs the owner's explicit agreement first, because it reverses a prior decision.

Deliberately not included: moving *past* occurrences. They happened when they happened.

### One-click logging from the reminder email, unauthenticated

The reminder email currently opens the app. The point of a reminder is to be answered from the
lock screen, so it gains two buttons — **Done** and **Didn't happen** — behind signed, expiring URLs
that write the outcome without a login.

The security trade, stated plainly: anyone holding that email can log that one occasion. The blast
radius is one outcome on one occasion, correctable in the app, and the alternative costs the
one-click property on exactly the device where reminders get read. Accepted.

Mitigations that come with it:

- **Signed** (`signed` middleware) and **expiring** — 7 days, which is long enough for a late
  check-in and short enough that an old mailbox is not a standing key.
- **Effectively single-use**: an occasion that already has an outcome is not written again; the page
  says it is already logged. This gives single-use semantics with no new state to store, and it
  makes the double-click case correct for free.
- **Rate limited**, since it is the app's only unauthenticated write.
- The confirmation page is a standalone Blade view, not the Inertia app: no session, no nav, no data
  beyond what was just logged and a link into the app.

**`failed` is not in the email.** A failure must carry the user's stated reason, and a one-click
failure would either drop it or invent it. The third button deep-links into the app with the outcome
preselected and the reason field focused.

### The PWA installs and caches the shell — nothing more

`vite-plugin-pwa` (approved as a new dependency), a manifest, icons, and a service worker that
precaches the built assets. Installable, opens from the home screen, loads instantly.

**Document requests are `NetworkOnly`.** Caching Inertia HTML would serve a stale CSRF token and
produce 419s that look like random logouts — the classic footgun, and it is worth stating in the
config's own comment so it does not get "optimised" later.

Explicitly out: offline write queueing. Queuing outcomes offline and replaying them on reconnect is
its own subsystem — replay ordering, conflict rules, a new failure surface — and it is more work
than the other eight items combined.

## Architecture

| Route | Name | Notes |
| --- | --- | --- |
| `GET /settings/timezone` | `timezone.edit` | new screen; the existing `PATCH` is unchanged in shape |
| `GET /o/{occurrence}/{outcome}` | `occurrences.quick-log` | `signed` + throttle, **no** `auth` |

- `QuickLogController` resolves the occurrence, refuses anything but `completed` / `skipped`, and
  calls the existing `LogAction`. Returns a Blade confirmation view.
- `ActionDueNotification::toMail()` gains the two buttons via `URL::temporarySignedRoute`.
  `DailyDigestNotification` gains them per row.
- `resources/js/pages/settings/timezone.tsx`, following the existing settings pages.
- `vite.config.ts` gains the PWA plugin; `resources/js/app.tsx` registers the worker.

## Error handling

- **Invalid or expired signature** — a plain "this link has expired" page with a link to the app.
  Never a stack trace, never a redirect to login carrying the intended URL.
- **Already logged** — the confirmation page says so and does not write again.
- **Unknown outcome in the URL** — 404. Only two values are ever generated.
- **Timezone not in `timezone_identifiers_list()`** — rejected by validation.

## Testing

- Quick-log: happy path for both outcomes; an unsigned URL is rejected; an expired one is rejected;
  a second click does not write a second outcome; `failed` in the URL 404s; another user's
  occurrence is not writable.
- The mail carries a valid signed URL for the right occurrence — assert by extracting and following
  it, not by matching the string.
- Timezone: the screen renders the stored zone; a change re-anchors future occurrences and leaves
  logged ones alone; an invalid identifier is rejected.
- PWA: the manifest builds and the document route is not precached. Asserted against the generated
  service worker, since the failure mode is silent.

---

# Phase C — The record and the machinery

## Decisions

### Export is JSON and Markdown, read-only

The app's whole claim is that it is the record. A record you cannot take out is a record someone
else is holding.

Two formats from one endpoint: **JSON** is the complete machine-readable dump — loops with their
chain, every strategy version with its verdict and note, actions, occurrences, outcomes with their
verbatim reasons, notes, reflections. **Markdown** is the lab notebook as prose, the thing you would
actually read back.

No importer. A round trip means identity collisions, versioning and partial-failure semantics, and
nothing needs it. If moving accounts ever becomes real, the JSON is what an importer would be
written against.

### The loops index gets the filter the API already has

`Api\IntentionController::index()` supports `?status=`; the web index has no filter and no search.
Adding `?status=` and `?q=` to the web index closes a gap that exists only because the two surfaces
were written at different times. Search covers the title and the four chain fields — the cue is
often what you remember.

### The failure alert does not ride the queue that failed

The daily digest stamps `digest_last_sent_on` immediately after `notify()`, which only *enqueues*.
If the job exhausts its retries the user loses that day's digest and nothing says so. The spec that
introduced it named a `failed_jobs` alert as the mitigation and did not build it.

An hourly scheduled command mails the owner when a job has failed since the last check. Two details
make it work rather than look like it works:

- **It sends synchronously**, not through the queue. An alert about a broken queue that is itself
  queued is not an alert.
- **The high-water mark is a cache key**, not a new table. If the cache is cleared the worst case is
  one duplicate alert, which is the right direction to fail in.

## Architecture

| Route / command | Name | Notes |
| --- | --- | --- |
| `GET /export` | `export.show` | `?format=json\|md`, streamed download, own data only |
| `GET /loops` | `loops.index` | gains `?status=` and `?q=` |
| `php artisan jobs:alert-failed` | — | hourly in `routes/console.php` |

- `RecordExport` service builds the payload once; two small formatters render it. The service is the
  unit worth testing; the formatters are rendering.
- `FailedJobsNotification`, sent on the sync connection, mirroring how `ActionDueNotification` pins
  its database channel.

## Error handling

- Export of an account with no loops produces a valid empty document, not an error.
- An unknown `format` falls back to JSON rather than erroring.
- `q` is used as a bound parameter in a `LIKE`, never interpolated.
- The alert command is idempotent within its window and does not advance the high-water mark if
  sending throws.

## Testing

- Export: JSON contains every model the record holds, and a verbatim failure reason survives the
  round trip byte-for-byte; Markdown renders a loop with two versions and a verdict; another user's
  data never appears; the empty account produces a valid document.
- Filter and search: each filter narrows correctly, they compose, `q` matches on the chain as well
  as the title, and a `%` in `q` is treated as a literal.
- Alert: fires once for a new failure, does not re-fire on the next tick, sends on the sync
  connection, and does not advance the mark when the mail throws.

---

## Out of scope, for all three phases

- **Creating a loop in the app.** It stays with Claude, deliberately. The chain has to be talked
  through to be worth anything.
- **Writing a reflection in the app.** A reflection is a synthesis across the record — the thing the
  coach is actually for. `write-reflection` stays MCP-only.
- **Editing or deleting notes and outcomes.** Append-only.
- **Offline writes.** See Phase B.
- **An import path.** See Phase C.
- **Any change to the MCP surface.** These nine features add a second door to writers that already
  exist; they do not change what the coach can do.
- **The open items from the 2026-08-29 board reconciliation** — reconnecting the connector,
  `laravel/ai`, `ANTHROPIC_API_KEY`, the three.js CDN load, MCP rate limiting. Tracked separately in
  ClickUp Phase 1.5 and untouched here.

## Assumptions

- Single user in practice, so no migration or compatibility burden for other accounts.
- The connector is expected to be reconnected independently of this work. These features are
  insurance against it breaking again, not a replacement for fixing it.
- Mail is deliverable in production (SES is configured); the quick-log buttons are worth nothing if
  the mail is not arriving, and that is checked before Phase B ships rather than assumed.
