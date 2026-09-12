# The notebook

The notebook is the spine: the loop, the experiment on it, the action it prescribes, the occasion
that action produced, and the outcome recorded against that occasion. Every other subsystem in this
app hangs off those five rows. `docs/WORKFLOWS.md`, `docs/GYM.md`, `docs/MCP.md` and `docs/BLOB.md`
all assume this file.

This is the current-state reference: how it works today, why it works that way, and which decisions
must not be reopened. The per-phase specs in `docs/superpowers/specs/` are frozen design records —
accurate for the day they were written and superseded in places since. **Where this file and a spec
disagree, this file is right.**

**This file is not on `CompanionVocabularyTest::sourceFiles()` and must not be added.** Section 9
documents where the app is allowed to keep score and where it is forbidden, which cannot be written
without naming the banned words, so scanning it would fail on its own subject matter. `docs/BLOB.md`
carries the same exemption for the same reason.

---

## 1. The one sentence

**The app authors nothing and infers nothing; it records what a person said happened, and keeps it.**

Structured data arrives already-formed over MCP. The server validates and persists; React renders.
There is no model provider in this codebase, no chat endpoint, and no token budget — `coach_usages`
and `agent_conversations` were dropped in the zero-LLM pivot, and `tests/Feature/NoLlmTest.php`
keeps them gone.

## 2. The chain

```
Intention (a loop)            the habit, as cue -> craving -> response -> reward
  └─ Strategy (a version)     one experiment: a hypothesis, one intervention point, a verdict
       └─ Action              a standing prescription: what to do, on what cadence
            └─ Occurrence     one occasion that prescription produced
                 └─ ActionLog the outcome recorded for that occasion
```

Each arrow is a `hasMany` and each level below `Intention` is bound to the level above by a foreign
key that never moves. That binding is what makes attribution structural rather than inferred from
dates: `Strategy::actionLogs()` reaches logs through `actions.strategy_id`, so "which experiment was
running when this was recorded" is a join, not a timestamp comparison.

### Why the occasion exists

An outcome attaches to an `Occurrence`, never to the `Action`. Three things follow, and all three are
load-bearing:

- **An occasion from days ago is still loggable today.** Nothing expires, nothing is overdue. The
  log is dated by the occasion it describes rather than by the moment it was typed.
- **The action row is never written by the logging flow.** Completing one occasion says nothing
  about the standing prescription, which is why `Action::STATUSES` is only `active` / `archived` —
  a prescription is live or it is put away, and whether any given occasion of it was answered is a
  fact about the occasion.
- **One occasion, one log.** Enforced by a unique index on `action_logs.occurrence_id`. That
  constraint is the economy the companion ladder spends; see §9.

## 3. The five rows, plus the six that hang off them

Read from the migrations, not from prose. Column lists are exact as of 2026-09-12.

| Table | What it is | Columns |
| --- | --- | --- |
| `intentions` | A habit loop | `user_id`, `title`, `description`, `type` (build \| break), `status`, **`cue`, `craving`, `response`, `reward`**, **`workflow`**, `metadata` |
| `strategies` | One versioned experiment | `intention_id`, `version`, `status` (active \| superseded \| retired), `intervention_point` (cue\|craving\|response\|reward), `approach`, `rationale`, `parent_strategy_id`, `change_reason` (initial \| stacked_on_success \| restrategized_on_failure), `superseded_reason`, `review_at`, `verdict` (worked \| failed \| inconclusive), `verdict_note`, `metadata` |
| `actions` | A standing prescription | `intention_id`, `strategy_id`, `title`, `description`, **`series_started_at`** (the anchor), `recurrence`, `status` (active \| archived), `metadata` |
| `occurrences` | One occasion | `action_id`, `scheduled_for`, `fired_at` |
| `action_logs` | One outcome | `action_id`, **`occurrence_id`** (unique), `user_id`, `outcome`, **`reason`**, `context`, `context_fields`, `logged_at`, `metadata` |
| `notes` | A discrete observation on a loop | `intention_id`, `body`, `noted_at` |
| `summaries` | The rolling narrative | `user_id`, `intention_id`, `scope`, `content`, `window_start`, `window_end`, `events_count`, `metadata` |
| `companion_remarks` | A line the coach gave Blob | `user_id`, `intention_id`, `body` |
| `exercises` · `action_exercises` · `performed_sets` | The gym workflow | see `docs/GYM.md` |

`metadata` JSON columns carry provenance (which tool authored a loop, which prompt version) without
polluting the typed columns. Enum-like fields are stored as strings and the model constants own the
allowed set — `Intention::TYPES`, `Strategy::VERDICTS`, `Action::STATUSES` and so on.

