# Lab Notebook reframe — Phases 1 & 2

Date: 2026-08-25
Status: approved (design), not yet planned

## Context

PatYourSelf shipped as a coach-first app: the daily-driver screen is an LLM chat,
and the app itself holds an Anthropic API key, four agents, a token budget guard
and a per-user rate limiter.

That reason for existing is gone. The app now runs on Forge at
`patyourself.hector-moya.com` with an MCP server, and the owner talks to it from a
Claude Desktop project over that connector. Claude Desktop is a better chat client
than anything this app will ever ship, and it is already paid for.

So the split changes: **Claude thinks, PatYourSelf remembers.**

The app stops being a coach and becomes a lab notebook — the place where the
evidence lives, where logging takes twenty seconds, and where you read back what
you actually wrote when it went wrong.

### The reframe

The unit of the app becomes **the experiment, not the habit**.

- A **loop** (`Intention`) is the behaviour under change. Permanent.
- A **strategy version** (`Strategy`) is one experiment on that loop: a hypothesis,
  an intervention point, a start date, a planned length, a result, a verdict.

The schema already believes this. `actions.strategy_id` exists, so every
`ActionLog` is already attributable to the experiment that was running when it was
logged. The evidence is in the database today and has never been rendered.

Three states, all first-class:

| State | Meaning |
| --- | --- |
| **Running** | An experiment is in flight — day N of M. |
| **Ready to conclude** | Past its `review_at`, waiting on a verdict. |
| **Between** | No experiment. Logging continues. **Not a failure state.** |

The notebook never nags the user to start an experiment. A loop running
indefinitely with no experiment under test is a success, not neglect. Logging is
never gated on an experiment existing.

## Scope

This spec covers **Phase 1 (Strip)** and **Phase 2 (Experiment model)** only,
plus the `/intentions` → `/loops` route rename.

Deferred to their own specs:

- **Phase 3 — Notebook UI**: the `dashboard` notebook screen, the merged lab
  record at `/loops/{loop}`, the `/log` fast-capture screen, the `/loops` index.
- **Phase 4 — MCP expansion**: `loop-journal`, `start-experiment`,
  `conclude-experiment`, `activate-loop` / `pause-loop`, plus `create-action`,
  `reschedule-action`, `write-reflection`, `update-loop`, rewritten server
  instructions, and MCP prompts.

Phase 2 blocks both 3 and 4. Phases 3 and 4 are independent of each other.

**Recommended sequencing: land Phase 4 before Phase 3.** Phase 1 deletes the only
code path that currently creates a strategy v2+ (the auto-coaching closure), and
Phase 2 is a domain layer with no entry point. Until either 3 or 4 lands, starting
a new experiment requires tinker. Phase 4 is thin and restores the capability from
Claude Desktop immediately; Phase 3 is the larger piece of work.

## Phase 1 — Strip the LLM layer

Goal: the application makes zero LLM calls, holds no `ANTHROPIC_API_KEY`, and has
no token budget, usage metering or coach rate limiting.

### Deleted outright

Application code:

- `app/Http/Controllers/ChatController.php`
- `app/Actions/RespondToChat.php`
- `app/Actions/ReviseStrategy.php`
- `app/Actions/UpdateRollingSummary.php`
- `app/Ai/` — entire tree (`Agents/Coach`, `Agents/IntentionAuthor`,
  `Agents/Strategist`, `Agents/Summarizer`, `Concerns/MetersUsageToUser`,
  `Middleware/GuardCoachUsage`, `Tools/CreateLoop`, `Tools/GetLatestSummary`,
  `Tools/GetLoopDetail`, `Tools/ListLoops`, `TurnCollector`)
