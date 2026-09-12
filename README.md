# PatYourSelf

A zero-LLM habit **lab notebook**. It persists and visualises habit **loops**
(cue → craving → response → reward chains), worked via versioned
**strategies** — each version is an **experiment**: it carries a hypothesis,
optionally a planned length, and ends with a verdict. The conversation happens
outside this codebase, in Claude Desktop over an MCP connector; PatYourSelf is
the record, not the coach — celebrating the wins so you can _pat yourself_ on
the back.

**Stack:** Laravel 13 · PHP 8.4 · Inertia v3 + React 19 + Vite (TypeScript) ·
Tailwind v4 · installable PWA · database queue · Fortify + Sanctum (web/API
auth) · Passport (OAuth for the MCP endpoint).

**Database: SQLite locally and in tests, MySQL in production.** That is not a
detail to smooth over. Raw SQL and `assertDatabaseMissing` calls that pass on
SQLite can error or silently no-op on MySQL, and an `ORDER BY` without a
tiebreaker resolves differently on each. A green suite is not evidence that raw
SQL works in production.

**Shape:** a web app (the notebook, loops, progress, the companion, the gym) on
a mobile-ready API, plus a Laravel MCP server that is Claude's only way to
author or read a loop.

---

## The documentation

Two kinds of file, and one rule for when they disagree.

**Current-state references** — maintained, and right about today:

| File | Covers |
|------|--------|
| [`docs/NOTEBOOK.md`](docs/NOTEBOOK.md) | The spine: loop → experiment → action → occasion → outcome. Scheduling, the trigger engine, logging, experiments, and where the app is allowed to keep score. **Read this first.** |
| [`docs/WORKFLOWS.md`](docs/WORKFLOWS.md) | The module architecture: the two extension sites, the two mirrored registries, and how to author the second module |
| [`docs/GYM.md`](docs/GYM.md) | The one module that exists: routines, performed sets, the 876-row catalogue, progression |
| [`docs/MCP.md`](docs/MCP.md) | The 20 tools and 2 prompts, mapped tool → Action class → table, for a developer rather than for the coach |
| [`docs/BLOB.md`](docs/BLOB.md) | Blob, the companion: the ladder, how it is drawn, how the art is made |
| [`docs/DEPLOY-FORGE.md`](docs/DEPLOY-FORGE.md) | The deploy runbook, the server's real shape, and what has actually been run on production |
| `resources/js/patyourself/{sprites,scenes,ui}/README.md` | Asset-level detail: every sprite measurement, every scene job id, the nine-slice insets |

**Frozen design records** — `docs/superpowers/specs/` (26) and
`docs/superpowers/plans/` (29). One per phase, accurate for the day they were
written. They are the record of what was decided and why, and they are **not
edited** when implementation later disproves one; a dated correction note is
added and the original argument left readable.

> **Precedence:** where a current-state reference and a frozen spec disagree,
> the current-state reference is right.

None of the `docs/*.md` files are on `CompanionVocabularyTest::sourceFiles()`,
and they must not be added: they quote the banned vocabulary in order to
document it, so scanning them would fail on their own subject matter. Each says
so in its own header.

---

## The one-paragraph MVP (as built)

The owner talks to Claude Desktop, connected to PatYourSelf over MCP, and
describes a habit to build or break. Claude calls the `create-loop` tool to
author a structured **Intention** (a habit loop modelled as
cue → craving → response → reward) with a first **Strategy** — an experiment
that intervenes at one point in that chain — and hands back action cards the
owner reviews and activates in the app. Each day, actions get logged
completed / failed / skipped, and on a miss the owner _states why_. When an
experiment has run its course the owner concludes it with a verdict
(`worked` / `failed` / `inconclusive`) via Claude, or starts the next
experiment, which supersedes the current strategy version and records why.
History is never overwritten — every strategy shift is a new version with the
reason recorded.

---

## Core principles (non-negotiable)

1. **The app authors nothing.** Structured `Intention` / `Strategy` / action
   data arrives already-formed, through the MCP `create-loop` tool. The server
   only validates and persists it; React components only render it — no
   coaching or business logic in the client, and no model provider
   integration anywhere in this codebase.
