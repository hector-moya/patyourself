# Lab Notebook — carry-forward into phases 3 & 4

Date: 2026-08-26
Status: notes only. Phases 1 and 2 are complete and merged-ready; phases 3 and 4
each still need their own spec and plan.

This file exists because these facts were discovered *during* implementation and
are not derivable from the code. Read it before specifying phase 3 (Notebook UI)
or phase 4 (MCP expansion).

## Recommended sequencing: phase 4 before phase 3

Phase 1 deleted the auto-coaching closure, which was the only code path that
created a strategy v2+. Phase 2 built `StartExperiment` and `ConcludeExperiment`
but deliberately wired no callers. **So today, starting or concluding an
experiment requires tinker.** Phase 4 is thin and restores that capability from
Claude Desktop immediately; phase 3 is the larger piece of work.

## Decisions the owner still needs to make

1. **`ConcludeExperiment` clears `review_at`.** The approved spec says to clear
   it, and the implementation does (`app/Actions/ConcludeExperiment.php`). The
   final whole-branch review recommended overruling that line, on two grounds:
   - `review_at` is the *only* record of how long an experiment was planned to
     run — `plannedDays()` derives entirely from it, and there is no
     `concluded_at` column to reconstruct from. After conclusion the notebook can
     never say "ran its full 21 days" or "called at day 9 of 21".
   - The clearing is **redundant**: `isUnderReview()` short-circuits on
     `! isConcluded()` first, so setting the verdict alone already takes the
     experiment out of the review state.

   Left as the spec specifies, because it is a data-retention decision and the
   spec was explicitly approved. Nothing calls `ConcludeExperiment` yet, so no
   data is at risk until phase 3 or 4 wires it up — decide before then.

2. **`laravel/ai` is still a composer dependency with no consumer.** Removing it
   is a dependency change and needs approval. If it stays, decide whether to add
   it to `extra.laravel.dont-discover` — its auto-discovered service provider
   still merges a config that reads `ANTHROPIC_API_KEY`.

3. **Operational, at deploy time:** delete `ANTHROPIC_API_KEY` from Forge and
   from the local `.env`. No application code reads it any more, but while it
   sits in the environment the vendor package loads it into
   `config('ai.providers.anthropic.key')` on every boot.

## Phase 4 (MCP expansion) — required, not optional

- **`loop-journal` is the highest-value missing tool.** `log-action-outcome`
  *writes* the user's failure reason but nothing reads it back, so Claude cannot
  reflect on history at all. `LoopProgress::experimentsFor()` (built in phase 2)
  already returns exactly the right shape, including each log's `reason`.

- **Validation has no home any more.** The deleted `ReviseStrategy::revise()`
  was the only thing checking that `intervention_point` is one of
  cue/craving/response/reward and that `approach` is non-empty. `AuthoredStrategy`
  has no guard of its own. A `start-experiment` MCP tool **must** validate both at
  its boundary or malformed strategy versions go straight into the database.

- **The `summaries` table has no writer.** Table, `Summary` model, factory,
  `ProgressController`'s `latestSummary` read and `progress/show.tsx`'s render all
  survive deliberately, but nothing writes to them since `UpdateRollingSummary`
  was deleted. A `write-reflection` tool closes this. It needs fresh tests — the
  coverage deleted with the old writer does not transfer.

- **The server `#[Instructions]` still describe the old coach-driven model.** One
  false clause (failures "drive a revision to a new version") was struck during
  the final review because it ships to the owner's only interface, but the full
  rewrite is phase 4's job. Note `McpEndpointTest` asserts exact, ordered tool
  names *and* that every advertised name appears in the instructions — both move
  together.

- **MCP prompts** (`daily-check-in`, `review-experiment`) would let the Claude
  Desktop project start in one click instead of re-establishing context by hand.

## Phase 3 (Notebook UI) — things to know before designing

- **`StartExperiment::handle()`'s `$revisedAction` parameter is the least
  guessable part of the API**: pass it and the action's cadence is re-proposed;
  omit it and the prior action's cadence is inherited. Now documented in the
  docblock.

- **`experimentsFor()` returns raw counts, never a rounded rate**, by design. At
  these sample sizes a percentage hides its denominator. Deciding when a rate is
  honest to show is rendering's job. Show `8/11`, not `73%`, under n≈20.

- **`planned_days: null` means open-ended** and must never render as a countdown
  or a zero-day experiment. This is the mechanism behind the "the notebook never
  nags" principle.

- **Two ISO-8601 encodings are in play.** `StrategyResource` emits Laravel's
  default (`…T12:00:00.000000Z`) while `experimentsFor()` uses
  `toIso8601String()` (`…T12:00:00+00:00`). Phase 3 will consume both read models
  on the same screen — normalise deliberately rather than by accident.

- `dayOfExperiment()` counts to `now()` with no upper bound, so a superseded or
  concluded version reports an ever-growing day count. Fine while it is only read
  for the *current* experiment; cap it if the UI shows it on historical versions.

## Deferred cleanups (none blocking)

- More dead chat-era CSS remains in `resources/css/patyourself.css`
  (`.py-msg*`, `.py-typing*`, `.py-systemline*`, `.bubble-actions`, `.chat-inner`,
  `.coach-head*`, `.coach-progress*`, `.py-avatar`, `.py-avatar--user`). The final
  fix wave deliberately deleted only the rules it had been asked to.
- `IntentionAuthoringException::validationFailed()` and
  `StrategyTransitionException::invalidRevision()` are never called.
- `tests/Feature/StartExperimentTest.php` has three near-duplicate tests left over
  from the `ReviseStrategy` era, and `CreateLoopToolTest::test_prompts_no_agent`
  lost its only meaningful assertion when the agents were deleted — it now
  re-runs the happy path under a name promising a guarantee it no longer checks.
- The API surface still serves `/api/intentions` while the web surface is
  `/loops`. In-spec, but the two now disagree.
- `ActionLogged` has no listeners. The spec set a decision point here — delete it
  or keep it as a seam.
