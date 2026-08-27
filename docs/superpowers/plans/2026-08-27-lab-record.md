# Lab Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render the experiment on `/loops/{loop}` and `/loops` — the per-version evidence that `LoopProgress` already computes and nothing displays.

**Architecture:** No new aggregation. `LoopProgress::forCurrentVersion()` and `experimentsFor()` exist and are tested; this wires them through `IntentionController@show` and renders them. Frontend work is React/Inertia components under `resources/js/patyourself/`, tested with Vitest alongside the existing page tests.

**Tech Stack:** PHP 8.4, Laravel 13, Inertia v3 + React 19, Tailwind v4, PHPUnit 12, Vitest, Pint.

## Global Constraints

Verbatim from the spec and CLAUDE.md. Every task implicitly includes these.

- Reasons, notes and reflections are **verbatim**. Never trim, squish or sentence-case, UI included.
- Failure language is about **the strategy, never the user**.
- **No quantities** — no calories, portions, weights, or numeric targets anywhere, including as a goal the rate is compared against.
- **The notebook never nags** — no overdue counts, no red backlog states, no prompt to start an experiment.
- **Gamification is capped at the §2 momentum rules**: streak renders only while running, disappears when broken, no reset/milestone/celebration. A falling delta renders in the same weight and colour as a rising one.
- `skipped` never enters a denominator.
- `planned_days: null` = open-ended. Never a countdown, progress bar, or zero-day experiment.
- Every change is programmatically tested; failing test first, watched failing for the right reason.
- **Every load-bearing assertion is mutation-verified.** Change the implementation so it should fail, run that single test, confirm it fails for the right reason, restore.
- `vendor/bin/pint --dirty --format agent` before each PHP commit.
- Do not delete existing tests. `strategy-timeline.test.tsx` is **extended**, never replaced.

### Verified facts

Read out of the codebase; do not re-derive.

| Fact | Value |
| --- | --- |
| `LoopProgress::forCurrentVersion(Intention): ?array` | Returns `version, started_at, day_of_experiment, planned_days, is_under_review, verdict, streak{outcome,length}, completion_rate, totals{completed,failed,skipped}, last_logged_at`. **Null** when no active version. |
| `LoopProgress::experimentsFor(Intention): list<array>` | Per version: `strategy_id, version, status, intervention_point, approach, hypothesis, started_at, review_at, day_of_experiment, planned_days, is_under_review, verdict, verdict_note, outcomes[{outcome,reason,logged_at}], totals{completed,failed,skipped}` |
| Log → version attribution | via `actions.strategy_id` (migration `2026_06_04_100003_create_actions_table.php:20`) |
| Reflection model | `Summary` — `content`, `window_start`, `window_end`, `events_count`; relation `Intention::latestSummary` |
| Current `/loops/{loop}` props | `intention, strategies, outcomes, outcomes_total, showing_all_history, notes` |
| `ActiveStrategySummary` (TS) | today: `intervention_point, approach, rationale, version` |
| Stage accents | `--cue #5B8398`, `--craving #8A5B79`, `--response #E26B3E`, `--reward #5E8C6A` — scoped to `.py-shell, .py-landing, .py-host`, which `CoachLayout` does **not** apply |
| `.py-host` collision | also defines `--border`; adding it to the layout would fight shadcn's `border-border`. Use a `--stage-*` namespace at `:root` instead. |
| Dead chat CSS | `patyourself.css` lines 218–219, 232–236, 240–243, 294–296 |
| Existing components | `StrategyTimeline` + `SectionHeading` in `strategy-timeline.tsx`; `OutcomeStrip`, `StreakBadge`, `ProgressCard` under `patyourself/progress/` |

---

### Task 1: Wire the experiment data through the controller

Backend only. After this the lab record receives everything it needs and renders nothing new yet.

**Files:**
- Modify: `app/Http/Controllers/IntentionController.php` (`show`)
- Modify: `app/Http/Resources/IntentionResource.php` (active-strategy summary)
- Modify: `routes/web.php` (progress detail redirect)
- Test: `tests/Feature/` — the existing loop-show controller test, plus a new redirect test

**Interfaces:**
- Produces: `/loops/{loop}` gains props `current_version` (`?array` from `forCurrentVersion()`), `experiments` (`list<array>` from `experimentsFor()`), and `reflection` (`?array{content,window_start,window_end,events_count}`). `IntentionResource`'s `strategy` summary gains `day_of_experiment: int`, `planned_days: ?int`, `is_under_review: bool`. Tasks 2–4 consume these names exactly.

