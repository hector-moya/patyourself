# PatYourSelf

A conversational habit / behaviour-change coach. You talk to a coach; it turns
your intentions into structured habit **loops**, prescribes small **actions**,
learns from how you do, and re-strategizes when you slip — celebrating the wins
so you can _pat yourself_ on the back.

**Stack:** Laravel · Inertia + React + Vite (TypeScript) · SQLite · Queues ·
Sanctum · provider-agnostic LLM coach.
**Shape:** a 3-screen web app on a mobile-ready API (Phase 2 = native mobile).

---

## The one-paragraph MVP

A user opens the **chat**, describes a habit they want to build or break, and
the coach authors a structured **Intention** (a habit loop modelled as
cue → craving → response → reward). The coach attaches a **Strategy** that
intervenes at one point in that chain and hands back **action cards**. The user
logs each action done or missed — and on a miss, _states why_. Those reasons
feed a rolling summary; when a strategy keeps failing the coach **restrategizes**
(shifts the intervention point and bumps the strategy version); when it works it
**stacks** the next habit on top. History is never overwritten — every strategy
shift is a new version with the reason recorded.

---

## Core principles (non-negotiable)

1. **AI authors data, UI renders it.** The LLM produces structured `Intention` /
   `Strategy` / action objects (JSON). React components only render them — no
   business logic or coaching decisions in the client.
2. **All LLM calls are server-side** (Laravel), for security and cost control.
   The client never talks to a model provider directly.
3. **`CoachService` is provider-agnostic.** Code against the interface; the
   vendor/driver is swappable.
4. **Strategies are versioned.** Failures record the user-stated reason and
   shift the intervention point up/down the behavioural chain. Never rewrite
   history in place.
5. **Pattern detection uses rolling summaries, not ML.**

---

## Data model (Phase 1 schema — migrated)

| Table          | Purpose | Key columns |
|----------------|---------|-------------|
| `users`        | Account (web + API auth) | name, email, password, 2FA, passkeys |
| `intentions`   | A habit **loop** | type (build \| break), status, **cue / craving / response / reward**, `metadata` (AI payload) |
| `strategies`   | **Versioned** intervention on an intention | `version`, status (active \| superseded \| retired), `intervention_point` (cue\|craving\|response\|reward), `approach`, `parent_strategy_id` (lineage), `change_reason` (initial \| stacked_on_success \| restrategized_on_failure), `superseded_reason` (user-stated) |
| `actions`      | Concrete prescribed actions (the action cards) | bound to the strategy version that produced it, `scheduled_for`, `recurrence`, status, `metadata` |
| `action_logs`  | Completion / failure / skip events | `outcome`, **`reason`** (user-stated, esp. on failure), `logged_at` |
| `summaries`    | **Rolling** pattern-detection snapshots | scope (intention \| user), `content`, window, `events_count` |

`metadata` JSON columns throughout preserve the *AI-authors / UI-renders*
separation. Enum-like fields are stored as strings; Eloquent casts own the
allowed set.

---

## The three screens

1. **Chat home** — coach conversation with inline action cards.
2. **Loops list** — all the user's intentions (habit loops) at a glance.
3. **Loop detail** — habit anatomy (cue→craving→response→reward) + the strategy
   version timeline.

A shared, mobile-first layout shell (`resources/js/layouts/coach-layout.tsx`)
hosts all three: full-bleed on phones, centered ~md column on desktop, with
sticky header / scroll area / bottom-nav + footer slots.

---

## Build status — Phase 1 (Web App + API / MVP)

> Source of truth is the ClickUp **Phase 1** list. Tasks are numbered in intended
> order; the **Suggested build sequence** below reorders by dependency.

- [x] **1.** Scaffold Laravel + Inertia + React + Vite project
- [x] **2.** Set up authentication (web + API)
- [ ] **3.** Configure SQLite, queues & env/secrets  ← _SQLite is live; queues + env/secrets still to finalize_
- [x] **4.** Tailwind + base layout/UI system
- [x] **5.** Design & migrate core schema
- [x] **6.** Eloquent models, relationships, factories & seeders  ← _models + query scopes, factories & the `HabitDataSeeder` graph_
- [x] **7.** Provider-agnostic `CoachService` interface + first LLM driver  ← _`CoachManager` + Anthropic driver + `FakeCoachService`; smoke-test with `php artisan coach:ping`_
- [ ] **8.** Intention authoring (LLM → structured JSON)
- [ ] **9.** Versioned strategy logic (stack-on-success / restrategize-on-failure)
- [ ] **10.** Rolling-summary pattern detection
- [ ] **11.** Coach prompt templates / system prompts
- [ ] **12.** Chat endpoint (message → coach response + action cards)
- [ ] **13.** Intentions (loops) CRUD endpoints
- [ ] **14.** Action logging endpoint (completion / failure + reason)
- [ ] **15.** Strategy history endpoint
- [ ] **16.** API resources + Sanctum token auth (mobile-ready)
- [ ] **17.** App shell, navigation & 3-screen routing
- [ ] **18.** Chat home screen (messages + inline action cards)
- [ ] **19.** Loops list screen
- [ ] **20.** Loop detail screen (habit anatomy + strategy timeline)
- [ ] **21.** Action card components (render Intention objects)
- [ ] **22.** Wire frontend ↔ coach end-to-end
- [ ] **23.** Validation, error handling, LLM rate limiting & cost guards
- [ ] **24.** Feature & service tests
- [ ] **25.** Deploy Phase 1 to VPS

**Phase 2** (separate list): native Mobile App — only after Phase 1 is fully done.

---

## Suggested build sequence (by dependency)

Engine → API → frontend tends to work better than strict numeric order:

1. **Foundation:** 3 (queues/env) → 6 (models)
2. **Coach engine:** 7 (CoachService) → 11 (prompts) → 8 (intention authoring)
   → 9 (versioned strategy) → 10 (rolling summaries)
3. **API surface:** 13 (intentions CRUD) → 14 (action logging) → 15 (strategy
   history) → 12 (chat endpoint) → 16 (resources + Sanctum)
4. **Frontend:** 17 (shell/routing) → 21 (action cards) → 18/19/20 (the three
   screens) → 22 (wire end-to-end)
5. **Harden & ship:** 23 (validation / rate-limit / cost guards) → 24 (tests)
   → 25 (deploy to VPS)

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
- Tests: `php artisan test` (or `composer test`). The data layer (models,
  relationships, scopes, the `HabitDataSeeder` graph) and the Coach engine
  are covered.
- Smoke-test the live coach driver end-to-end:
  ```bash
  php artisan coach:ping "Say hello in five words or fewer."
  ```

> Note: `public/build/` may be owned by `root` from an earlier sudo build,
> which breaks `npm run build` (EACCES). Fix once with
> `sudo chown -R "$USER" public/build`.
