# SP5 — Progress Dashboard (design)

**Date:** 2026-06-22
**Status:** Approved, ready for implementation planning
**Program slice:** SP5 of the "close the habit loop" decomposition (see SP1 spec,
`docs/superpowers/specs/2026-06-13-action-authoring-design.md`; SP2 spec,
`docs/superpowers/specs/2026-06-14-trigger-engine-design.md`; SP3 spec,
`docs/superpowers/specs/2026-06-15-cue-delivery-design.md`; SP4 spec,
`docs/superpowers/specs/2026-06-19-auto-coaching-closure-design.md`)

---

## App intent (one paragraph)

PatYourSelf is a conversational habit-change coach. It models every habit as an
_Atomic Habits_ loop — cue → craving → response → reward. SP1 authored a concrete,
scheduled `Action` per Strategy. SP2 fires a due action and rolls recurring actions
forward. SP3 delivers the fire as a persistent in-app cue and clears it when the
user logs the outcome. SP4 closed the loop: logging an outcome now runs an
after-commit queued coaching pass that refolds the rolling `Summary` and, on a
deterministic streak, auto-revises the `Strategy` (a new version that supersedes the
old, recording _why_) and drops an inbox cue. After four slices the data is rich —
versioned strategy journeys, outcome logs, rolling narrative summaries — but the
user has nowhere to **see** it. The only authenticated home is the coach chat; the
loops list shows structure, not momentum. SP5 adds the **"how am I doing" view**: a
read-only progress dashboard that aggregates the existing data into per-loop
momentum (streak, completion rate, recent activity), the strategy **journey**
(how the plan adapted), and the coach's **narrative** summary.

## The problem this slice solves

Every signal a user would want to judge their own progress already exists in the
database, but nothing surfaces it. `OutcomeStreak` computes a streak only for the
coach's internal revision decision. The versioned `Strategy` history — the literal
record of _the plan changed because you kept missing it_ — is rendered on the loop
detail screen but framed as anatomy, not as a progress story. The rolling `Summary`
the coach maintains is **never shown to the user at all**. A user logging outcomes
day after day has no glance-able answer to "am I improving?".

SP5 builds that answer. It is **pure read-side**: one small aggregation service over
existing models, two controller actions, two Inertia pages, and a reused timeline
component. No new domain logic, no writes, no LLM calls, no schema change.

---

## Decisions (locked in brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Placement | **New `/progress` route + 4th bottom-nav tab.** A dedicated destination (Coach / Loops / **Progress** / Inbox) in the existing `CoachLayout` shell. The coach chat stays the home (`/dashboard`); the daily-driver is untouched. |
| 2 | Metrics surfaced | **Per-loop streak + completion rate + recent-activity sparkline; strategy journey timeline; rolling-summary narrative.** No account-level rollup header (explicitly dropped — loops are independent journeys). |
| 3 | Layout | **Index + drill-in.** `/progress` is a stack of compact per-loop cards (metrics + a one-line narrative snippet). `/progress/{loop}` is the detail: full metrics, the journey timeline, and the full narrative. |
| 4 | Loop scope on index | **Active only.** The index lists `status = active` loops (matching the loops list + coach home, which both use the `active()` scope). The detail route serves any owned loop (owner-gated), but is only linked from active cards. |
| 5 | Data loading | **Direct load.** The controller computes the aggregates and passes them as normal Inertia props; first paint is full content. Data volume is tiny (one user, a handful of loops, a few hundred logs) so deferred props buy nothing and add skeleton complexity. |
| 6 | Journey timeline | **Reuse the existing component.** The versioned strategy history is already rendered on `intentions/show.tsx` as `StrategyTimeline`. SP5 **extracts** that component into a shared module and imports it on both screens rather than duplicating it. |
| 7 | Orphaned placeholder | **Delete `resources/js/pages/dashboard.tsx`.** It is a Laravel starter placeholder (`PlaceholderPattern` boxes) wired to no route — nothing server-side renders `'dashboard'` (the `dashboard` *route* renders `'coach'`). SP5 removes it. The unused `app-layout`/AppSidebar stack is **left alone** (unrelated cleanup, out of scope). |