- `app/Listeners/RunCoachingClosure.php`
- `app/Models/CoachUsage.php`
- `app/Console/Commands/CoachPing.php`
- `app/Services/Coach/Usage/CoachUsageGuard.php`
- `app/Services/Coach/Chat/ChatResult.php`
- `app/Services/Coach/Authoring/IntentionSchema.php` (the agents' JSON schema)
- `app/Notifications/StrategyRevisedNotification.php`
- `config/ai.php`

Frontend:

- `resources/js/pages/coach.tsx`
- `resources/js/patyourself/chat/` — entire tree
- `resources/js/patyourself/progress/coach-usage-card.tsx` + its test

Frontend edits that follow from those deletions:

- `resources/js/pages/progress/index.tsx` + `index.test.tsx`: drop the `usage`
  prop and the usage card it renders.
- `resources/js/patyourself/types.ts`: drop the coach-usage types.

Database:

- Migration dropping the `coach_usages` table.

Tests (deleted with the code they cover):

- `tests/Feature/ChatEndpointTest.php`
- `tests/Feature/Coach/CoachHardeningTest.php`
- `tests/Feature/Coach/CoachUsageGuardTest.php`
- `tests/Feature/Coach/RunCoachingClosureTest.php`
- `tests/Feature/Ai/GuardCoachUsageTest.php`
- `tests/Feature/Ai/TurnCollectorTest.php`
- `tests/Unit/Ai/MetersUsageToUserTest.php`
- `tests/Feature/Progress/ProgressUsageTest.php`

- `tests/Feature/Ai/CreateLoopTest.php` — covers the *agent* tool
  `App\Ai\Tools\CreateLoop`, not the MCP tool. Deleted with the agent.
- `tests/Feature/Ai/CoachConversationTest.php`, `ReadToolsTest.php`,
  `SdkInstallTest.php`, `StrategistTest.php`, `SummarizerTest.php`
- `tests/Feature/Coach/AttributesCoachingUsageTest.php`
- `tests/Feature/PromptVersioningTest.php` — asserts that agent-authored
  artifacts record the prompt version that produced them. No agents, no prompt
  versions.

Two test files are **retained**, and confusing them with the above is the easiest
way to break this work:

- `tests/Feature/Mcp/CreateLoopToolTest.php` covers the MCP `create-loop` tool.
  It is the regression net for the `AuthorIntention` split and must stay green
  throughout.
- `tests/Feature/Coach/OutcomeStreakTest.php` covers `OutcomeStreak`, which
  survives. It moves to `tests/Feature/Strategy/` with the class.

### Survives, with changes — the subtle part

These are the places where a naive delete breaks working behaviour.

**1. `AuthorIntention` — split, do not delete.**

`app/Mcp/Tools/CreateLoopTool.php` depends on `AuthorIntention` and on the
`AuthoredIntention` / `AuthoredStrategy` / `AuthoredAction` DTOs. It passes a
fully-authored payload precisely so that `AuthorIntention` never reaches its LLM
branch. Deleting the class removes the only working Claude write path for creating
a loop.

Action: keep the persistence half — the transactional writer that turns an
`AuthoredIntention` DTO into an `Intention` + its first `Strategy` + its `Action`
rows — and delete only the LLM-authoring branch. Rename the surviving class to
`App\Actions\PersistAuthoredIntention` to make the remaining responsibility
obvious. `CreateLoopTool` is updated to call it.

**2. The `Authored*` DTOs survive.** They are the MCP tool's input contract, not
agent scaffolding. They move from `App\Services\Coach\Authoring\` to
`App\Support\Authoring\` since there is no longer a coach.

**3. Exceptions: rename, do not delete.** `CreateLoopTool` catches
`App\Services\Coach\Exceptions\CoachException`. `CoachQuotaException` dies with the
usage guard, but the base exception survives as
`App\Support\Authoring\AuthoringException`, and `IntentionAuthoringException`
survives alongside it. Update the `CreateLoopTool` catch.

**4. `BehavioralChain` and `StrategyTransitionException` survive.** The
supersede-v1-and-create-v2 transition machinery is deterministic, has no LLM
dependency, and is exactly what Phase 2's `StartExperiment` needs. They move to
`App\Domain\Strategy\`.

**5. `OutcomeStreak` survives.** Deterministic, no LLM. Under the old model it was
a *trigger* that fired automatic revision. Under the new model it becomes a
*passive observation* the notebook can display ("3 misses in a row under v2") with
no automatic action attached. It moves to `App\Domain\Strategy\`.

**6. `summaries` table and the `Summary` model survive** the Summarizer's death.
Claude writes reflections into them over MCP in Phase 4. `UpdateRollingSummary`
(the LLM writer) is deleted; the table and model are not.

**7. `ActionLogged` event survives, its listener does not.** `LogAction` continues
to dispatch it. `RunCoachingClosure` is deleted and unregistered. The event stays
as a seam for future non-LLM listeners; if nothing registers against it by the end
of Phase 2, delete it then rather than pre-emptively.

### Configuration and wiring changes

- `app/Providers/AppServiceProvider.php`: remove the `TurnCollector` binding, the
  `CoachUsageGuard` binding, and the `coach` rate limiter definition.
- `bootstrap/app.php`: remove the `CoachQuotaException` / coach-failure exception
  renderers (the 429 "daily coaching limit" and 503 "coach is unavailable"
  responses).
- `config/services.php`: remove the entire `coach` block (`daily_token_budget`,
  `rate_per_minute`, `fail_streak`, `stack_streak`).
- `.env.example`: remove `ANTHROPIC_API_KEY` and any coach budget keys.
- `routes/web.php`: remove the `chat` POST route and the `throttle:coach`
  middleware. The `dashboard` route name must survive — Fortify's post-login
  redirect (`config/fortify.php` → `home`) targets it. For Phase 1 it points at
  `IntentionController@index` so the app stays navigable; Phase 3 repoints it at
  the notebook controller. Both `dashboard` and `loops.index` therefore resolve to
  the same screen during Phases 1–2, which is acceptable and temporary.
- `app/Http/Controllers/ProgressController.php`: drop the `CoachUsageGuard`
  dependency and the `usage` prop from `index`. The whole controller is folded
  into the lab record in Phase 3; here it only loses the usage snapshot.
- `resources/js/patyourself/nav-tabs.ts` and `app-rail`: remove the Coach tab.
- `resources/js/resolve-page-layout.ts`: drop the `coach` page.

### `tests/TestCase.php`

`setUp()` currently calls `Http::preventStrayRequests()` and blanket-fakes all four
agents, because logging an outcome synchronously triggered the queued coaching
closure and would otherwise bill a real API key.

The agent fakes are deleted along with the agents. **`Http::preventStrayRequests()`
stays** — it is a general hermeticity guarantee, not agent-specific, and removing
it would let a future stray call escape in CI.

### Observable behaviour changes

State these plainly; they are intentional losses, not regressions:

- No in-app coaching conversation.
- **No automatic strategy revision on a failure streak.** Revision becomes a
  deliberate act by the user, with Claude. This is the point of the reframe, but
  it means a loop can now sit on a failing strategy indefinitely until someone
  concludes the experiment.
- No `StrategyRevisedNotification`.
- No auto-generated rolling summaries. The Progress "Coach summary" section will
  be empty for new activity until Phase 4 lets Claude write reflections back.
- No coach usage card, no token budget, no coach rate limit.

Unchanged: reminders, `actions:fire`, the inbox, notification settings, auth,
two-factor, and the MCP server itself.

## The `/intentions` → `/loops` rename

The model stays `Intention`. Only the URL surface and the route names change; the
UI, the MCP server and the owner all say "loops" already.

- `routes/web.php`: `Route::resource('intentions', …)` becomes
  `Route::resource('loops', …)` with parameter binding to `Intention`
  (`->parameters(['loops' => 'intention'])`), so controller signatures are
  untouched. `progress/{intention}` follows in Phase 3 when Progress is folded
  into the lab record; leave it alone here.
- Frontend uses hardcoded path strings today (Wayfinder output is generated at
  build, not committed). Update every literal: `nav-tabs.ts`, `app-rail`,
  `inbox.tsx`, `intentions/index.tsx`, `intentions/show.tsx`, and the
  corresponding tests.
- Move `resources/js/pages/intentions/` to `resources/js/pages/loops/` and update
  `resolve-page-layout.ts` and its test.
- `IntentionController` keeps its name (it controls the `Intention` model).

Route names change from `intentions.*` to `loops.*`. Grep for `route('intentions`
and for `/intentions` before finishing.

## Phase 2 — The experiment model

Goal: a strategy version can be started as an experiment with a planned length,
and concluded with a verdict; results are computable per version. Domain layer and
tests only — no UI, no MCP surface.

### Migration

Three nullable columns on `strategies`:

| Column | Type | Meaning |
| --- | --- | --- |
| `review_at` | `datetime`, nullable | Planned end of the experiment. Drives "day N of M" and the ready-to-conclude state. **Null means open-ended** — no countdown, no pressure. |
| `verdict` | `varchar`, nullable | `worked` / `failed` / `inconclusive`. Null means not yet concluded. |
| `verdict_note` | `text`, nullable | The user's words on why it got that verdict. |

`verdict_note` is a separate column rather than reusing `superseded_reason`
because a strategy concluded as `worked` is never superseded — its note would
otherwise live permanently in a column whose name denies it. `superseded_reason`
keeps its existing meaning: why this version was *replaced*, written by
`StartExperiment` onto the outgoing version.

No new column for the experiment's start date: `strategies.created_at` already
marks when the version became active, because versions are created and activated
in the same transaction.

`strategies.status` is unchanged (`active` / `superseded`). "Concluded" is derived
from `verdict !== null`, not a fourth status — a concluded experiment whose
strategy is still in force must stay `active`.

### `Strategy` model

- Constants `VERDICT_WORKED`, `VERDICT_FAILED`, `VERDICT_INCONCLUSIVE`.
- Casts for `review_at`.
- `isConcluded(): bool` — `verdict !== null`.
- `isUnderReview(): bool` — running, has a `review_at`, and it has passed.
- `dayOfExperiment(): int` and `plannedDays(): ?int`, both derived from
  `created_at` / `review_at`. Null-safe for open-ended experiments.

### Actions

**`App\Actions\StartExperiment`**

Starts a new experiment on a loop. Signature takes the loop, a hypothesis
(persisted to `rationale`), an approach, an intervention point, an optional
`review_after_days`, and the reason the previous version is being replaced.

Behaviour, in one transaction:

1. Supersede the current active version via the surviving `BehavioralChain` /
   `StrategyTransitionException` machinery, writing `superseded_reason`.
2. Create the next version (`version` + 1, `status = active`,
   `parent_strategy_id`, `change_reason`).
3. Set `review_at` from `review_after_days` when supplied; leave null otherwise.

Guards: rejects a second concurrent supersede via the existing
`StrategyTransitionException`; refuses to start an experiment on a loop with no
active strategy.

**`App\Actions\ConcludeExperiment`**

Sets `verdict` and `verdict_note` on a version and clears `review_at`. Does **not**
supersede it and does **not** change `status` — a strategy concluded as `worked`
keeps running. Rejects concluding an already-concluded version.

### `LoopProgress` extension

`App\Services\Progress\LoopProgress` currently aggregates over the whole loop. Add
a per-version breakdown that Phases 3 and 4 both consume:

- For each `Strategy` of a loop, in version order: the version number, its
  hypothesis, intervention point, start and end dates, its `ActionLog` outcomes in
  chronological order, the completed/failed/skipped totals, and the verdict.
- Logs attribute to a version through `actions.strategy_id`. Guard the case of an
  action whose strategy was deleted.
- Return raw counts (`8/11`), not a rounded percentage. **Rendering decides when a
  rate is honest to show; the service does not pre-round away the denominator.**

Keep the existing whole-loop aggregate — the loops index still needs it.

### Resources

`StrategyResource` gains `review_at`, `verdict`, `verdict_note`, the derived day
counts, and the per-version outcome series. `IntentionResource` is untouched here.

## Testing

Every item below is a required test, per the project's test-enforcement rule.

Phase 1:

- The `POST /chat` route no longer resolves.
- No page or provider references a deleted class (boot the app and hit each
  surviving route).
- `create-loop` over MCP still creates a loop, its first strategy and its actions,
  after the `AuthorIntention` split — the existing `CreateLoopTest`, moved and
  updated, covers this and must pass unchanged in behaviour.
- `McpEndpointTest` still passes: tool names and instructions are untouched in
  Phase 1.
- Logging an outcome no longer dispatches a coaching job.
- Every renamed route resolves at `/loops`, and `/intentions` no longer does.

Phase 2:

- `StartExperiment` supersedes the old version, creates the next with the right
  `version`, `parent_strategy_id` and `change_reason`, and sets `review_at` only
  when `review_after_days` is given.
- `StartExperiment` throws on a concurrent supersede and on a loop with no active
  strategy.
- `ConcludeExperiment` sets verdict and note, clears `review_at`, and leaves
  `status` as `active`.
- `ConcludeExperiment` rejects an already-concluded version.
- `isUnderReview()` is false for an open-ended experiment, false before
  `review_at`, true after.
- `LoopProgress` attributes each log to the version that was active when it was
  logged, across a v1 → v2 boundary.
- `LoopProgress` returns counts, not rounded rates.

Run with `php artisan test --compact` filtered to the touched files; run the full
suite once at the end of each phase.

## Out of scope

- Any Notebook UI work (Phase 3).
- Any new MCP tool, instruction rewrite, or MCP prompt (Phase 4).
- Renaming the `Intention` model or the `intentions` table.
- Renaming the `progress/{intention}` route (Phase 3 folds Progress into the lab
  record).
- Backfilling `verdict` or `review_at` for existing strategy rows — they stay null,
  which correctly reads as "open-ended, never concluded".

## Assumptions

- Single-user in practice; no migration/compatibility burden for other accounts.
- Losing automatic strategy revision is acceptable in the gap between Phase 1 and
  Phase 4, given the recommended sequencing.
- `ANTHROPIC_API_KEY` can be removed from Forge once Phase 1 deploys. Nothing else
  in the app reads it.