- [ ] **Step 1: Find the existing controller test and read it**

Run: `grep -rln "loops/show\|IntentionController" tests/Feature | head`

Read the file that covers `show`. Add to it; do not create a parallel file.

- [ ] **Step 2: Write the failing tests**

Add to the loop-show controller test:

```php
public function test_the_lab_record_carries_the_current_experiment_and_the_ladder(): void
{
    $user = User::factory()->create();
    $loop = Intention::factory()->for($user)->create();
    $strategy = Strategy::factory()->for($loop, 'intention')->create(['status' => 'active', 'version' => 1]);

    $this->actingAs($user)
        ->get("/loops/{$loop->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('current_version.day_of_experiment')
            ->has('current_version.completion_rate')
            ->has('current_version.totals')
            ->has('experiments', 1)
            ->has('experiments.0.totals')
            ->has('experiments.0.version'));
}

public function test_the_lab_record_carries_the_reflection_with_its_provenance(): void
{
    $user = User::factory()->create();
    $loop = Intention::factory()->for($user)->create();

    Summary::factory()->for($loop, 'intention')->create([
        'content' => 'The craving reads more like hunger than habit.',
        'events_count' => 28,
    ]);

    $this->actingAs($user)
        ->get("/loops/{$loop->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('reflection.content', 'The craving reads more like hunger than habit.')
            ->where('reflection.events_count', 28)
            ->has('reflection.window_start')
            ->has('reflection.window_end'));
}

public function test_a_loop_with_no_active_version_carries_a_null_current_version(): void
{
    $user = User::factory()->create();
    $loop = Intention::factory()->for($user)->create();

    $this->actingAs($user)
        ->get("/loops/{$loop->id}")
        ->assertInertia(fn (Assert $page) => $page->where('current_version', null));
}

public function test_the_progress_detail_route_redirects_into_the_lab_record(): void
{
    $user = User::factory()->create();
    $loop = Intention::factory()->for($user)->create();

    $this->actingAs($user)
        ->get("/progress/{$loop->id}")
        ->assertRedirect("/loops/{$loop->id}");
}
```

Check the factory names against `database/factories/` before running — use whatever `Summary`/`Strategy` factories actually exist, and their existing states.

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test --compact --filter="lab_record|progress_detail_route" tests/Feature`

Expected: FAIL on missing props and on `/progress/{id}` returning 200 instead of a redirect.

- [ ] **Step 4: Implement**

In `IntentionController@show`, inject `LoopProgress $progress`, eager-load `latestSummary`, and add to the `Inertia::render` array:

```php
'current_version' => $progress->forCurrentVersion($intention),
'experiments' => $progress->experimentsFor($intention),
'reflection' => $intention->latestSummary === null ? null : [
    'content' => $intention->latestSummary->content,
    'window_start' => $intention->latestSummary->window_start?->toIso8601String(),
    'window_end' => $intention->latestSummary->window_end?->toIso8601String(),
    'events_count' => $intention->latestSummary->events_count,
],
```

In `IntentionResource`, add to the active-strategy summary:

```php
'day_of_experiment' => $strategy->dayOfExperiment(),
'planned_days' => $strategy->plannedDays(),
'is_under_review' => $strategy->isUnderReview(),
```

In `routes/web.php`, replace the `progress/{intention}` GET with a redirect to `loops.show`, keeping the `progress.show` route name so nothing that generates the URL breaks.

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test --compact tests/Feature` — expected PASS, including the pre-existing progress tests. **If `ProgressController@show` tests now fail because the route redirects, that is real:** update those tests to assert the redirect rather than deleting them.

- [ ] **Step 6: Mutation-verify**