### Why a new route over restructuring the home

The coach chat is the product's core interaction and its post-login landing
(`config/fortify.php → home → dashboard`). Demoting it to surface a glance-view would
trade the daily-driver for a screen the user visits occasionally. A dedicated
`/progress` destination mirrors how `intentions` already works (its own tab, its own
index + detail) and keeps each screen single-purpose. Adding a 4th `BottomNav` tab is
a one-line change to an already-extensible array.

### Why streak and completion rate use different scopes

The **streak** is active-strategy-scoped because that is what `OutcomeStreak` already
computes and what "current run" means — a revision supersedes the strategy, archives
its actions, and starts the new active strategy at streak 0, so the streak naturally
reads "since the current plan". The **completion rate** is loop-lifetime (across every
version's logs) because a per-strategy rate would reset to "no data" after every
revision — useless for "am I improving overall". Both exclude `skipped` (neutral),
consistent with `OutcomeStreak`'s own skip handling.

---

## Design

### 1. Components

| Unit | Type | Responsibility | Depends on |
|---|---|---|---|
| `routes/web.php` | routes (modify) | `GET /progress` → `index`, `GET /progress/{intention}` → `show`, inside the existing `auth`+`verified` group. Named `progress` / `progress.show`. | `ProgressController` |
| `App\Http\Controllers\ProgressController` | controller (new) | `index`: active loops → per-loop card payload via `LoopProgress`. `show`: owner-gated single loop → full metrics + journey (`StrategyResource`) + narrative. | `LoopProgress`, `StrategyResource`, `IntentionPolicy` |
| `App\Services\Progress\LoopProgress` | service (new) | Pure read. Computes one loop's metric block: streak (delegates to `OutcomeStreak`), lifetime completion rate, lifetime totals, the last-10 recent-activity strip, last-logged-at. | `OutcomeStreak`, `Intention`, `ActionLog` |
| `App\Services\Coach\OutcomeStreak` | service (reuse, unchanged) | `forStrategy()` — leading non-skip run on the active strategy. SP5 reads `[outcome, length]`. | — |
| `App\Http\Resources\StrategyResource` | resource (reuse, unchanged) | One version in the journey (version, status, intervention_point, approach, rationale, change_reason, superseded_reason). Already used by `IntentionController@show`. | — |
| `resources/js/patyourself/strategy-timeline.tsx` | component (new, **extracted**) | The journey timeline (`StrategyTimeline` + `TimelineNode` + `CHANGE_REASON` + `SectionHeading`), lifted verbatim from `intentions/show.tsx` and imported by both screens. | `StrategyData` |
| `resources/js/pages/progress/index.tsx` | page (new) | Stack of `ProgressCard`s (each links to detail), or the no-loops empty state. In `CoachLayout` + `BottomNav`. | `LoopProgressCard` |
| `resources/js/pages/progress/show.tsx` | page (new) | Detail: metric header, reused `StrategyTimeline`, narrative (or its empty state). In `CoachLayout`, back-link to `/progress`. | `LoopProgressDetail`, `StrategyData`, shared timeline |
| `resources/js/patyourself/progress/*` | components (new) | `progress-card.tsx` (index row), `outcome-strip.tsx` (the sparkline), `streak-badge.tsx` (streak pill). | shared types |
| `resources/js/patyourself/bottom-nav.tsx` | component (modify) | Add the **Progress** tab (lucide `trending-up`) between Loops and Inbox. | — |
| `resources/js/patyourself/types.ts` | types (modify) | New `OutcomeMark`, `LoopStreak`, `LoopProgressCard`, `LoopProgressDetail`. | — |
| `resources/js/pages/intentions/show.tsx` | page (modify) | Import `StrategyTimeline` from the shared module instead of its local copy (delete the inlined definitions). Behaviour unchanged. | shared timeline |
| `resources/js/pages/dashboard.tsx` | page (delete) | Orphaned starter placeholder. | — |

No model, migration, or schema change. SP5 reads SP1's `intentions` / `strategies` /
`actions` / `action_logs` and SP4's `summaries`.

### 2. The read service

`LoopProgress` produces one loop's metric block. It expects the loop to arrive with
`activeStrategy`, `latestSummary`, and `actionLogs` eager-loaded (the controller does
this once per request to avoid N+1), and reads from those in memory — except the
streak, which delegates to `OutcomeStreak::forStrategy` (its own scoped query) so the
streak definition stays single-sourced with SP4.

```php
namespace App\Services\Progress;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Services\Coach\OutcomeStreak;

/**
 * Read-side aggregation for one loop's progress card. Pure: no writes, no model
 * calls. Streak delegates to OutcomeStreak (active-strategy run); rate and totals
 * span the loop's whole lifetime so they survive strategy revisions. `skipped`
 * outcomes are neutral (excluded from the rate, kept in the recent strip).
 */
final class LoopProgress
{
    public function __construct(private OutcomeStreak $streak) {}

    /**
     * @return array{
     *   streak: array{outcome: ?string, length: int},
     *   completion_rate: ?int,
     *   totals: array{completed: int, failed: int, skipped: int},
     *   recent: list<string>,
     *   last_logged_at: ?string,
     * }
     */
    public function forLoop(Intention $loop): array
    {
        $logs = $loop->actionLogs; // eager-loaded HasManyThrough

        $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();
        $failed = $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count();
        $skipped = $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count();

        $decided = $completed + $failed;
        $rate = $decided === 0 ? null : (int) round($completed / $decided * 100);

        [$outcome, $length] = $loop->activeStrategy === null
            ? [null, 0]
            : $this->streak->forStrategy($loop->activeStrategy);

        // The newest 10 logs, oldest → newest so the strip reads left-to-right.
        $recent = $logs
            ->sortByDesc('logged_at')
            ->take(10)
            ->reverse()
            ->pluck('outcome')
            ->values()
            ->all();

        $lastLoggedAt = $logs->max('logged_at');

        return [
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $rate,
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            'recent' => $recent,
            'last_logged_at' => $lastLoggedAt?->toIso8601String(),
        ];
    }
}
```

`OutcomeStreak::forStrategy` returns a 3-tuple `[outcome, runLength, latestFailureReason]`;
SP5 destructures the first two and ignores the reason (a coach-revision concern, not a
progress one).

### 3. The controller

```php
namespace App\Http\Controllers;

use App\Http\Resources\StrategyResource;
use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProgressController extends Controller
{
    public function index(Request $request, LoopProgress $progress): Response
    {
        $loops = $request->user()->intentions()
            ->active()
            ->with(['activeStrategy', 'latestSummary', 'actionLogs'])
            ->latest()
            ->get()
            ->map(fn (Intention $loop): array => [
                'id' => $loop->id,
                'title' => $loop->title,
                'type' => $loop->type,
                ...$progress->forLoop($loop),
                'summary_excerpt' => $this->excerpt($loop->latestSummary?->content),
            ])
            ->values();

        return Inertia::render('progress/index', ['loops' => $loops]);
    }

    public function show(Intention $intention, LoopProgress $progress): Response
    {
        Gate::authorize('view', $intention);

        $intention->load(['activeStrategy', 'latestSummary', 'actionLogs']);
        $strategies = $intention->strategies()->orderedByVersion()->get();

        return Inertia::render('progress/show', [
            'intention' => [
                'id' => $intention->id,
                'title' => $intention->title,
                'type' => $intention->type,
                ...$progress->forLoop($intention),
            ],
            'strategies' => StrategyResource::collection($strategies)->resolve(),
            'summary' => $intention->latestSummary?->content,
        ]);
    }

    /** First line of the rolling summary, trimmed for the index card. */
    private function excerpt(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return Str::limit(trim(strtok($content, "\n")), 120);
    }
}
```

`show` mirrors `IntentionController@show` exactly: implicit route-model binding on
`{intention}` + `Gate::authorize('view', $intention)` against the existing
`IntentionPolicy`, so a user can never view another user's loop (403). The detail
serves any owned loop regardless of status (only the index filters to active).

### 4. Metric definitions (deterministic)

| Metric | Definition | Empty value |
|---|---|---|
| **streak** | `OutcomeStreak::forStrategy(activeStrategy)` → `{outcome, length}`. `outcome` is `completed` (a win run, render ▲), `failed` (a miss run, render as caution), or `null`. | `{null, 0}` when no active strategy or no logs |
| **completion_rate** | `round(completed / (completed + failed) * 100)` over **lifetime** logs; `skipped` excluded from numerator and denominator | `null` when `completed + failed == 0` |
| **totals** | lifetime counts `{completed, failed, skipped}` | all `0` |
| **recent** | the newest 10 logs, ordered **oldest → newest**, each `completed \| failed \| skipped` | `[]` |
| **last_logged_at** | ISO-8601 of the most recent log's `logged_at` | `null` |
| **summary_excerpt** (index) | first line of `latestSummary.content`, `Str::limit(…, 120)` | `null` |
| **summary** (detail) | full `latestSummary.content` | `null` |

Worked examples (lifetime logs, newest-first): `completed, completed, failed` →
rate `67%`, totals `{2,1,0}`, recent `[failed, completed, completed]` reversed to
oldest-left. `skipped, completed, completed` → rate `100%` (skip excluded), totals
`{2,0,1}`. No logs → rate `null`, totals `{0,0,0}`, streak `{null,0}`.

### 5. Frontend

**Index (`progress/index.tsx`)** — `CoachLayout` (title "Progress", `bottomNav`),
a vertical list of `ProgressCard`s, each an Inertia `<Link href={`/progress/${id}`}>`:

```
┌─ Morning run ──────────────────┐
│ ▲ 5-day streak          82%    │
│ ●●●×●●–●●●                      │   ← outcome-strip
│ "You complete most mornings…"  │   ← summary_excerpt (muted, 1 line)
└────────────────────────────────┘
```

A loop with no logs renders the card with "No activity yet" in place of the strip,
streak/rate as "—", and still links to its detail. No active loops at all → an empty
state with a CTA `<Link href="/dashboard">` ("Start a loop with your coach").

**Detail (`progress/show.tsx`)** — `CoachLayout` with a `headerLeading` back-link to
`/progress` (same pattern as `intentions/show.tsx`):

1. **Metric header** — title, `StreakBadge`, completion rate, totals line
   (`12 done · 2 missed · 3 skipped`), the full `OutcomeStrip`.
2. **Journey** — the reused `StrategyTimeline` (version order, top-down, the active
   version flagged — each node's approach + change-reason + superseded-reason),
   rendered identically to the loop-detail screen.
3. **Narrative** — the full `summary`, or "Your coach hasn't summarized this loop
   yet." when `null`.

**Shared components (`patyourself/progress/`)**
- `outcome-strip.tsx` — maps `OutcomeMark[]` to dots (● completed / × failed / – skipped), colour by outcome; empty → muted "No activity yet".
- `streak-badge.tsx` — pill: `completed` → "▲ N-day streak" (primary), `failed` → "N misses — restart" (muted caution), `null/0` → "No streak yet".
- `progress-card.tsx` — the index row composing the above + excerpt.

**Timeline extraction** — `StrategyTimeline`, `TimelineNode`, `CHANGE_REASON`, and the
shared `SectionHeading` move from `intentions/show.tsx` into
`patyourself/strategy-timeline.tsx` unchanged; `intentions/show.tsx` imports them. This
is reuse, not a rewrite — the rendered output of the loop-detail screen is identical,
guarded by its existing test.

**Bottom nav** — insert into the `TABS` array:
```ts
{ label: 'Progress', icon: 'trending-up', href: '/progress', match: ['/progress'] },
```
between Loops and Inbox. The active-state logic (`path === m || path.startsWith(`${m}/`)`)
already handles the `/progress/{id}` detail keeping the Progress tab lit.

**Types (`patyourself/types.ts`)**
```ts
export type OutcomeMark = 'completed' | 'failed' | 'skipped';

export interface LoopStreak {
    outcome: 'completed' | 'failed' | null;
    length: number;
}

export interface LoopProgressCard {
    id: number;
    title: string;
    type: string;
    streak: LoopStreak;
    completion_rate: number | null; // 0–100, null when no decided logs
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[]; // oldest → newest, max 10
    last_logged_at: string | null;
    summary_excerpt: string | null; // index only
}

export type LoopProgressDetail = Omit<LoopProgressCard, 'summary_excerpt'>;
```
The detail page receives `{ intention: LoopProgressDetail; strategies: StrategyData[]; summary: string | null }`.

### 6. Empty & edge states

- **No active loops** → index empty state + coach CTA (no cards, no error).
- **Active loop, zero logs** → card renders, metrics show "—" / "No activity yet", links to detail; detail shows the same plus its v1 strategy in the journey and the narrative empty line.
- **No active strategy** (all retired) → streak `{null, 0}`; rate/totals still computed from lifetime logs.
- **No summary** (SP4 never folded one yet) → excerpt `null` (omitted on card), detail narrative shows the empty line.
- **Loop with only v1** → journey shows a single node (the existing component already handles this).

### 7. Performance

Per request the controller eager-loads `activeStrategy`, `latestSummary`, and
`actionLogs` for the active loops (index) or one loop (detail), then computes in
memory. The only per-loop query is `OutcomeStreak::forStrategy` (one scoped count-ish
read per active loop) — acceptable for a handful of active loops. No pagination
(YAGNI for a single user's active loops); the `latest()->get()` is unbounded but
naturally small.

---

## Testing

**Unit — the read service (`tests/Unit/Services/Progress/LoopProgressTest.php`, new):**
Drive `LoopProgress` directly with factory-built loops/strategies/logs.
- Completion rate: `2 completed, 1 failed` → `67`; rate **excludes** `skipped`
  (`2 completed, 1 skipped` → `100`); `null` when no completed/failed logs.
- Totals: lifetime counts across all of a loop's actions (multiple strategy versions).
- Streak: delegates to `OutcomeStreak` — a `completed` run on the **active** strategy
  yields `{completed, n}`; logs on a **superseded** strategy do not extend it; no
  active strategy → `{null, 0}`.
- Recent strip: newest 10, ordered oldest → newest; fewer than 10 returns all; `[]` when none.
- `last_logged_at` is the max `logged_at` (ISO-8601), `null` when no logs.

**Feature — index (`tests/Feature/Progress/ProgressIndexTest.php`, new):**
- Requires auth (`/progress` redirects a guest).
- Lists **only** the user's `active` loops (paused / completed / archived absent);
  never another user's loops.
- Card payload carries the computed metrics (streak, rate, totals, recent, excerpt)
  for a loop with a known log history.
- A `summary_excerpt` is the first line, trimmed; `null` when the loop has no summary.
- No active loops → the page renders with an empty `loops` array (empty-state path).
- A loop with zero logs renders with zeroed/null metrics (no error).

**Feature — detail (`tests/Feature/Progress/ProgressShowTest.php`, new):**
- Owner can view; a **different** user gets `403` (`Gate::authorize('view')`).
- Props: `intention` metric block, `strategies` ordered by version
  (`StrategyResource` shape: version, change_reason, approach, superseded_reason),
  `summary` content (and `null` when none).
- Serves a non-active owned loop (status filter is index-only).
- Guest redirected.

**Frontend (vitest):**
- `resources/js/pages/progress/index.test.tsx` (new): renders a card per loop with
  streak badge, rate, and the outcome strip; a no-logs loop shows "No activity yet";
  no loops shows the empty state + coach CTA; a card links to `/progress/{id}`.
- `resources/js/pages/progress/show.test.tsx` (new): renders the metric header, the
  reused journey timeline (a revised loop shows ≥2 version nodes with change-reason
  copy), and the narrative; `summary = null` shows the empty narrative line.
- `resources/js/patyourself/bottom-nav.test.tsx` (extend): the **Progress** tab
  renders and is active on `/progress` and `/progress/123`; existing tabs unaffected.
- `resources/js/pages/intentions/show.test.tsx` (regression, if present): the loop
  detail still renders its strategy timeline after the component extraction.

All PHP feature tests that render Inertia pages require built assets (`npm run build`)
to avoid `ViteManifestNotFound`.

---

## Files touched (anticipated)

**New**
- `app/Http/Controllers/ProgressController.php`
- `app/Services/Progress/LoopProgress.php`
- `resources/js/pages/progress/index.tsx`
- `resources/js/pages/progress/show.tsx`
- `resources/js/patyourself/strategy-timeline.tsx` (extracted)
- `resources/js/patyourself/progress/progress-card.tsx`
- `resources/js/patyourself/progress/outcome-strip.tsx`
- `resources/js/patyourself/progress/streak-badge.tsx`
- `tests/Unit/Services/Progress/LoopProgressTest.php`
- `tests/Feature/Progress/ProgressIndexTest.php`
- `tests/Feature/Progress/ProgressShowTest.php`
- `resources/js/pages/progress/index.test.tsx`
- `resources/js/pages/progress/show.test.tsx`

**Modified**
- `routes/web.php` — the two `progress` routes.
- `resources/js/patyourself/bottom-nav.tsx` — the Progress tab.
- `resources/js/patyourself/types.ts` — the new progress types.
- `resources/js/pages/intentions/show.tsx` — import the extracted timeline.
- `resources/js/patyourself/bottom-nav.test.tsx` — Progress-tab assertions.

**Deleted**
- `resources/js/pages/dashboard.tsx` — orphaned starter placeholder.

**Reused unchanged**
- `app/Services/Coach/OutcomeStreak.php`, `app/Http/Resources/StrategyResource.php`,
  `app/Policies/IntentionPolicy.php`, `app/Models/{Intention,Strategy,Action,ActionLog,Summary}.php`.

---

## Success criteria

1. A new **Progress** tab routes to `/progress`, listing the user's active loops as
   cards; the coach chat remains the authenticated home.
2. Each card shows a correct current streak (reusing `OutcomeStreak`), a lifetime
   completion rate that excludes skips, and a recent-activity strip of the last 10
   outcomes.
3. A loop's detail (`/progress/{loop}`) shows the same metrics plus the **journey** —
   the versioned strategy history (initial → stacked-on-success → restrategized) —
   and the coach's rolling **narrative** summary; the journey reuses the existing
   timeline component, not a copy.
4. The progress detail is owner-gated: a user cannot view another user's loop (403).
5. Empty states are handled: no active loops (coach CTA), a loop with no logs (no
   error, "—" metrics), a loop with no summary (empty narrative line).
6. The orphaned `dashboard.tsx` placeholder is removed; the loop-detail screen still
   renders its strategy timeline unchanged after the extraction.
7. All new and affected tests pass (PHPUnit + vitest); `vendor/bin/pint` clean; no new
   TypeScript errors in touched files.

## Scope boundary — explicitly NOT in SP5

- **No writes, no LLM calls, no new domain logic** — pure read-side aggregation.
- **No new model, migration, or table** — reads SP1/SP4 schema.
- **No account-level rollup header** — dropped in brainstorming; the dashboard is
  per-loop only.
- **No `CoachUsage` / cost metrics** — SP4's queued coaching path is currently
  unmetered, so usage data is incomplete; the dashboard deliberately omits it.
- **No charting library** — the sparkline is CSS/marks only.
- **No paused / completed / archived loops on the index** — active only (the detail
  serves any owned loop by URL).
- **No deferred props / skeletons** — direct load (Decision 5).
- **No editing strategies or loops from the dashboard** — read-only; writes stay on
  their existing screens.
- **No removal of the unused `app-layout` / AppSidebar stack** — only the orphaned
  `dashboard.tsx` is deleted; broader starter-cruft cleanup is out of scope.