**`actions.scheduled_for` is gone.** `2026_08_26_120001_retire_action_scheduled_for` dropped it. An
action has an *anchor* (`series_started_at`) and a cadence; the concrete moments live on
`occurrences`. A doc or a query still reaching for `actions.scheduled_for` predates that migration.

**`summaries` is written.** README said "schema present, currently unwritten" for a long time and it
was true when written; `App\Actions\WriteReflection` fills it now, and the progress screen renders
the latest row.

## 4. Scheduling: from a cadence to concrete occasions

Four collaborators, each with one job.

| Class | Job |
| --- | --- |
| `Scheduling\Recurrence` | The enum: `daily`, `weekdays`, `weekly`. The token `once` and `null` both map to `null` — a one-off |
| `Scheduling\Schedule` | Pure date math. `firstOccurrence()`, `advance()`, `nextAfter()`. No database |
| `Scheduling\MaterialiseOccurrences` | Walks the anchor forward and writes the occasions that walk implies |
| `Scheduling\ReanchorsSeries` | Moves an anchor and drops the occasions belonging to the cadence being left behind |

**Stored datetimes are UTC; the user's IANA timezone localises them.** Weekday and weekly math is
evaluated in the user's zone, which is why `Schedule::advance()` round-trips through
`setTimezone($timezone)` — it preserves wall-clock time and so stays DST-correct and keeps weekly's
weekday.

### Materialising

`MaterialiseOccurrences` is **lazy, idempotent, and read-path only**.

- **Lazy** — it runs on a read path and never as a side effect of a write, so logging an outcome can
  never quietly conjure rows.
- **Idempotent** — rows go in through an upsert against the unique `(action_id, scheduled_for)`
  index that updates nothing on conflict. An overlapping run writes no duplicates, needs no lock,
  and leaves an already-logged occasion exactly as it is.
- **Bounded** — `MAX_SLOTS_PER_ACTION = 1000` per pass, so a very old anchor cannot run away.

Two details that look like details and are not:

**The horizon is the end of the user's local day, not `now`.** The today list splits into due-now and
upcoming, and "upcoming" needs real rows to select from. A horizon at `now` makes that half of the
list permanently empty.

**The walk always restarts from the anchor, never resumes from the last materialised slot.**
`RescheduleAction` re-anchors, and the old grid and the new one do not share a phase — resuming
would continue the cadence that was abandoned.

### Anchored actions have no grid

An action with `series_started_at = null` is cue-anchored: "train after work". It has no clock time,
so the scheduler never fires it and it materialises nothing. It reaches the today list through the
second half of `TodaysOccasions` instead (§5), and it gains an occasion only when something creates
one — pressing a verdict, or beginning to record (see `docs/WORKFLOWS.md` §5).

### Which occasion does a caller mean?

`Scheduling\ResolvesOccasionSlot` answers that for a caller that names none, and it answers it
**two different ways on purpose**:

| Method | Window | Caller |
| --- | --- | --- |
| `liveSlotFor()` | today's unlogged slots **up to now** | `LogAction` — a verdict |
| `todaysSlotFor()` | today's unlogged slots, **due or not yet due** | `Workflows\MaterialisesOccasion` — beginning to record |

The ceiling is the whole difference. A verdict is resolved at the moment it is pressed and logs what
it resolved, so bounding it to slots already due is correct — you do not log a session you have not
had. A recording session resolves at the start and is logged at the end, and warming up at 18:00 for
a 19:00 slot is ordinary. Under `liveSlotFor()`'s ceiling tonight's slot is invisible, so a phantom
18:00 occasion gets minted beside it, the sets hang off the phantom, and the real slot is stranded
unlogged on `/catch-up`.

Both fall through to `freeSlotAt()`, which stamps a slot at `now` — for a cue-anchored action with
no grid, and for a day whose slots are all already logged. Occasions are stored to the second and
`freeSlotAt()` walks forward a second at a time (up to 60) through a shared `firstOrCreate`, which
is what makes two taps inside the same second unable to mint two occasions.

## 5. What is due today

`Scheduling\TodaysOccasions` is the **one** definition, shared by the daily digest, the
`today-actions` MCP tool and the action cards, so those three can never disagree about what today
holds. It returns two halves:

1. Unlogged occasions inside the user's local day.
2. Cue-anchored actions, which have no schedule and so usually no occasion to be inside it.