2. **Strategies are versioned.** Failures record the user-stated reason and
   shift the intervention point up/down the behavioural chain. Never rewrite
   history in place.
3. **Every strategy version is an experiment.** It carries a hypothesis, an
   optional planned length (`review_at`), and ends with a verdict via
   `ConcludeExperiment`. Concluding does not supersede a version — only
   starting the *next* experiment does, via `StartExperiment`.
4. **The notebook never nags.** A version only reads as "under review" while
   it is still the active, running one — past its planned length and
   unconcluded. A superseded or already-concluded version never resurfaces
   that flag.

---

## Data model (migrated)

| Table              | Purpose | Key columns |
|--------------------|---------|-------------|
| `users`            | Account (web + API auth) | name, email, password, 2FA, passkeys, timezone, email-reminder prefs |
| `intentions`       | A habit **loop** | type (build \| break), status, **cue / craving / response / reward**, **`workflow`** (which module records this loop, or null), `metadata` |
| `strategies`       | **Versioned experiment** on an intention | `version`, status (active \| superseded \| retired), `intervention_point` (cue\|craving\|response\|reward), `approach`, `rationale`, `parent_strategy_id` (lineage), `change_reason` (initial \| stacked_on_success \| restrategized_on_failure), `superseded_reason`, `review_at`, `verdict` (worked \| failed \| inconclusive), `verdict_note` |
| `actions`          | A standing prescription (the action cards) | bound to the strategy version that produced it, **`series_started_at`** (the anchor), `recurrence`, status (active \| archived), `metadata` |
| `occurrences`      | **One occasion** an action produced | `action_id`, `scheduled_for`, `fired_at` (the cue-delivery idempotency guard) |
| `action_logs`      | Completion / failure / skip events | **`occurrence_id`** (unique), `outcome`, **`reason`** (user-stated, esp. on failure), `context`, `logged_at` |
| `notes`            | A discrete observation on a loop | `body`, `noted_at` |
| `summaries`        | The loop's rolling narrative, written by `write-reflection` | scope (intention \| user), `content`, `window_start` / `window_end`, `events_count` |
| `companion_remarks`| A line the coach gave Blob | `intention_id`, `body` |
| `exercises`        | The shared 876-row movement catalogue, plus the user's own | `external_id`, `name`, `category`, `equipment`, muscles, `instructions` |
| `action_exercises` | The gym workflow's **config** site: one routine row | `action_id`, `exercise_id`, `position`, `target_sets`, `target_reps` |
| `performed_sets`   | The gym workflow's **record** site: one set performed | `occurrence_id`, `exercise_id`, `set_number`, `reps`, `weight` (kg; null = body weight) |

Two things this table is easy to get wrong:

- **Outcomes attach to an occasion, never to the action.** That is what makes an
  occasion from days ago still loggable, and why nothing is ever overdue.
  `docs/NOTEBOOK.md` §2 has the consequences.
- **`actions.scheduled_for` no longer exists.** An action has an anchor and a
  cadence; the concrete moments live on `occurrences`.

`metadata` JSON columns throughout carry provenance and extra detail (e.g.
which tool authored a loop) without polluting the typed columns. Enum-like
fields are stored as strings; the model constants own the allowed set.

`coach_usages` and the `agent_conversations` tables were **dropped** in the
zero-LLM pivot. `tests/Feature/NoLlmTest.php` keeps them gone.

---

## The screens

1. **Notebook** (`/dashboard`, the landing route) — what is due today, with
   quick verdict controls and Blob.