1. Drop `'experiments' => …` from the render array → the ladder test must fail.
2. Change `events_count` to a hardcoded `0` → the reflection test must fail.
3. Make `forCurrentVersion` always return an array → the null test must fail.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/IntentionController.php app/Http/Resources/IntentionResource.php routes/web.php tests/
git commit -m "feat(ui): the lab record receives the experiment it was missing"
```

---

### Task 2: The experiment header and momentum

**Files:**
- Create: `resources/js/patyourself/experiment-header.tsx`
- Create: `resources/js/patyourself/experiment-header.test.tsx`
- Modify: `resources/js/patyourself/types.ts`

**Interfaces:**
- Consumes: the `current_version` prop from Task 1.
- Produces: `<ExperimentHeader current={CurrentVersionData | null} previousRate={number | null} />`, and the exported `CurrentVersionData` type. Task 3 does not use it; Task 5 restyles it.

- [ ] **Step 1: Add the type**

In `types.ts`:

```ts
/** The active experiment's own record — LoopProgress::forCurrentVersion(). Null when no version is active, which is a good state. */
export interface CurrentVersionData {
    version: number;
    started_at: string;
    day_of_experiment: number;
    planned_days: number | null;
    is_under_review: boolean;
    verdict: string | null;
    streak: { outcome: string | null; length: number };
    completion_rate: number | null;
    totals: { completed: number; failed: number; skipped: number };
    last_logged_at: string | null;
}
```

- [ ] **Step 2: Write the failing test**

`experiment-header.test.tsx` — these are the discriminating cases:

```tsx
it('renders a planned run as day N of M', () => {
    render(<ExperimentHeader current={build({ day_of_experiment: 9, planned_days: 14 })} previousRate={null} />);
    expect(screen.getByText(/day 9 of 14/i)).toBeInTheDocument();
});

it('renders an open-ended run without a countdown', () => {
    render(<ExperimentHeader current={build({ day_of_experiment: 9, planned_days: null })} previousRate={null} />);
    expect(screen.getByText(/open-ended/i)).toBeInTheDocument();
    expect(screen.queryByText(/of \d/i)).not.toBeInTheDocument();
});

it('asks for a verdict when the version is under review', () => {
    render(<ExperimentHeader current={build({ is_under_review: true })} previousRate={null} />);
    expect(screen.getByText(/ready for a verdict/i)).toBeInTheDocument();
});

it('states the no-experiment case plainly and does not prompt', () => {
    render(<ExperimentHeader current={null} previousRate={null} />);
    expect(screen.getByText(/logging continues/i)).toBeInTheDocument();
    expect(screen.queryByText(/start an experiment/i)).not.toBeInTheDocument();
});

it('omits the delta entirely when there is no previous version', () => {
    render(<ExperimentHeader current={build({ completion_rate: 68 })} previousRate={null} />);
    expect(screen.queryByText(/up from|down from/i)).not.toBeInTheDocument();
    expect(screen.queryByText('—')).not.toBeInTheDocument();
});

it('renders a falling delta without alarm language', () => {
    render(<ExperimentHeader current={build({ completion_rate: 41 })} previousRate={68} />);
    expect(screen.getByText(/down from 68%/i)).toBeInTheDocument();
    expect(screen.queryByText(/warning|slipping|failing|behind/i)).not.toBeInTheDocument();
});

it('shows a running streak', () => {
    render(<ExperimentHeader current={build({ streak: { outcome: 'completed', length: 9 } })} previousRate={null} />);
    expect(screen.getByText(/9 in a row/i)).toBeInTheDocument();
});