**The two halves are exclusive by construction, not by coincidence.** Beginning to record
materialises an occasion for a cue-anchored action, and from that moment the action belongs to the
first half rather than the second. The `whereDoesntHave` that enforces this is load-bearing: two
entries for one action mean two cards, two verdicts and `logCount` +2 for a single session, and the
unique index on `action_logs.occurrence_id` cannot catch it because they are two real, distinct
occasions.

**The window is the whole point.** Occasions never expire, so selecting every unlogged past occasion
would build a backlog and turn the digest into a nag. Yesterday's misses are reachable only from
`/catch-up`, which the user goes looking for.

**`due` is derived from the clock, not from whether a cue was delivered.** `fired_at` is the trigger
engine's idempotency guard and nothing else.

## 6. Delivering the cue

`Scheduling\TriggerEngine`, run every minute by `actions:fire`, delivers the cue for each occasion
whose moment has arrived.

- **Firing is idempotent.** Each occasion is claimed with a guarded conditional update on
  `fired_at`, so an overlapping or repeated run fires every occasion at most once.
- **Bounded to the user's local day.** Occasions never expire, so without the window an outage would
  come back and deliver every missed cue at once. A missed occasion is not a cue worth ringing
  later; it stays loggable, quietly, on `/catch-up`.
- **It iterates users rather than running one global query,** because midnight is not the same
  instant for two people.

## 7. Recording an outcome

`App\Actions\LogAction` is the only place the logging flow writes to the database.

```php
LogAction::handle(User $user, Action $action, array $data, ?Occurrence $occurrence = null): ActionLog
```

The fourth parameter is the contract that matters. **Left null, the verdict resolves its own occasion
afresh at the moment it is pressed** — a different question asked at a different time, which may well
be a different answer. A recording surface that materialised an occasion earlier must hand that
occurrence back here, or the sets and the verdict land on different rows. That is pinned by
`Tests\Feature\Workflows\MaterialisesOccasionTest::test_the_verdict_lands_on_the_session_only_when_the_caller_names_it`.

Three outcomes: `completed`, `failed`, `skipped`.

- **A failure must carry the user's stated reason, stored verbatim** — never trimmed, squished or
  sentence-cased. It is the raw material the next experiment is written from.
- **`skipped` means the occasion never happened** (no meal, travelling, ill). If it happened and the
  strategy did not hold — including not thinking about it — that is `failed`.
- **A log is immutable.** There is no edit path.

Logging also marks the in-app cue read, and **every earlier unanswered cue for the same action**.
The narrower rule leaves one unread behind per missed day, and the unread count renders as a badge
in the primary navigation: a running tally of the unlogged set, which is the nagging this notebook
does not do. Bounded to "at or before" so catching up Tuesday leaves today's fresh cue standing, and
bounded to this action so answering dinner says nothing about lunch. Nothing here touches the missed
occasions themselves — they stay unlogged and wait quietly on `/catch-up`.

## 8. Experiments

Every strategy version **is** an experiment: a hypothesis, an intervention point, optionally a
planned length (`review_at`), and a verdict.

| Act | Class | What it does |
| --- | --- | --- |
| Start the next experiment | `StartExperiment` | Supersedes the current version and creates the next one, in one transaction |
| End this experiment | `ConcludeExperiment` | Writes a verdict. **Does not supersede** |

**Concluding is not superseding.** A version concluded as `worked` stays active and keeps running.
Only starting the *next* experiment supersedes, and it records why — `stacked_on_success` or
`restrategized_on_failure` — and where in the chain the new version intervenes.
`Strategy\BehavioralChain::direction()` names that move as `earlier` / `later` / `same` so the
timeline can render which way the intervention shifted.

**`review_at` is deliberately left alone when concluding.** Clearing it was redundant —
`isUnderReview()` short-circuits on `! isConcluded()` — and destructive, because `plannedDays()`
derives entirely from `review_at` and there is no `concluded_at` to fall back on. Nulling it erased
the only record of how long the experiment was planned to run.

**The notebook never nags.** `Strategy::isUnderReview()` requires *active* **and** not concluded
**and** a `review_at` in the past. A superseded version never reports under review even with a past
`review_at` and no verdict, because starting the next experiment is how the ordinary flow moves on
without a formal conclusion — without that check, a version replaced months ago would read as "under
review" forever. Open-ended experiments are never under review either.

**`dayOfExperiment()` stops when a version is superseded,** taken from the successor's own
`created_at` rather than this row's `updated_at`: `StartExperiment` supersedes and creates the
successor in one transaction, so the successor's start *is* the moment this one stopped, and it
cannot drift. A concluded-but-still-active version is deliberately not capped — freezing its count
would stop the clock on the experiment the loop screen is currently showing.