2. **Loops** (`/loops`) — every habit loop at a glance, active ones first.
3. **Loop detail** (`/loops/{id}`) — habit anatomy (cue → craving → response →
   reward, with the active strategy's intervention point highlighted), the
   versioned strategy/experiment timeline, and the editable action layer —
   which is where a workflow draws its configuration surface.
4. **Catch-up** (`/catch-up`) — past occasions that went unlogged. Reachable
   only by going looking for it, deliberately: it is not a badge and not a
   backlog count.
5. **Progress** (`/progress`, `/progress/{id}`) — streaks, completion rate and
   recent outcomes per loop, read-only. This is the one area of the app that
   keeps score on purpose; see `docs/NOTEBOOK.md` §9.
6. **Companion** (`/companion`) — Blob. `docs/BLOB.md`.
7. **The gym** — a session (`/occurrences/{id}/session`), one exercise
   (`/occurrences/{id}/exercises/{id}`) and a movement across sessions
   (`/exercises/{id}/progression`). Only reachable from a loop that names the
   `gym` workflow. `docs/GYM.md`.
8. **Inbox** (`/inbox`) — delivered "this is due" cues.
9. **Export** (`/export`) — the whole record out, as JSON or Markdown.
10. **Settings** — profile, security, notifications, timezone, appearance,
    record. Still wearing the stock Laravel starter-kit shell.

A shared, mobile-first layout shell (`resources/js/layouts/coach-layout.tsx`)
hosts these: full-bleed on phones, centered ~md column on desktop, with sticky
header / scroll area / bottom-nav + footer slots.

The app is an **installable PWA** — `vite-plugin-pwa` generates the manifest and
service worker, and `resources/js/app.tsx` registers the worker itself. The
service worker must never precache a document: a cached document serves a stale
CSRF token, and the user sees random 419s that look like being logged out.
`tests/Feature/PwaManifestTest.php` guards that and two build-config-only fixes
that have no other coverage.

Claude Desktop, over the MCP connector, is the conversational surface: it can
list loops, read one, see what's due today, log an outcome, check progress,
and create a new (paused) loop for the owner to review and activate.

---

## Local development

- App is served by **Herd** at **https://patyourself.test** — do **not** run
  `php artisan serve`.
- Run the Vite dev server for the frontend:
  ```bash
  npm run dev
  ```
- Local database is SQLite at `database/database.sqlite`; foreign keys enforced.
  Tests use a separate in-memory SQLite connection. **Production is MySQL.**
  ```bash
  php artisan migrate
  ```
  The local file drifts behind `main` more often than you would expect — a
  missing column is usually unrun migrations, not a stale schema tool. Back the
  file up before migrating.
- The MCP server lives at `/mcp`, OAuth-protected via Passport (`mcp:use`
  scope). Connect it as a Claude Desktop / claude.ai connector to drive the app
  conversationally. `docs/MCP.md` has the surface; note that **a connector
  caches the tool list at connection time** and goes stale silently.

### The full check

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline on `main` as of 2026-09-12: **1015 PHP tests / 6386 assertions**,
**467 JS tests**, **0 TypeScript errors**. `tsc` is a floor, not a target.

**`npm run build` must run first.** Without `public/build`, `PwaManifestTest`
skips itself and silently drops ~470 assertions from the count — a suite that
looks green while testing less than you think.

### Traps worth knowing before you start

- **Herd serves this checkout at https://patyourself.test.** Never run
  `php artisan serve`. Herd will not serve a git worktree — use
  `php -S 127.0.0.1:8899 -t public` for one.
- **Never symlink `vendor/` into a worktree.** Composer bakes absolute paths, so
  PHP silently runs the *primary* checkout's code: your edits do nothing and new
  classes "do not exist". Run `composer install` in the worktree. A fresh
  worktree also has no Passport keys (`php artisan passport:keys`) and no
  `public/build`.
- `public/build/` may be owned by `root` from an earlier sudo build, which breaks
  `npm run build` (EACCES). Fix once with `sudo chown -R "$USER" public/build`.
- **Any new companion, workflow or module source file must be added to
  `CompanionVocabularyTest::sourceFiles()`.** A file absent from that list is
  scanned by nothing, and this project has been bitten by exactly that.
- **`route:list --except-vendor` hides `Route::inertia` pages**, because they
  resolve to `Inertia\Controller`. `settings/appearance` and `settings/record`
  are real routes that the filtered list does not show. Drop the flag before
  concluding a screen is unrouted.
