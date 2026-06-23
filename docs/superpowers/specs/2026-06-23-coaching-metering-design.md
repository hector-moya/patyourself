# SP6 — Meter the Background Coaching Path

**Date:** 2026-06-23
**Status:** Approved — ready for implementation plan
**Depends on:** SP4 (auto-coaching closure), SP5 (progress dashboard), the existing
cost-guard stack (`CoachUsage`, `CoachUsageGuard`, `GuardCoachUsage`).

## Problem

SP4 introduced a queued, after-commit listener (`RunCoachingClosure`) that runs the
LLM-bearing coaching pass off the request: it refolds the loop's rolling summary
(`Summarizer`) and, on a deterministic outcome streak, revises the active strategy
(`Strategist`). That pass runs **unmetered**.

`GuardCoachUsage` resolves the billed user from `auth()->guard()->user()` first, then
falls back to the agent's `conversationParticipant()`. On the queued, session-less path
`auth()` is null, and `Strategist`/`Summarizer` expose no `conversationParticipant()`
(only `Coach` does, via `RemembersConversations`). So the middleware hits its
"no user — pass straight through unmetered" branch: the per-user rolling-24h token
budget is **never enforced for auto-coaching**, and the spend is **never recorded** in
`coach_usages`. SP4's own spec flagged this as the known follow-up.

Impact: a loop that keeps qualifying for revision can drive unbounded background LLM
spend for its owner, off-session, with no cap and no ledger trail. The budget the
interactive `Coach` path respects does not exist for auto-coaching.

## Goal

Attribute the queued `Strategist`/`Summarizer` calls to the loop owner so the existing
`GuardCoachUsage` middleware meters and caps them, skip the pass gracefully when the
owner is over budget, and surface today's per-user usage on the progress dashboard.

## Locked decisions

1. **Attribution: agent-level `forUser()`.** Give `Strategist`/`Summarizer` an explicit
   `forUser(User)` setter plus `conversationParticipant()`; the services pass the loop
   owner when prompting. No global auth mutation on the queue worker. `auth()` still
   takes precedence in the middleware, so interactive paths are unchanged.
2. **Over budget: silent skip.** Catch `CoachQuotaException`, log it, no-op the pass.
   No inbox notification. The user learns they are near/over budget proactively from the
   usage card, not reactively per skip. Self-heals on the next qualifying log as the
   rolling-24h window frees.
3. **Usage surface: card on `/progress` index.** The budget is per-user (the
   `coach_usages` ledger is keyed by `user_id`; `daily_token_budget` is account-level),
   so the readout is account-level and lives at the top of the existing SP5 dashboard.

## Architecture

The cost-guard stack is unchanged in shape — SP6 only makes the queued path *resolve a
user* so the existing guard fires, plus a read method for the card. Five touch points
plus one new trait, one new component.

### 1. `MetersUsageToUser` trait (new)

`app/Ai/Concerns/MetersUsageToUser.php`. Provides:

- `forUser(User $user): static` — stores the user on a protected property, returns
  `$this` for chaining.
- `conversationParticipant(): ?User` — returns the stored user (null by default).

`GuardCoachUsage` already resolves the billed user with
`method_exists($prompt->agent, 'conversationParticipant')` → so no interface is needed
and no middleware change is required for resolution. The trait is the smallest surface
that lets `Strategist`/`Summarizer` carry an owner without pulling in the
conversation-memory machinery of `RemembersConversations`.

Applied to `Strategist` and `Summarizer`. The `$prompt->agent` instance the middleware
inspects is the same instance `forUser()` was called on, so the participant resolves
correctly.

### 2. `Strategist` / `Summarizer` agents

`use MetersUsageToUser;` on each. No other change — the `GuardCoachUsage` middleware they
already declare does the metering once a user resolves.

### 3. `ReviseStrategy` / `UpdateRollingSummary` services

Thread the owner into the prompt at the call site:

- `UpdateRollingSummary`: `(new Summarizer)->forUser($intention->user)->prompt(...)`.
- `ReviseStrategy`: `(new Strategist)->forUser($current->intention->user)->prompt(...)`
  (the owner is reachable from the strategy being revised).

The owner is already in hand at both call sites, so no method signatures change. On
interactive paths where `auth()` is set, the middleware prefers `auth()->user()`, so
`forUser()` is inert there — no double-attribution, no behavior change.

### 4. `RunCoachingClosure` listener

Today the `CoachQuotaException` catch wraps only `reviseFor()` (the `Strategist` call).
The `updateSummary->handle()` (the `Summarizer` call) sits **outside** the catch. That is
harmless while the summary call is unmetered, but once it is attributed an over-budget
owner makes it throw `CoachQuotaException`, which would bubble out of the queued job and
retry three times (`tries = 3`) against a budget that will not free up in 10/30/60s.

SP6 extends the skip-on-quota to the **whole pass**: the summary call and the revision
both run under one `CoachQuotaException` catch, so an over-budget owner skips the entire
pass gracefully — the job succeeds, `Log::info` records the skip, no summary or revision
is written. The streak persists, so the next qualifying log retries when budget frees.
The existing per-loop cache lock and the `StrategyTransitionException` skip are preserved;
the catch stays inside the lock callback so the lock still releases normally.