## 9. Where the app is allowed to keep score

This is the boundary most likely to be got wrong, and the reason
`CompanionVocabularyTest::sourceFiles()` is a **list of files** rather than a global grep.

**The notebook keeps statistics. The companion never does.**

| Surface | Keeps score? |
| --- | --- |
| `/progress` — `Progress\LoopProgress`, `Strategy\OutcomeStreak` | **Yes, deliberately.** Streak, completion rate, totals, a recent strip |
| `loop-progress` MCP tool | **Yes** — same read model |
| Everything on the vocabulary list — the companion, the gym module, the verdict form, the loop screen's action layer | **No.** Scanned for `streak`, `completion rate`, `percent`, `points`, `level up` and the rest, comments included |

So `progress/index.tsx` is deliberately *off* the list and a gym progression screen is deliberately
*on* it. A completion rate on a progress card is a statistic the owner asked to see; the same number
beside a routine is a score against a target, which turns recording into being marked.

Two properties of `LoopProgress` worth keeping:

- **`skipped` is neutral** — excluded from the rate, kept in the recent strip. An occasion that never
  happened is not a failure.
- **Streak is scoped to the active strategy; rate and totals span the loop's lifetime,** so they
  survive strategy revisions.

`OutcomeStreak` is a passive observation with nothing attached: nothing reads it to trigger a
revision. It is surfaced for the owner to see.

## 10. Around the edges

| Service | What it does, and the one thing to know |
| --- | --- |
| `Reminders\DigestDispatcher` | One email a day per subscriber, at or **past** their local time. "At or past" rather than an exact minute match, because an exact match stakes the whole day's digest on one scheduler minute succeeding and a missed minute costs the user that day silently. A per-local-day stamp caps it at one |
| `Reminders\QuickLogLinks` | The signed Done / Didn't-happen pair a reminder mail offers, valid 7 days. **`failed` is deliberately absent** — a failure must carry a reason, and a one-click link cannot collect one |
| `Alerts\FailedJobsAlert` | Mails the owner when a queued job has failed. Tracks a high-water mark on `failed_jobs.id`, not a timestamp: `failed_at` is second-precision, so a burst inside one second — exactly what an outage produces — can tie with the mark and be skipped permanently. An auto-increment id cannot tie |
| `Export\RecordExport` | One user's whole record as a plain array, rendered by the JSON and Markdown formatters. Nothing formats, rounds, summarises or scores; text the user wrote is copied out exactly as stored, whitespace and all. The app's claim is that it is the record, and a record you cannot take out is a record someone else is holding |
| `Authoring\Authored*` | The DTOs that structured MCP input arrives as. `AuthoredIntention::fromStructured()` is the validation boundary for `create-loop` |
| `Companion\*` | Blob. See `docs/BLOB.md` |
| `Workflows\*` | The module seam. See `docs/WORKFLOWS.md` |
| `Training\*` | The gym module. See `docs/GYM.md` |

## 11. Rulings that must not be reopened

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **Outcomes attach to occurrences, never to actions** | It is what dates a log by the occasion it describes, makes an old occasion loggable, and keeps the prescription un-written-to by the logging flow | The whole catch-up model, and "nothing is overdue" |
| **One occasion, one log** | `logCount` is what the companion ladder spends. A surface more granular than one occasion would inflate the economy every other loop is measured against | Unique index on `action_logs.occurrence_id`; the ladder's meaning |
| **Materialising never creates an ActionLog** | The occasion exists to hang records on; the verdict is still pressed separately, by a person, afterwards | An inferred verdict overruling the person who was there |
| **Materialising is read-path only** | A write path that conjures occasions means logging an outcome can create rows | Rows appearing as a side effect of an unrelated write |
| **The materialisation horizon is end-of-local-day** | "Upcoming" needs real rows to select from | Half the today list permanently empty |
| **Concluding does not supersede** | A strategy that worked keeps running; inconclusive is a real answer | A working experiment cancelled by being judged |
| **`review_at` survives a conclusion** | `plannedDays()` derives entirely from it and there is no `concluded_at` | The only record of the planned length, erased |
| **`skipped` is neutral in the rate** | An occasion that never happened is not a failure | Travel and illness reading as failures |
| **A failure carries the user's words verbatim** | Those reasons are what the next experiment is written from | Paraphrase replacing evidence |
| **No one-click failure link** | A failure must carry a reason and a link cannot collect one | A reasonless failure, which is a data point with nothing in it |
| **The digest is "at or past", not an exact minute** | An exact match stakes the day on one scheduler minute | A silent missing day per missed minute |
| **`FailedJobsAlert` marks on id, not timestamp** | Second-precision ties during exactly the burst it exists to catch | Failures skipped permanently and silently |