it('shows nothing at all once a streak has broken', () => {
    render(<ExperimentHeader current={build({ streak: { outcome: 'failed', length: 3 } })} previousRate={null} />);
    expect(screen.queryByText(/in a row/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/streak|reset|lost|0/i)).not.toBeInTheDocument();
});
```

Write a `build(overrides)` helper returning a full `CurrentVersionData` so each test states only what it is testing.

- [ ] **Step 3: Run to verify it fails** — `npx vitest run experiment-header`. Expected: module not found.

- [ ] **Step 4: Implement `ExperimentHeader`**

Rules, restated because they are the whole component:

- `is_under_review` wins over the day count — a version past its review date reads `READY FOR A VERDICT`, not `DAY 15 OF 14`.
- `planned_days === null` → `DAY {n} · OPEN-ENDED`. Never compute a remainder.
- `current === null` → `No experiment running · logging continues`, in the same neutral styling as any other state. No amber, no border-warning, no call to action.
- Delta renders only when `previousRate !== null && current.completion_rate !== null`. Rising and falling share one class; only the word and the arrow glyph differ.
- Streak renders only when `streak.outcome === 'completed' && streak.length > 0`. Every other case renders nothing.
- Numbers (`day_of_experiment`, rate, totals) get `font-mono`; prose does not.

- [ ] **Step 5: Run to verify it passes** — `npx vitest run experiment-header`

- [ ] **Step 6: Mutation-verify**

1. Render the countdown unconditionally (ignore the null check) → open-ended test must fail.
2. Put the day count ahead of `is_under_review` → verdict test must fail.
3. Render `—` when there is no previous rate → delta-omitted test must fail.
4. Add `text-amber-600` to the falling delta → confirm the falling test still passes, then **add an assertion that pins the class parity between rising and falling** so it does not. Restore.
5. Render the streak regardless of outcome → broken-streak test must fail.

- [ ] **Step 7: Commit** — `git commit -m "feat(ui): the experiment header answers what is being tested"`

---

### Task 3: The experiment ladder and the reflection

**Files:**
- Modify: `resources/js/patyourself/strategy-timeline.tsx` (extend — heading becomes "Experiments", per-version evidence added)
- Modify: `resources/js/patyourself/strategy-timeline.test.tsx` (add; never remove)
- Create: `resources/js/patyourself/reflection.tsx` + `reflection.test.tsx`
- Modify: `resources/js/patyourself/types.ts`

**Interfaces:**
- Consumes: `experiments` and `reflection` from Task 1.
- Produces: `<Reflection reflection={ReflectionData | null} />`; `StrategyTimeline` gains an optional `experiments` prop carrying per-version `totals` and `outcomes`.

- [ ] **Step 1: Write the failing tests**

Ladder, added to `strategy-timeline.test.tsx`:

```tsx
it('leads with raw counts and follows with the rate', () => { /* 9/22 before 41% */ });

it('keeps "not yet tested" distinct from a failed version', () => { /* totals all zero → "Not yet tested", no 0% */ });

it('renders a failure reason exactly as it was written', () => {
    // The fixture MUST arrive untidy or this test proves nothing.
    const reason = '  kept   FORGETTING by evening\n\nthen gave up  ';
    // assert the rendered text equals `reason`, not a trimmed form
});

