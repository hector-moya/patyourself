# PatYourSelf

A zero-LLM habit **lab notebook**. It persists and visualises habit **loops**
(cue → craving → response → reward chains), worked via versioned
**strategies** — each version is an **experiment**: it carries a hypothesis,
optionally a planned length, and ends with a verdict. The conversation happens
outside this codebase, in Claude Desktop over an MCP connector; PatYourSelf is
the record, not the coach — celebrating the wins so you can _pat yourself_ on
the back.

**Stack:** Laravel · Inertia + React + Vite (TypeScript) · SQLite · Queues ·
Sanctum (web/API auth) · Passport (OAuth for the MCP endpoint).
**Shape:** a web app (loops, progress, inbox) on a mobile-ready API, plus a
Laravel MCP server that is Claude's only way to author or read a loop.

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

| Table          | Purpose | Key columns |
|----------------|---------|-------------|
| `users`        | Account (web + API auth) | name, email, password, 2FA, passkeys, timezone, email-reminder prefs |
| `intentions`   | A habit **loop** | type (build \| break), status, **cue / craving / response / reward**, `metadata` (provenance, tags, confidence) |
| `strategies`   | **Versioned experiment** on an intention | `version`, status (active \| superseded \| retired), `intervention_point` (cue\|craving\|response\|reward), `approach`, `parent_strategy_id` (lineage), `change_reason` (initial \| stacked_on_success \| restrategized_on_failure), `superseded_reason`, `review_at`, `verdict` (worked \| failed \| inconclusive), `verdict_note` |
| `actions`      | Concrete prescribed actions (the action cards) | bound to the strategy version that produced it, `scheduled_for`, `recurrence`, status, `metadata` |
| `action_logs`  | Completion / failure / skip events | `outcome`, **`reason`** (user-stated, esp. on failure), `logged_at` |
| `summaries`    | Rolling per-loop snapshots (schema present, currently unwritten) | scope (intention \| user), `content`, window, `events_count` |

`metadata` JSON columns throughout carry provenance and extra detail (e.g.
which tool authored a loop) without polluting the typed columns. Enum-like
fields are stored as strings; Eloquent casts own the allowed set.

---

## The screens

1. **Loops** (`/loops`, also the `/dashboard` landing route) — every habit
   loop at a glance, active ones surfaced first.
2. **Loop detail** (`/loops/{id}`) — habit anatomy
   (cue → craving → response → reward, with the active strategy's
   intervention point highlighted) and the versioned strategy/experiment
   timeline.
3. **Progress** (`/progress`, `/progress/{id}`) — streaks, completion rate and
   recent outcomes per loop, read-only.
4. **Inbox** (`/inbox`) — delivered "this is due" cues.

A shared, mobile-first layout shell (`resources/js/layouts/coach-layout.tsx`)
hosts these: full-bleed on phones, centered ~md column on desktop, with sticky
header / scroll area / bottom-nav + footer slots.

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
- Database is SQLite at `database/database.sqlite`; foreign keys enforced.
  ```bash
  php artisan migrate
  ```
- Checks: `npm run types:check` · `npm run lint:check` · `vendor/bin/pint`
- Tests: `php artisan test` (or `composer test`).
- The MCP server lives at `/mcp`, OAuth-protected via Passport
  (`mcp:use` scope). Connect it as a Claude Desktop/claude.ai connector to
  drive the app conversationally; see `app/Mcp/Servers/PatYourSelfServer.php`
  for the tools it exposes.

> Note: `public/build/` may be owned by `root` from an earlier sudo build,
> which breaks `npm run build` (EACCES). Fix once with
> `sudo chown -R "$USER" public/build`.