### 5. `CoachUsageGuard` + container binding

Add a read method:

```php
/**
 * @return array{used:int, budget:int, remaining:?int, breakdown:array<string,int>}
 */
public function snapshotFor(User $user): array
```

- `used` — `tokensUsedToday($user)`.
- `budget` — the configured daily budget (`0` or less means no cap).
- `remaining` — `max(0, budget - used)` when capped, `null` when uncapped.
- `breakdown` — today's `total_tokens` summed and grouped by `purpose`
  (`coach` / `strategist` / `summarizer` / authoring), for the card's auto-coaching-vs-chat line.

`GuardCoachUsage` currently constructs `new CoachUsageGuard((int) config(...))` inline.
The `ProgressController` would duplicate that construction, so bind `CoachUsageGuard` in
the container (singleton, config budget) in `AppServiceProvider` and have the middleware
resolve it from the container instead of `new`. One construction, two consumers. This is
a targeted DRY refactor justified by the new second consumer; the guard's behavior is
unchanged.

### 6. `ProgressController@index` + usage card

`index` adds a `usage` prop from `$guard->snapshotFor($request->user())` (guard injected).
`show` is untouched.

`resources/js/pages/progress/index.tsx` renders a new `CoachUsageCard` at the top:

- Used today / daily budget / remaining, with a small progress bar.
- Per-purpose breakdown line: auto-coaching (`strategist` + `summarizer`) vs chat
  (`coach`) vs any other purpose present.
- States: uncapped (`budget <= 0` → "No cap", no remaining/bar), over budget
  (`remaining === 0` → bar full / muted treatment).

The card is account-level (one per user), distinct from the per-loop metric cards below it.

## Data flow

```
Action logged (committed)
  → ActionLogged event
    → queued RunCoachingClosure (afterCommit)
      → per-loop cache lock
        → UpdateRollingSummary: (new Summarizer)->forUser(owner)->prompt()
        → ReviseStrategy:       (new Strategist)->forUser(owner)->prompt()
          → GuardCoachUsage: auth() null → conversationParticipant() → owner
            → ensureWithinBudget(owner)   [throws CoachQuotaException if over → caught → skip pass]
            → record(owner, model, tokens, purpose)  → coach_usages row

/progress index
  → CoachUsageGuard::snapshotFor(user)  → { used, budget, remaining, breakdown }
    → CoachUsageCard
```

## Testing strategy (TDD)

Backend (PHPUnit, DB-backed → `tests/Feature`; pure no-DB → `tests/Unit`):

- **Metered closure (Feature):** with the LLM faked, a qualifying log writes
  `coach_usages` rows for the loop owner tagged `summarizer` and `strategist`.
- **Over-budget skip (Feature):** an owner already over budget → the pass is skipped,
  no exception bubbles, the job does not fail, no summary or revision is written, the
  skip is logged. Asserts the **new** summary-call coverage, not just the revision.
- **`snapshotFor` (Feature):** correct `used` / `budget` / `remaining` / `breakdown`;
  uncapped case returns `remaining === null`; empty ledger returns zeros.
- **Trait round-trip (Unit):** `forUser()` then `conversationParticipant()` returns the
  user; default is `null`; `forUser()` returns `$this`.
- **Controller prop (Feature):** `progress` index includes the `usage` prop for the
  authenticated user.

Frontend (vitest):

- `CoachUsageCard` renders used / budget / remaining and the breakdown; covers the
  uncapped ("No cap") and over-budget (remaining 0) states.

Quality gates: `vendor/bin/pint --dirty` clean; touched JS/TS prettier-formatted and
eslint-clean; no new tsc errors in touched files; full PHP suite green after
`npm run build`.

## Scope boundary — NOT in SP6

- **No per-loop budgets** — the budget stays per-user/account-level.
- **No historical usage charts or trends** — the card shows today's rolling-24h snapshot
  only; no time series.
- **No budget-editing UI** — `daily_token_budget` stays config-only.
- **No inbox notice on skip** — over-budget skips are silent (Decision 2).
- **No new cue channels, no propose/approve flow, no threshold-tuning UI, no retroactive
  backfill** — those remain separate deferred slices.
- **No change to LLM behavior, prompts, versioning, or streak logic** — SP6 only
  attributes the owner, enforces the existing budget, and surfaces usage.

## Success criteria

1. A qualifying log's auto-coaching pass records the owner's token spend in
   `coach_usages` (one row per LLM call, tagged with the agent purpose).
2. An owner over the rolling-24h budget has the auto-coaching pass skipped without
   failing the job, without bubbling an exception, and without writing a partial
   summary/revision; the skip is logged.
3. Interactive coach metering is unchanged (`auth()` still wins).
4. `/progress` shows the owner today's used / budget / remaining and a per-purpose
   breakdown, handling uncapped and over-budget states.
5. All new and affected tests pass (PHPUnit + vitest); `vendor/bin/pint` clean;
   types/lint clean.