it('attributes outcomes to the version that was running', () => { /* v1 totals ≠ v2 totals */ });
```

Reflection, in `reflection.test.tsx`:

```tsx
it('renders the body verbatim, including its line breaks', () => { /* multi-line, untidy input */ });
it('renders the window and the occasion count', () => { /* "28 occasions" present */ });
it('omits the provenance line when the window is unknown', () => { /* nulls → no stray separator */ });
it('states the empty case without implying something is outstanding', () => {
    render(<Reflection reflection={null} />);
    expect(screen.getByText(/no reflection written yet/i)).toBeInTheDocument();
    expect(screen.queryByText(/coach|yet to|hasn't|waiting/i)).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run to verify they fail** — `npx vitest run strategy-timeline reflection`

- [ ] **Step 3: Implement**

- `SectionHeading` for the ladder changes to `Experiments`.
- Each version row gains `{completed}/{decided} held` in mono, then the rate, then the existing verdict / run-length line.
- `Not yet tested` stays the zero state — do not let it become `0%`.
- Failure reasons render inside the version, verbatim, with `whitespace-pre-line`. Do **not** call `.trim()` anywhere in this component.
- `Reflection` renders `SectionHeading` `What the record shows`, the body with `whitespace-pre-line`, then the provenance line in mono, only when `window_start`, `window_end` and `events_count` are all present.

- [ ] **Step 4: Run to verify they pass**

- [ ] **Step 5: Mutation-verify**

1. Add `.trim()` to the reason → the verbatim test must fail. **If it still passes, the fixture is not untidy enough — fix the fixture.**
2. Render `0%` instead of `Not yet tested` → that test must fail.
3. Render the provenance line unconditionally → the omit test must fail.
4. Change the empty state back to "Your coach hasn't summarized this loop yet." → the empty-case test must fail on the `/coach/` assertion.

- [ ] **Step 6: Commit** — `git commit -m "feat(ui): the ladder shows which version actually held"`

---

### Task 4: Compose the lab record and the loops index

**Files:**
- Modify: `resources/js/pages/loops/show.tsx`
- Modify: `resources/js/pages/loops/show.test.tsx`
- Modify: `resources/js/pages/loops/index.tsx`
- Modify: `resources/js/pages/progress/show.tsx` — see Step 4

- [ ] **Step 1: Write the failing tests** — `loops/show` renders the header, ladder and reflection in that order; `loops/index` shows `DAY 9 OF 14` on a running row, `READY FOR A VERDICT` on a reviewable one, and `no experiment` on a loop with none.

- [ ] **Step 2: Run to verify they fail**

- [ ] **Step 3: Compose `loops/show.tsx`** — order: state chips → experiment header → momentum → reflection → anatomy → experiments ladder → outcome history → notes. The reflection sits high because it is the thing worth reading; anatomy sits below it because it changes rarely.

- [ ] **Step 4: Handle `progress/show.tsx`**

Its route now redirects, so the page is unreachable. **Do not delete it or its test in this task** — deleting `progress/show.test.tsx` would remove tests. Leave both in place and note them in the final report as a follow-up for the owner to approve.

- [ ] **Step 5: Run to verify they pass, then mutation-verify** the index states (swap `is_under_review` handling → the verdict row test must fail).

- [ ] **Step 6: Commit**

---

### Task 5: Stage accents, mono, and the dead CSS

**Files:**
- Modify: `resources/css/patyourself.css`
- Modify: `resources/js/pages/loops/show.tsx` (Anatomy only)

- [ ] **Step 1: Expose the stage accents**

Add to `:root` in both themes, outside the `.py-shell, .py-landing, .py-host` block so `CoachLayout` screens can reach them, under a namespace that cannot collide with shadcn's `--border`/`--background`:

```css
:root {
  --stage-cue: #5B8398;      --stage-cue-soft: #E4ECEF;
  --stage-craving: #8A5B79;  --stage-craving-soft: #EFE6EC;
  --stage-response: #E26B3E; --stage-response-soft: #FCEDE4;
  --stage-reward: #5E8C6A;   --stage-reward-soft: #E9F1E9;
}
```

Pick dark-theme values that hold contrast; verify against the existing `[data-theme="dark"]` block.

- [ ] **Step 2: Use them in Anatomy** — each stage takes its own colour; the intervention point is emphasised with weight and the existing "strategy acts here" pill rather than by swapping to `bg-primary`.

- [ ] **Step 3: Delete the dead chat CSS** — `.py-avatar`, `.py-avatar--user`, `.py-msg*`, `.py-typing*`, `.coach-head*`. Before deleting, confirm nothing references them:

```bash
grep -rn "py-avatar\|py-msg\|py-typing\|coach-head" resources/js resources/views
```

Expected: no matches. If there are matches, stop and report rather than deleting.

- [ ] **Step 4: Build and eyeball** — `npm run build`, then load `/loops/{id}` and confirm the four stages are visually distinct in both themes.

- [ ] **Step 5: Commit**

---

### Task 6: Verification and merge

- [ ] **Step 1:** `php artisan test --compact` — expect the full suite green.
- [ ] **Step 2:** `npx vitest run` — expect 81 baseline plus the new tests.
- [ ] **Step 3:** `npm run build && npm run lint`
- [ ] **Step 4:** `git status --short` — expect empty. If `package-lock.json` is modified, `git checkout --` it; never commit the worktree name rewrite.
- [ ] **Step 5:** No migrations in this plan, so the MySQL cross-check does not apply. State that explicitly rather than skipping it silently.
- [ ] **Step 6:** Merge `--no-ff` into local `main`, re-run the suite on the merged result, push `main` only. No PR.
- [ ] **Step 7:** Report the follow-ups this plan deliberately left: the orphaned `progress/show.tsx` and its test, the `/progress` index nav entry, and the landing page's chat-era pitch.

## Self-Review

**Spec coverage.** §1 experiment header → Task 2. §2 momentum → Task 2. §3 ladder → Task 3. §4 reflection → Task 3. §5 loops index → Tasks 1 and 4. §6 redirect → Task 1. §7 typography and stage accents → Tasks 2, 3 and 5. §8 dead CSS → Task 5.

**Placeholders.** Task 4's tests are described rather than written out, because their fixtures depend on the exact prop shapes Tasks 1–3 settle. Every other test is concrete. The one open judgement is Task 2 mutation 4, which deliberately asks the implementer to strengthen an assertion if the mutation does not fail it.

**Type consistency.** `CurrentVersionData` is defined once in Task 2 and matches `forCurrentVersion()`'s documented return exactly. `current_version`, `experiments` and `reflection` are named identically in Task 1's controller and Tasks 2–4's consumers.

**Known risk.** Task 5 changes colour on an existing screen. It is last on purpose: if it needs rework, Tasks 1–4 have already landed and the app is strictly better than it was.