## 12. Traps that have already bitten

1. **`assertDatabaseMissing` on a column that does not exist is a constant-false predicate.** SQLite
   degrades the unresolvable identifier to a string literal, so it passes forever; MySQL errors
   outright. **Tests here are SQLite, production is MySQL.**
2. **Raw SQL that passes on SQLite can error on MySQL.** Bind values; never embed quoted literals.
   `IntentionController::matchTitleOrChain` records the specific quoting bug.
3. **Column-limited eager loads hide new columns forever.** `->with('rel:id,title')` returns null for
   a column added later, with the suite green. This project has been bitten three times in one
   branch; `Training\SessionScreen` carries the note.
4. **A missing `ORDER BY` tiebreaker is a cross-engine difference.** Two occasions can share a
   `scheduled_for` — different actions, same slot — and which one wins is whatever the engine
   returns, which differs between SQLite here and MySQL in production. `LastPerformance` and
   `ExerciseHistory` both tiebreak on `occurrences.id`.
5. **`whereKey()` on a collection of integers routes to `whereIntegerInRaw`,** interpolating ids as
   literals rather than binding them. The SQL text grows with the table, so neither a query count nor
   a binding count can see it. `LastPerformance` records the shape it replaced.
6. **A test written against a fixture's default asserts nothing.** If you cannot name the mutation
   that turns a test red, it is decoration.
7. **A guard over a state the system cannot reach costs you the state it can.** The scarf-clearance
   guard asserts a form/item pairing the companion ladder cannot produce, and it is what cost the
   `blob` form's sleep row its breath. Assert what is reachable.
8. **`php artisan test` reports ~470 fewer assertions without `npm run build` first.**
   `PwaManifestTest` skips itself when `public/sw.js` is absent. A fresh worktree has no
   `public/build`.

## 13. Where everything lives

```
app/Models/
  Intention.php            the loop; nextStrategyVersion(), activeStrategy(), activeAction()
  Strategy.php             the experiment; isUnderReview(), dayOfExperiment(), successor()
  Action.php               the prescription; nextOccurrenceAt(), actionExercises()
  Occurrence.php           the occasion; unlogged() and unfired() scopes
  ActionLog.php            the outcome
  Note.php · Summary.php · CompanionRemark.php

app/Actions/               every write, one act each
  LogAction.php            the only writer on the logging path
  StartExperiment.php · ConcludeExperiment.php
  CreateIntention.php · UpdateIntention.php · DeleteIntention.php
  CreateAction.php · RescheduleAction.php · ArchiveAction.php
  PersistAuthoredIntention.php · LogNote.php · WriteReflection.php · WriteBlobRemark.php
  Training/                the gym module's writers — see docs/GYM.md

app/Services/
  Scheduling/              Recurrence, Schedule, MaterialiseOccurrences, ReanchorsSeries,
                           ResolvesOccasionSlot, TriggerEngine, TodaysOccasion(s)
  Strategy/                BehavioralChain, OutcomeStreak
  Progress/LoopProgress.php
  Authoring/               AuthoredIntention, AuthoredStrategy, AuthoredAction
  Reminders/               DigestDispatcher, QuickLogLinks
  Alerts/FailedJobsAlert.php
  Export/                  RecordExport + the JSON and Markdown formatters
  Workflows/               see docs/WORKFLOWS.md
  Companion/               see docs/BLOB.md
  Training/                see docs/GYM.md

routes/console.php         actions:fire (every minute), reminders:digest (every minute),
                           jobs:alert-failed (hourly, runInBackground)
routes/web.php · routes/api.php · routes/ai.php (the MCP mount)

tests/Feature/Scheduling/ · Strategy/ · Progress/ · Reminders/ · Export/ · Notes/ · QuickLog/
tests/Feature/NoLlmTest.php               keeps the LLM out
tests/Feature/DeploymentReadinessTest.php keeps route:cache working
```

## 14. What is not done

- **`create-loop` cannot set a workflow.** It routes through the `AuthoredIntention` DTO, which has
  no `workflow` property. `update-loop` can. See `docs/MCP.md` §6.
- **The Settings area still wears the stock Laravel starter-kit shell.**
- **A catch-up acknowledgement is an open design question,** not code.
- **`summaries.scope` supports `user` as well as `intention`,** and nothing writes a user-scoped row.
- **Phases B, C and D1/D2 have never been verified in production** — mail arriving, one-click links
  on a phone, the PWA installing. See `docs/DEPLOY-FORGE.md` §14.
