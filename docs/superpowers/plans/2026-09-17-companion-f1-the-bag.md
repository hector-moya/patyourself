# Companion F1 — The bag: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blob earns XP from the record, learns a skill you chose, gathers from a world the record stocked, builds a container out of it, and answers to a name — so two people with the same record can have visibly different clearings.

**Architecture:** Four new tables store only what cannot be derived (choices, inventory, world stock, the name). Earned XP stays derived from the record on every read, exactly as the ladder's counts already are; only `companions.xp_spent` is stored, and the balance is `max(0, earned − spent)`. The existing `CompanionResolver` gains the insight *kind* it currently discards so the XP table can price each source. The world is stocked by a listener on the existing `App\Events\ActionLogged`. The gift ladder is untouched — it keeps handing out the body and the wearables, unchosen and unpurchasable.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit 12 (feature tests preferred), Inertia v3 + React 19, Vitest, Tailwind v4 plus the hand-written `resources/css/patyourself.css`, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-09-17-companion-f1-the-bag-design.md`, whose reasoning lives in `docs/superpowers/specs/2026-09-17-companion-progression-arc-design.md`. Read both; this plan argues from them and does not restate their justifications.

---

## Global Constraints

Every task's requirements implicitly include this section. Values are copied verbatim from the spec.

- **A `failed` outcome pays exactly what a `completed` one pays.** Also a `skipped` one. There is a test for this and it is the therapeutic invariant, not a detail. (arc §3, F1 §9)
- **Never show a total, a completion percentage, or an end state. Show what is choosable now.** The existing guard in `resources/js/pages/companion.test.tsx` — `never shows what has not happened`, matching `/locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i` — is kept, and **every new surface must pass it**. A skill list with prices passes. A skill list reading "2 of 6 known" does not. (arc §4)
- **Nothing regresses.** No decay, no expiry, no upkeep, nothing removed from Blob for inactivity, ever. Materials *transform*; they are never taken. (arc §4)
- **Never show a locked row.** A skill Blob has not met is absent, not greyed. A node's skill is revealed by meeting the node, never listed in advance. (F1 §2)
- **XP rates** (F1 §3), all in `config('companion.xp')`: outcome 1st of the day **3**, 2nd **2**, 3rd and after **1** (floor, never zero); reflection **5**; chain correction **8**; new strategy version **10**; concluded experiment **15**. The four insight sources sit **outside** the taper.
- **The taper groups by `logged_at`**, not by the occasion — a catch-up session logging seven days at once pays 3+2+1+1+1+1+1 = **10**, not 21.
- **Base capacity is 5.** Each container adds **5**, cumulative. (F1 §1)
- **Node stock accrues only from `learned_at`**, so an established account arrives to an empty clearing. Stock is uncapped and nothing expires. (F1 §4)
- **Item types stay capped at four wearables forever** (`config('companion.item_types')`), guarded by `CompanionLadderTest`. The new categories (`material`, `container`, …) are uncapped and live in a *separate* config key. (arc §5)
- **Scene thresholds never move.** `cabin` stays at `insights: 5`. F1 adds no scene. (config/companion.php)
- **Copy rules** for anything authored: sentence case, one or two sentences, no exclamation marks, never congratulating, no second person keeping score. Say what Blob can now do — never how well the user is performing.
- **Model convention:** the `#[Fillable([...])]` **attribute**, never a `$fillable` property. See `App\Models\User`, `App\Models\CompanionRemark`.
- **Foreign keys stay out of `#[Fillable]`.** Create through the relation (`$user->companion()->firstOrCreate([])`), which sets the key itself. `CompanionRemark` is the precedent.
- **Tests are SQLite, production is MySQL.** Bind values in raw SQL; never embed quoted literals. And `assertDatabaseMissing` on a column that does not exist is a constant-false predicate that passes forever on SQLite — assert on columns you have confirmed exist.
- **Run `vendor/bin/pint --dirty --format agent`** before every commit that touches PHP.
- **Never run `npm run lint` from the repo root** — eslint descends into `.claude/worktrees/*` and auto-edits files there. Scope it: `npx eslint resources/js/<path>`.
- **Never run `npx prettier --write` on `resources/css/*.css`.** Both CSS files are hand-formatted and already fail `format:check` on `main`; `--write` turns a small addition into a multi-thousand-line diff. Hand-format new CSS to match its neighbours.
- **`className`: use `cn()`, never a template literal** — prettier's tailwind plugin eats the leading space and ships `class="c-entryis-new"`. Assert with `toHaveClass`, never `toContain`.
- **Column-limited eager loads hide new columns.** If you add `->with('rel:id,title')` anywhere, a later column added to `rel` reads as null forever with the suite green. Prefer full eager loads.

---

## File structure

**New — storage**

| File | Responsibility |
| --- | --- |
| `database/migrations/*_create_companions_table.php` | One row per user: the name and `xp_spent`. Nothing else. |
| `database/migrations/*_create_companion_skills_table.php` | What was bought, and when — `learned_at` is the epoch for that skill's node. |
| `database/migrations/*_create_companion_items_table.php` | The bag. Category is looked up in config, never duplicated here. |
| `database/migrations/*_create_companion_nodes_table.php` | The world's stock. The one stored value that could be derived. |
| `app/Models/Companion.php` | The row, its three relations, and `capacity()`. |
| `app/Models/CompanionSkill.php` | A learned skill. |
| `app/Models/CompanionItem.php` | One stack in the bag. |
| `app/Models/CompanionNode.php` | One node's standing stock. |
| `database/factories/CompanionFactory.php` … `CompanionNodeFactory.php` | Test fixtures. |

**New — the economy**

| File | Responsibility |
| --- | --- |
| `app/Services/Companion/CompanionWallet.php` | `earnedFor` / `spentFor` / `balanceFor`. Earned is derived; spent is read from the row. |
| `app/Services/Companion/CompanionEconomyException.php` | Refusals the economy makes: cannot afford, bag full, materials short. |
| `app/Services/Companion/CompanionBag.php` | What is held, what capacity is, what the skill list contains. The read model for the surfaces. |
| `app/Actions/LearnSkill.php` | Debits `xp_spent`, writes the skill row. The only writer of `xp_spent`. |
| `app/Actions/MeetNode.php` | The encounter that reveals a skill. Writes the node row at zero. |
| `app/Actions/HarvestNode.php` | Moves stock into the bag, bounded by remaining capacity. |
| `app/Actions/BuildItem.php` | Consumes exactly a recipe, writes the built item. |
| `app/Listeners/StockCompanionNodes.php` | One unit into each unlocked node, per `ActionLogged`. |

**New — the surfaces**

| File | Responsibility |
| --- | --- |
| `app/Http/Controllers/CompanionSkillController.php` | `POST /companion/skills` — buy one. |
| `app/Http/Controllers/CompanionNodeController.php` | `POST /companion/nodes/{node}` — click one. |
| `app/Http/Controllers/CompanionBuildController.php` | `POST /companion/build` — build one. |
| `app/Http/Controllers/CompanionNameController.php` | `PATCH /companion/name` — rename. |
| `resources/js/patyourself/companion-bag.tsx` | The bag modal: capacity, contents, recipes, the skill list, on Radix's dialog primitives in the panels' wood. |
| `resources/js/patyourself/companion-bag.test.tsx` | Its own guard against totals, plus the open/close behaviour. |

**Modified**

| File | Change |
| --- | --- |
| `config/companion.php` | New keys: `xp`, `skills`, `nodes`, `bag`, `capacity`. Existing keys untouched except the `{name}` token pass in Batch 3. |
| `app/Services/Companion/CompanionResolver.php` | `insightMoments()` carries the kind; `logMoments()` and `insightMoments()` become public. Ladder behaviour unchanged. |
| `app/Models/User.php` | `companion(): HasOne`. |
| `app/Http/Controllers/CompanionController.php` | Adds the bag payload to the Inertia props. |
| `routes/web.php` | Four new POST/PATCH routes beside the existing `companion` GET. |
| `resources/js/patyourself/companion.tsx` | `CompanionData` gains `bag`; `describe()` takes the name. |
| `resources/js/patyourself/companion-room.tsx` | Clickable nodes in the scene. |
| `resources/js/pages/companion.tsx` | The layout settled in Task 1. |
| `resources/js/patyourself/companion.fixture.ts` | A `bag` default so the four other screens keep rendering. |
| `resources/css/patyourself.css` | The bag modal — overlay, frame, rows — hand-formatted. |

---

## Batches

| Batch | What it delivers | Risk |
| --- | --- | --- |
| **1** | The layout decision (a mockup, no code) + the tables + the wallet + buying a skill. Server-side only. | None on screen. |
| **2** | The world: config content, the stocking listener, meeting a node, harvesting, building. Server-side only. | None on screen. |
| **3** | The name: the `{name}` token across all authored copy, the guard, the rename. | Touches 19 authored strings. |
| **4** | The bag modal and the XP readout on `/companion`, per Task 1's decision. | First UI. |
| **5** | The clearing: clickable nodes inside `CompanionRoom`, the encounter, harvest and build wired to the scene. | Art. |

**Stop for review after every batch.**

---

# BATCH 1 — the layout decision, the tables, the wallet

Nothing in this batch renders. Task 1 produces a mockup and a decision; Tasks 2–6 produce PHP behind tests.

---

### Task 1: Settle the `/companion` layout

`/companion` today carries two panels in one `.c-wrap` grid (`resources/css/patyourself.css:1042`): the room card (place bar, scene, plinth) and the record. F1 adds four things — an XP readout, a skill list, a bag, and clickable nodes. F1 §8 states a working assumption, not an answer, and says explicitly that settling this comes before building any of it.

**Files:**
- Create: `<scratch>/companion-f1-layout.html` — the mockup, not in the repo
- Read: `resources/js/pages/companion.tsx`, `resources/css/patyourself.css:1015-1103`

**Interfaces:**
- Consumes: nothing
- Produces: a layout decision that Tasks 14–19 build against. Specifically: which panel holds the bag, where the XP number sits, and the mobile stack order.

> **ANSWERED, 17 Sep 2026.** None of the three candidates below. **The bag is a button in the plinth
> that opens a modal.** The page stays at two panels, the record is untouched, and the button carries
> the capacity (`Bag 3 / 5`) because that is the bag's one ambient fact. Steps 1–3 are kept as the
> record of what was weighed; Step 4 below carries the settled decision and is what Batches 4–5
> build. F1 §8 has been rewritten to match.

- [x] **Step 1: Establish the three candidate layouts**

Only the second and third were live; the first is recorded because it is what F1 §8 assumed and a later reader should see why it lost.

```
A — F1 §8's assumption: right column stacks bag over record
   ┌──────────────┬──────────┐      room column ≈ 580px tall
   │              │   BAG    │      record column grows forever
   │     ROOM     ├──────────┤      → the tall column gets taller
   │              │  RECORD  │
   └──────────────┴──────────┘

B — the bag under the room, in the world's own column   ← RECOMMENDED
   ┌──────────────┬──────────┐      balances the two columns
   │     ROOM     │          │      node click (room) → skill row (bag)
   ├──────────────┤  RECORD  │      are adjacent
   │     BAG      │          │      XP in the place bar, above both
   └──────────────┴──────────┘

C — two panels, the right one tabbed
   ┌──────────────┬──────────┐      rejected: a tab hides the record,
   │     ROOM     │ [bag|rec]│      and the record is the emotional
   │              │          │      payload of the screen
   └──────────────┴──────────┘
```

**Recommendation: B.** Three reasons, in order of weight:

1. **Adjacency.** The whole discovery mechanic (F1 §2) is: click a node in the room, a skill appears in the list. Those two things being in the same column, one above the other, is the mechanic being legible. In A they are diagonal across the page.
2. **Column balance.** The room card is roughly fixed at ~580px; the record grows with every unlock and is already the taller column. A puts the bag on the growing side and makes the imbalance worse; B puts it on the fixed side and evens them out.
3. **The record stays whole.** It keeps its own frame, its own ribbon and its own scroll, exactly as `4c5af3f` ("split what happened out of what the loop is") left it.

**The XP number goes in the place bar**, as F1 §8 assumed — `the forest · 48 xp · dusk 19:30`. Balance only. No target, no bar, no "next at", no delta.

**Mobile cost, stated rather than discovered:** the stack becomes room → bag → record, pushing the record to third. The bag is short (a capacity line, a few stacks, a few priced rows), so the record stays reachable, but it is one scroll further than it is today. Accepted.

- [ ] **Step 2: Build the mockup**

Write a single self-contained HTML file using the real hex values from `resources/css/patyourself.css:1042-1103` (`#241C15` board, `#181209` bars, `#F2E3BF` / `#C9AE7E` / `#A58D64` type) so the decision is read in the wood it will actually live in. Show B at desktop width and at phone width, with A beside it for comparison. Box the scene rather than reproducing the sprite art — this is a layout decision, not a pixel one.

The bag's contents, which the mockup must show because they are what the guard is about:

```
┌─ THE BAG ────────────────── 3 / 5 ─┐   ← held-of-capacity, never "60%"
│  fibre        3                     │
├─────────────────────────────────────┤
│  BUILD                              │
│  basket       4 fibre               │   ← a price, not a requirement
├─────────────────────────────────────┤
│  BLOB COULD LEARN                   │
│  gather fibre           20 xp       │   ← met the reeds, so it is listed
└─────────────────────────────────────┘
```

Nothing in that panel names how many skills exist, how many are unbought, or how far along anything is.

- [x] **Step 3: Show it and stop**

Published 17 Sep 2026: <https://claude.ai/code/artifact/02cc1e73-548c-4f96-a4bd-f4ce568a634a> — both candidates at honest proportions, the phone stack, and the rule the bag has to pass. Tasks 14–19 do not start until this is answered.

- [x] **Step 4: The settled layout**

Recorded in F1 §8 and in the arc doc's open-questions list, both of which now say this rather than pointing at a mockup.

```
/companion — two panels, unchanged in number
┌──────────────────────────────┬──────────────────┐
│ the forest · 48 xp · dusk    │ WHAT HAS HAPPENED│   XP balance in the
│ ┌──────────────────────────┐ │  scarf    11 Sep │   place bar. Balance
│ │        the scene         │ │  walk      4 Sep │   only — no target,
│ │   reeds ·    · deadfall  │ │  shoes    28 Aug │   no bar, no "next at"
│ └──────────────────────────┘ │  arms     24 Aug │
│  [Pet] [Play] [Poke] [Bag 3/5]│  legs     22 Aug │   ← the bag is a button
└──────────────────────────────┴──────────────────┘

          ┌─ THE BAG ─────────────── 3 / 5 ─┐   opened by that button,
          │  HELD                            │   in the same wood, over
          │  fibre                        3  │   a dimmed page
          │  BUILD                           │
          │  basket                  4 fibre │
          │  BLOB COULD LEARN                │
          │  gather fibre             20 xp  │
          └──────────────────────────────────┘
```

Four consequences the later tasks are built on:

1. **The record is untouched.** No new panel competes with it, so `.c-wrap` keeps its two columns and the phone keeps its two-item stack. Task 15 writes no grid CSS.
2. **The button carries the capacity.** `Bag 3 / 5` — the bag's one ambient fact, the number that is true whether or not you are looking. Everything else in the bag is static until you act on it, which is the argument for a modal in the first place.
3. **An encounter that reveals a skill opens the bag once.** A panel made the discovery mechanic visible; a modal hides it. This pays that back — clicking the reeds shows Blob looking at them *and* opens the bag on the new row, once, as the response to a click you made. Not a nag; nothing opens unprompted.
4. **Radix primitives, not `@/components/ui/dialog`.** That wrapper hardcodes `bg-background`, `rounded-lg`, `p-6` and a lucide close button, and the close button cannot be removed through `className`. The primitives give the focus trap, Esc and overlay; the skin is the panels'.

---

### Task 2: Pin the ladder before touching the resolver

`insightMoments()` is about to change shape. The ladder walk and the tail both index into its return value, and neither should behave differently afterwards. Pin it first.

**Files:**
- Create: `tests/Feature/Companion/CompanionLadderPinTest.php`
- Read: `app/Services/Companion/CompanionResolver.php:122-139`, `:240-298`

**Interfaces:**
- Consumes: nothing
- Produces: a characterization test that Task 3 must leave green without editing.

- [ ] **Step 1: Write the pin**

Fifteen insights across all four kinds, at distinct controlled times, which walks the nine authored insight rungs and two tail rungs (at 12 and 15). The assertion is the **whole** unlock list, `unlocked_at` included — that is what makes it a pin rather than a smoke test.

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ladder, pinned whole, immediately before CompanionResolver::insightMoments()
 * changes shape to carry each insight's kind (F1 §6).
 *
 * The walk and the tail both index into that return value. This asserts the
 * entire resolved unlock list — names, kinds, variants, room objects and the
 * moment each was earned — so a refactor that reorders, drops or re-dates a
 * single insight cannot pass. Nothing here should ever need editing again: if
 * it goes red, the ladder changed, and that is the thing this file exists to
 * notice.
 */
class CompanionLadderPinTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->base = CarbonImmutable::parse('2026-01-01T09:00:00+00:00');
    }

    public function test_the_ladder_and_its_tail_are_unchanged(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 5; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $this->base->addDays($index),
            ]);
        }

        // Cycled rather than grouped, so the merge across the four sources is
        // exercised: a sort that is only correct within one kind fails here.
        $kinds = ['reflection', 'chain-correction', 'started-experiment', 'concluded-experiment'];

        $moments = [];

        for ($index = 0; $index < 15; $index++) {
            $at = $this->base->addDays(10)->addHours($index);
            $moments[] = $at;

            $this->insight($user, $kinds[$index % 4], $at);
        }

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertSame(5, $state->logCount);
        $this->assertSame(15, $state->insightCount);

        $shape = array_map(
            static fn (array $unlock): array => [
                $unlock['kind'],
                $unlock['name'],
                $unlock['variant'],
                $unlock['room_object'],
            ],
            $state->unlocks,
        );

        $this->assertSame([
            ['body', 'blob', null, null],
            ['body', 'legs', null, null],
            ['body', 'arms', null, null],
            ['item', 'shoes', null, null],
            ['ability', 'walk', null, null],
            ['item', 'scarf', null, null],
            ['ability', 'read', null, 'bookshelf'],
            ['item', 'hat', null, null],
            ['ability', 'wave', null, 'rug'],
            ['item', 'glasses', null, null],
            ['ability', 'jump', null, 'lamp'],
            ['item', 'scarf', 'coral', null],
            ['ability', 'carry', null, 'plant'],
            // The tail: a recolour every three further insights. No room
            // object on either of these — `room_every` is 3, so the first tail
            // object arrives on the third tail rung, at 18 insights.
            ['item', 'shoes', 'coral', null],
            ['item', 'scarf', 'moss', null],
        ], $shape);

        // Dated by the trigger that earned it, not by the request that noticed
        // it. The four log rungs first, then the insight rungs in order.
        $this->assertSame(
            $this->base->toIso8601String(),
            $state->unlocks[0]['unlocked_at'],
        );
        $this->assertSame(
            $this->base->addDays(4)->toIso8601String(),
            $state->unlocks[3]['unlocked_at'],
        );
        $this->assertSame(
            $moments[0]->toIso8601String(),
            $state->unlocks[4]['unlocked_at'],
        );
        $this->assertSame(
            $moments[8]->toIso8601String(),
            $state->unlocks[12]['unlocked_at'],
        );
        $this->assertSame(
            $moments[11]->toIso8601String(),
            $state->unlocks[13]['unlocked_at'],
        );
        $this->assertSame(
            $moments[14]->toIso8601String(),
            $state->unlocks[14]['unlocked_at'],
        );
    }

    /**
     * One insight of one kind, at one moment, built from the rows the resolver
     * actually reads rather than through the Actions that normally write them —
     * this is a pin of the resolver, and the timestamps have to be exact.
     */
    private function insight(User $user, string $kind, CarbonImmutable $at): void
    {
        match ($kind) {
            'reflection' => Summary::factory()->create([
                'user_id' => $user->id,
                'intention_id' => Intention::factory()->for($user),
                'scope' => Summary::SCOPE_INTENTION,
                'created_at' => $at,
                'updated_at' => $at,
            ]),

            'chain-correction' => Intention::factory()->for($user)->create([
                'metadata' => ['chain_revisions' => [
                    ['at' => $at->toIso8601String(), 'field' => 'craving'],
                ]],
            ]),

            // The parent has no verdict and no parent of its own, so it is the
            // loop being created rather than an insight — only the child counts.
            'started-experiment' => $this->startedExperiment($user, $at),

            'concluded-experiment' => Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                    'created_at' => $at->subMinute(),
                    'updated_at' => $at,
                ]),

            default => null,
        };
    }

    private function startedExperiment(User $user, CarbonImmutable $at): void
    {
        $loop = Intention::factory()->for($user)->create();

        $first = Strategy::factory()->for($loop)->create([
            'version' => 1,
            'created_at' => $at->subMinute(),
            'updated_at' => $at->subMinute(),
        ]);

        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'parent_strategy_id' => $first->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
```

- [ ] **Step 2: Run it and make it pass against the code as it stands**

Run: `php artisan test --compact tests/Feature/Companion/CompanionLadderPinTest.php`
Expected: PASS. It is characterizing existing behaviour, so it must be green **before** any refactor.

If it fails, the asserted shape is wrong, not the resolver — read the failure, correct the expectation to what the resolver actually does today, and re-run. Do not change `CompanionResolver` in this task.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Companion/CompanionLadderPinTest.php
git commit -m "test(companion): pin the whole ladder before the resolver changes shape"
```

---

### Task 3: `insightMoments()` carries the kind

F1 §6. The ladder only ever needed a count, so the kind was discarded. The XP table needs it.

**Files:**
- Modify: `app/Services/Companion/CompanionResolver.php`
- Test: `tests/Feature/Companion/CompanionResolverTest.php` (add cases), `tests/Feature/Companion/CompanionLadderPinTest.php` (must stay green, unedited)

**Interfaces:**
- Consumes: Task 2's pin
- Produces:
  - `CompanionResolver::INSIGHT_CONCLUDED_EXPERIMENT = 'concluded-experiment'`
  - `CompanionResolver::INSIGHT_STARTED_EXPERIMENT = 'started-experiment'`
  - `CompanionResolver::INSIGHT_CHAIN_CORRECTION = 'chain-correction'`
  - `CompanionResolver::INSIGHT_REFLECTION = 'reflection'`
  - `CompanionResolver::INSIGHT_KINDS` — `list<string>`, the four above
  - `public function logMoments(User $user): list<CarbonImmutable>`
  - `public function insightMoments(User $user): list<array{at: CarbonImmutable, kind: string}>`

Both become public because `CompanionWallet` (Task 5) prices the same two streams the ladder counts, and a second reader of the record is how the two quietly disagree.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Companion/CompanionResolverTest.php`:

```php
    /**
     * The ladder only ever needed a count, so the kind was thrown away. The XP
     * table prices each source differently (F1 §3), so it is carried now —
     * still merged into one stream, still sorted by time.
     */
    public function test_insight_moments_carry_which_kind_each_one_was(): void
    {
        $user = User::factory()->create();

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);
        Strategy::factory()->for($loop)->create(['verdict' => Strategy::VERDICT_WORKED]);
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);
        app(WriteReflection::class)->handle($loop, 'Still reads as a cue problem.');

        $moments = $this->resolver()->insightMoments($user);

        $this->assertCount(3, $moments);

        foreach ($moments as $moment) {
            $this->assertInstanceOf(CarbonImmutable::class, $moment['at']);
            $this->assertContains($moment['kind'], CompanionResolver::INSIGHT_KINDS);
        }

        $kinds = array_map(static fn (array $moment): string => $moment['kind'], $moments);

        sort($kinds);

        $this->assertSame([
            CompanionResolver::INSIGHT_CHAIN_CORRECTION,
            CompanionResolver::INSIGHT_CONCLUDED_EXPERIMENT,
            CompanionResolver::INSIGHT_REFLECTION,
        ], $kinds);
    }

    /** Merged into one stream and sorted by time, whatever kind each one is. */
    public function test_insight_moments_stay_in_time_order_across_kinds(): void
    {
        $user = User::factory()->create();
        $base = CarbonImmutable::parse('2026-02-01T09:00:00+00:00');

        $loop = Intention::factory()->for($user)->create();

        Summary::factory()->create([
            'user_id' => $user->id,
            'intention_id' => $loop->id,
            'scope' => Summary::SCOPE_INTENTION,
            'created_at' => $base->addHours(2),
            'updated_at' => $base->addHours(2),
        ]);

        Strategy::factory()->for($loop)->create([
            'verdict' => Strategy::VERDICT_WORKED,
            'verdict_note' => 'What the evidence showed.',
            'created_at' => $base,
            'updated_at' => $base,
        ]);

        $moments = $this->resolver()->insightMoments($user);

        $this->assertSame(
            [CompanionResolver::INSIGHT_CONCLUDED_EXPERIMENT, CompanionResolver::INSIGHT_REFLECTION],
            array_map(static fn (array $moment): string => $moment['kind'], $moments),
        );
    }
```

Add the imports the file does not already carry: `use App\Models\Summary;`, `use Carbon\CarbonImmutable;`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=insight_moments_carry`
Expected: FAIL — `insightMoments()` is private, so the call is an `Error: Call to private method`.

- [ ] **Step 3: Refactor the resolver**

Four changes, and nothing else in the file moves.

**(a)** Add the constants, at the top of the class:

```php
    /** An experiment reached a verdict — any verdict. */
    public const INSIGHT_CONCLUDED_EXPERIMENT = 'concluded-experiment';

    /** A new strategy version was started from an existing one. */
    public const INSIGHT_STARTED_EXPERIMENT = 'started-experiment';

    /** A loop's cue / craving / response / reward was corrected. */
    public const INSIGHT_CHAIN_CORRECTION = 'chain-correction';

    /** A reflection was written on a loop. */
    public const INSIGHT_REFLECTION = 'reflection';

    /** @var list<string> */
    public const INSIGHT_KINDS = [
        self::INSIGHT_CONCLUDED_EXPERIMENT,
        self::INSIGHT_STARTED_EXPERIMENT,
        self::INSIGHT_CHAIN_CORRECTION,
        self::INSIGHT_REFLECTION,
    ];
```

**(b)** Make both readers public and re-shape the insight one. The four private collectors keep returning `list<CarbonImmutable>` — tagging happens once, here, rather than four times:

```php
    /**
     * Every insight event this user has recorded, oldest first, each tagged
     * with which kind it was.
     *
     * The ladder walk only ever needed `count()`, which is why the kind used to
     * be discarded. The XP table prices each source differently (F1 §3), so it
     * is carried now — merged and re-sorted exactly as before, because a ladder
     * entry needs the Nth insight overall, not the Nth of one kind.
     *
     * Public because CompanionWallet prices the same stream the ladder counts,
     * and two readers of the record deriving it separately is how they come to
     * disagree.
     *
     * @return list<array{at: CarbonImmutable, kind: string}>
     */
    public function insightMoments(User $user): array
    {
        $moments = [
            ...$this->tag($this->concludedExperiments($user), self::INSIGHT_CONCLUDED_EXPERIMENT),
            ...$this->tag($this->startedExperiments($user), self::INSIGHT_STARTED_EXPERIMENT),
            ...$this->tag($this->chainCorrections($user), self::INSIGHT_CHAIN_CORRECTION),
            ...$this->tag($this->reflections($user), self::INSIGHT_REFLECTION),
        ];

        // By timestamp rather than by object: two moments in the same second
        // from different sources still need a stable, total order. usort has
        // been stable since PHP 8.0, so equal timestamps keep the source order
        // above — which is what the ladder pin asserts.
        usort(
            $moments,
            static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp(),
        );

        return $moments;
    }

    /**
     * @param  list<CarbonImmutable>  $moments
     * @return list<array{at: CarbonImmutable, kind: string}>
     */
    private function tag(array $moments, string $kind): array
    {
        return array_map(
            static fn (CarbonImmutable $at): array => ['at' => $at, 'kind' => $kind],
            $moments,
        );
    }
```

**(c)** `logMoments()` — change `private` to `public` and nothing else.

**(d)** Two index reads follow the shape. In `forUser()`, the line currently reading

```php
                'unlocked_at' => $moments[$at - 1]->toIso8601String(),
```

becomes

```php
                // `$moments` is either a list of CarbonImmutable (logs) or a
                // list of ['at' => …, 'kind' => …] (insights), so the moment is
                // reached through a helper rather than by indexing one shape.
                'unlocked_at' => $this->momentAt($moments, $at)->toIso8601String(),
```

and in `tailUnlocks()`:

```php
                'unlocked_at' => $insights[$at - 1]['at']->toIso8601String(),
```

with `tailUnlocks()`'s parameter docblock changed to
`@param  list<array{at: CarbonImmutable, kind: string}>  $insights`.

The helper, placed beside `tag()`:

```php
    /**
     * The Nth moment of a trigger stream, whichever of the two shapes it is.
     *
     * @param  list<CarbonImmutable>|list<array{at: CarbonImmutable, kind: string}>  $moments
     */
    private function momentAt(array $moments, int $at): CarbonImmutable
    {
        $moment = $moments[$at - 1];

        return $moment instanceof CarbonImmutable ? $moment : $moment['at'];
    }
```

- [ ] **Step 4: Run the new tests, the old ones, and the pin**

Run: `php artisan test --compact tests/Feature/Companion tests/Unit/Companion`
Expected: PASS, all of it — including `CompanionLadderPinTest`, unedited.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionResolver.php tests/Feature/Companion/CompanionResolverTest.php
git commit -m "refactor(companion): carry which kind each insight was"
```

---

### Task 4: The four tables

F1 §5. Store only what cannot be derived.

**Files:**
- Create: four migrations, four models, four factories
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Companion/CompanionStorageTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `App\Models\Companion` — `$name: ?string`, `$xp_spent: int`; `user()`, `skills()`, `items()`, `nodes()`; `displayName(): string`; `capacity(): int`; `held(): int`
  - `App\Models\CompanionSkill` — `$name: string`, `$learned_at: CarbonImmutable`; `companion()`
  - `App\Models\CompanionItem` — `$item: string`, `$quantity: int`; `companion()`
  - `App\Models\CompanionNode` — `$node: string`, `$available: int`; `companion()`
  - `User::companion(): HasOne<Companion, User>`

`Companion::capacity()`, `held()` and `room()` read `config('companion.bag')` and `config('companion.capacity')`, which Batch 2's Task 7 adds. Both reads default (`[]` and `5`), so in Batch 1 capacity is 5, nothing is held, and the methods are correct-but-trivial. That is deliberate — the shape is settled here so Task 7 is pure content.

- [ ] **Step 1: Generate the scaffolding**

```bash
php artisan make:model Companion -mf --no-interaction
php artisan make:model CompanionSkill -mf --no-interaction
php artisan make:model CompanionItem -mf --no-interaction
php artisan make:model CompanionNode -mf --no-interaction
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Companion/CompanionStorageTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\Companion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four tables F1 adds, and the one rule that governs all of them: store
 * only what cannot be derived. Counts, earned XP and the gift ladder are
 * absent here on purpose — they stay derived, so they cannot drift.
 */
class CompanionStorageTest extends TestCase
{
    use RefreshDatabase;

    /** Nothing is backfilled; the row appears the first time anything needs it. */
    public function test_a_companion_row_is_made_lazily_and_only_once(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->companion);

        $first = $user->companion()->firstOrCreate([]);
        $second = $user->fresh()->companion()->firstOrCreate([]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Companion::query()->count());
        $this->assertSame(0, $first->xp_spent);
        $this->assertNull($first->name);
    }

    /** One companion per user, enforced by the database rather than by care. */
    public function test_a_user_cannot_have_two_companions(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([]);

        $this->expectException(QueryException::class);

        Companion::factory()->create(['user_id' => $user->id]);
    }

    /** Null reads as "Blob", so nothing changes until someone renames. */
    public function test_an_unnamed_companion_is_called_blob(): void
    {
        $companion = Companion::factory()->create(['name' => null]);

        $this->assertSame('Blob', $companion->displayName());

        $companion->update(['name' => 'Pebble']);

        $this->assertSame('Pebble', $companion->displayName());
    }

    /** A skill is learned once. The unique index is what makes that true. */
    public function test_a_skill_is_learned_at_most_once(): void
    {
        $companion = Companion::factory()->create();

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->expectException(QueryException::class);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
    }

    /** One stack per item, so a quantity is incremented rather than duplicated. */
    public function test_the_bag_holds_one_stack_per_item(): void
    {
        $companion = Companion::factory()->create();

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $this->expectException(QueryException::class);

        $companion->items()->create(['item' => 'fibre', 'quantity' => 1]);
    }

    /** One row per node, likewise. */
    public function test_the_world_holds_one_row_per_node(): void
    {
        $companion = Companion::factory()->create();

        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        $this->expectException(QueryException::class);

        $companion->nodes()->create(['node' => 'reeds', 'available' => 1]);
    }

    /** Deleting the account takes the whole clearing with it. */
    public function test_everything_cascades_when_the_user_goes(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        $user->delete();

        $this->assertDatabaseCount('companions', 0);
        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertDatabaseCount('companion_items', 0);
        $this->assertDatabaseCount('companion_nodes', 0);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Companion/CompanionStorageTest.php`
Expected: FAIL — `Call to undefined method App\Models\User::companion()`.

- [ ] **Step 4: Write the migrations**

`*_create_companions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the record cannot say about Blob: what it is called, and how much of
 * its earned XP has been spent.
 *
 * `xp_spent` rather than a balance. Earned XP is recomputed from the record on
 * every read, exactly as the ladder's counts are, so the balance is
 * `max(0, earned - spent)` and there is no stored number that can drift out of
 * step with the record it claims to describe. Deleting history lowers `earned`
 * and therefore the balance; the clamp stops it going negative, and skills
 * already learned are never revoked. (F1 §3)
 *
 * The row is created lazily on first need, so nothing has to be backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Null reads as "Blob". Nothing changes until someone renames.
            $table->string('name')->nullable();

            $table->unsignedInteger('xp_spent')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companions');
    }
};
```

`*_create_companion_skills_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Blob chose to learn, and when.
 *
 * `learned_at` is not decoration: it is the epoch for this skill's node. Stock
 * accrues only from the moment the skill was learned, which is what stops a
 * long-established account arriving to a clearing holding a thousand units.
 * (F1 §4)
 *
 * Append-only. A skill is never revoked, not even when deleting history takes
 * the balance below what was spent on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->timestamp('learned_at');

            $table->timestamps();

            $table->unique(['companion_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_skills');
    }
};
```

`*_create_companion_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bag: one stack per item.
 *
 * `item` is a name, and its category (`material`, `container`, and in later
 * phases `tool` and `consumable`) is looked up in config rather than stored
 * beside it. A category written into a row is a second copy of something config
 * already decides, and the two would eventually disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('item');
            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->unique(['companion_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_items');
    }
};
```

`*_create_companion_nodes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is standing in the clearing waiting to be picked up.
 *
 * `available` is the one stored value in this feature that could in principle
 * be derived, and it is stored anyway. The exception is deliberate: the world
 * is a place, not a summary of the record. Harvesting mutates it, and replaying
 * every log to reconstruct it would buy purity at the cost of a write path that
 * has to agree with a read path. (F1 §5)
 *
 * A row also records that Blob has MET this node — the encounter that reveals
 * the skill (F1 §2) writes one at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('node');
            $table->unsignedInteger('available')->default(0);

            $table->timestamps();

            $table->unique(['companion_id', 'node']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_nodes');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Models/Companion.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The part of Blob that is chosen rather than earned.
 *
 * Everything the record can say — what Blob is, what it wears, what it can do,
 * where it lives — is still derived by {@see \App\Services\Companion\CompanionResolver}
 * on every read. This row holds only the two things the record cannot know:
 * what Blob is called, and how much of its XP has been spent.
 *
 * `user_id` is deliberately absent from #[Fillable]. Create through the
 * relation — `$user->companion()->firstOrCreate([])` — which sets the key
 * itself, the same way CompanionRemark is written.
 */
#[Fillable([
    'name',
    'xp_spent',
])]
class Companion extends Model
{
    /** @use HasFactory<CompanionFactory> */
    use HasFactory;

    /** What Blob is called when nobody has named it. */
    public const DEFAULT_NAME = 'Blob';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CompanionSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(CompanionSkill::class);
    }

    /** @return HasMany<CompanionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CompanionItem::class);
    }

    /** @return HasMany<CompanionNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(CompanionNode::class);
    }

    /**
     * What to call Blob in a sentence. Null reads as "Blob", so an account that
     * never renames sees exactly what it saw before the column existed.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name === '' ? self::DEFAULT_NAME : $name;
    }

    /**
     * How much Blob can hold: its hands, plus every container it has built.
     *
     * Read from config rather than stored, because it is a function of the bag's
     * contents and config's numbers — a stored capacity would be a third thing
     * that has to agree with the other two.
     */
    public function capacity(): int
    {
        $base = (int) config('companion.capacity.base', 5);
        $items = (array) config('companion.bag', []);

        $added = $this->items
            ->sum(fn (CompanionItem $held): int => (int) ($items[$held->item]['capacity'] ?? 0) * $held->quantity);

        return $base + (int) $added;
    }

    /**
     * How much of that capacity is taken up.
     *
     * Only the categories config calls carried count. A container does not
     * occupy the space it creates, and in later phases a tool is on the belt.
     */
    public function held(): int
    {
        $items = (array) config('companion.bag', []);
        $carried = (array) config('companion.capacity.carried', []);

        return (int) $this->items->sum(
            fn (CompanionItem $held): int => in_array($items[$held->item]['category'] ?? '', $carried, true)
                ? $held->quantity
                : 0,
        );
    }

    /** What is left before the bag is full. Never negative. */
    public function room(): int
    {
        return max(0, $this->capacity() - $this->held());
    }
}
```

> **Naming note for the implementer:** `App\Models\Companion` and the namespace `App\Services\Companion` share a leaf name. Inside `App\Services\Companion\*`, an unqualified `Companion` resolves to `App\Services\Companion\Companion`, which does not exist — so those files **must** carry an explicit `use App\Models\Companion;`, which then wins. This is ordinary PHP; it just looks wrong at a glance.

`app/Models/CompanionSkill.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanionSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing Blob chose to learn.
 *
 * Append-only and never revoked — see the `companions` migration for why a
 * balance that falls below what was spent takes nothing away.
 */
#[Fillable([
    'name',
    'learned_at',
])]
class CompanionSkill extends Model
{
    /** @use HasFactory<CompanionSkillFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['learned_at' => 'immutable_datetime'];
    }
}
```

`app/Models/CompanionItem.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stack in the bag. Its category is config's to say, not this row's. */
#[Fillable([
    'item',
    'quantity',
])]
class CompanionItem extends Model
{
    /** @use HasFactory<CompanionItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
```

`app/Models/CompanionNode.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanionNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One node in the clearing, and what is standing there.
 *
 * The row existing at all means Blob has met this node — that is the encounter
 * which reveals its skill (F1 §2). `available` is what has accrued since the
 * skill was learned, and nothing about it ever expires.
 */
#[Fillable([
    'node',
    'available',
])]
class CompanionNode extends Model
{
    /** @use HasFactory<CompanionNodeFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
```

- [ ] **Step 6: Write the factories**

`database/factories/CompanionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Companion>
 */
class CompanionFactory extends Factory
{
    protected $model = Companion::class;

    /**
     * Unnamed and unspent by default — a companion that has chosen nothing yet,
     * which is where every account starts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => null,
            'xp_spent' => 0,
        ];
    }

    /** A companion somebody has renamed. */
    public function named(string $name = 'Pebble'): static
    {
        return $this->state(['name' => $name]);
    }
}
```

`database/factories/CompanionSkillFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionSkill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionSkill>
 */
class CompanionSkillFactory extends Factory
{
    protected $model = CompanionSkill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'name' => 'gather-fibre',
            'learned_at' => now(),
        ];
    }
}
```

`database/factories/CompanionItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionItem>
 */
class CompanionItemFactory extends Factory
{
    protected $model = CompanionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'item' => 'fibre',
            'quantity' => 1,
        ];
    }
}
```

`database/factories/CompanionNodeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionNode>
 */
class CompanionNodeFactory extends Factory
{
    protected $model = CompanionNode::class;

    /**
     * Met but empty, which is what an encounter leaves behind before the skill
     * is learned.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'node' => 'reeds',
            'available' => 0,
        ];
    }
}
```

- [ ] **Step 7: Add the relation to `User`**

Beside `companionRemarks()` in `app/Models/User.php`:

```php
    /**
     * The part of Blob that is chosen rather than derived — the name and what
     * has been spent. Absent until the first choice is made; see
     * {@see \App\Models\Companion}.
     *
     * @return HasOne<Companion, $this>
     */
    public function companion(): HasOne
    {
        return $this->hasOne(Companion::class);
    }
```

Add `use Illuminate\Database\Eloquent\Relations\HasOne;` to the imports.

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/CompanionStorageTest.php`
Expected: PASS, 7 tests.

If the cascade test fails, check `RefreshDatabase` has foreign keys on — SQLite needs `PRAGMA foreign_keys=ON`, which Laravel's SQLite connector sets by default. If it is off in `config/database.php`, assert the cascade in a way that does not depend on it and note the gap rather than changing the config.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models database/factories tests/Feature/Companion/CompanionStorageTest.php
git commit -m "feat(companion): store what the record cannot say"
```

---

### Task 5: The XP wallet

F1 §3. Earned is derived, spent is stored, the balance is clamped.

**Files:**
- Create: `app/Services/Companion/CompanionWallet.php`
- Modify: `config/companion.php`
- Test: `tests/Feature/Companion/CompanionWalletTest.php`

**Interfaces:**
- Consumes: `CompanionResolver::logMoments()`, `CompanionResolver::insightMoments()`, `CompanionResolver::INSIGHT_*` (Task 3); `User::companion()` (Task 4)
- Produces:
  - `CompanionWallet::earnedFor(User $user): int`
  - `CompanionWallet::spentFor(User $user): int`
  - `CompanionWallet::balanceFor(User $user): int`

- [ ] **Step 1: Add the rates to config**

Append to `config/companion.php`, before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | XP
    |--------------------------------------------------------------------------
    |
    | What the record pays. Earned XP is recomputed from the record on every
    | read; only the spending is stored. See F1 §3.
    |
    | A `failed` outcome pays exactly what a `completed` one pays, and a
    | `skipped` one pays the same again. Paying for completions would build an
    | incentive to hide failures, choose easy actions, and feel worst exactly
    | when the data matters most. Nothing in this file may ever branch on the
    | outcome.
    |
    | FIXED RATIO, NEVER VARIABLE. Slot-machine scheduling is what makes games
    | compulsive; every reward here is predictable and transparent.
    |
    */

    'xp' => [

        /*
        | The nth outcome recorded in one day, grouped by `logged_at` — when the
        | user sat down and told the truth — rather than by the occasion. A
        | catch-up session logging seven days at once therefore tapers as one
        | day and pays 10, not 21. That is correct: the taper rewards showing
        | up, and you showed up once.
        |
        | The last value is the FLOOR and repeats forever. Nothing recorded ever
        | goes unpaid, so there is no point at which logging stops being worth
        | it — the taper is there so breadth does not out-earn depth, not so
        | that a tenth log is worthless.
        */
        'outcome' => [3, 2, 1],

        /*
        | The four insight sources, keyed by CompanionResolver's INSIGHT_*
        | constants. OUTSIDE the taper: they are rarer by nature, and
        | rate-limiting them would be double-counting.
        */
        'insight' => [
            'concluded-experiment' => 15,
            'started-experiment' => 10,
            'chain-correction' => 8,
            'reflection' => 5,
        ],

    ],
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Companion/CompanionWalletTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use App\Services\Companion\CompanionWallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the record pays, and what spending does to it.
 *
 * Earned is derived on every read, exactly as the ladder's counts are; only
 * the spending is stored. So there is no number here that can silently drift —
 * the worst case is a balance that falls, which takes nothing away.
 */
class CompanionWalletTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->base = CarbonImmutable::parse('2026-03-02T09:00:00+00:00');
    }

    private function wallet(): CompanionWallet
    {
        return app(CompanionWallet::class);
    }

    private function log(User $user, CarbonImmutable $at, string $outcome = ActionLog::OUTCOME_COMPLETED): void
    {
        ActionLog::factory()->create([
            'user_id' => $user->id,
            'outcome' => $outcome,
            'reason' => $outcome === ActionLog::OUTCOME_FAILED ? 'Never came up' : null,
            'logged_at' => $at,
        ]);
    }

    public function test_an_empty_record_has_earned_nothing(): void
    {
        $this->assertSame(0, $this->wallet()->earnedFor(User::factory()->create()));
    }

    /** 3, then 2, then 1 and 1 — the floor repeats and nothing goes unpaid. */
    public function test_outcomes_taper_within_a_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base);
        $this->assertSame(3, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHour());
        $this->assertSame(5, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHours(2));
        $this->assertSame(6, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHours(3));
        $this->assertSame(7, $this->wallet()->earnedFor($user));
    }

    /** A new day resets the taper. */
    public function test_the_taper_restarts_the_next_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base);
        $this->log($user, $this->base->addDay());

        $this->assertSame(6, $this->wallet()->earnedFor($user));
    }

    /**
     * The catch-up case from F1 §3, stated as a number: seven days logged in
     * one sitting is one day of showing up, and pays 3+2+1+1+1+1+1.
     */
    public function test_a_catch_up_session_tapers_as_one_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 7; $index++) {
            $this->log($user, $this->base->addMinutes($index));
        }

        $this->assertSame(10, $this->wallet()->earnedFor($user));
    }

    /** The day is the user's, not the server's. */
    public function test_the_day_is_read_in_the_users_own_timezone(): void
    {
        $late = User::factory()->create(['timezone' => 'Pacific/Auckland']);

        // 23:00 and 23:30 UTC on the 2nd are both midday on the 3rd in
        // Auckland: one day there, two days in UTC.
        $this->log($late, $this->base->setTime(23, 0));
        $this->log($late, $this->base->setTime(23, 30));

        $this->assertSame(5, $this->wallet()->earnedFor($late));
    }

    /**
     * The therapeutic invariant. Paying differently for a failure would build
     * an incentive to hide failures and feel worst when the data matters most.
     */
    public function test_a_failed_outcome_pays_exactly_what_a_completed_one_pays(): void
    {
        $completions = User::factory()->create(['timezone' => 'UTC']);
        $failures = User::factory()->create(['timezone' => 'UTC']);
        $skips = User::factory()->create(['timezone' => 'UTC']);

        foreach ([
            [$completions, ActionLog::OUTCOME_COMPLETED],
            [$failures, ActionLog::OUTCOME_FAILED],
            [$skips, ActionLog::OUTCOME_SKIPPED],
        ] as [$user, $outcome]) {
            for ($index = 0; $index < 4; $index++) {
                $this->log($user, $this->base->addDays($index), $outcome);
            }
        }

        $this->assertSame(12, $this->wallet()->earnedFor($completions));
        $this->assertSame(
            $this->wallet()->earnedFor($completions),
            $this->wallet()->earnedFor($failures),
        );
        $this->assertSame(
            $this->wallet()->earnedFor($completions),
            $this->wallet()->earnedFor($skips),
        );
    }

    /** Each insight kind pays its configured value, outside the taper. */
    public function test_each_insight_kind_pays_its_own_rate(): void
    {
        $reflections = User::factory()->create();
        $loop = Intention::factory()->for($reflections)->create();

        // Three reflections in one hour: no taper applies, so 15, not 5+5+5
        // tapered down to something else.
        for ($index = 0; $index < 3; $index++) {
            Summary::factory()->create([
                'user_id' => $reflections->id,
                'intention_id' => $loop->id,
                'scope' => Summary::SCOPE_INTENTION,
                'created_at' => $this->base->addMinutes($index),
                'updated_at' => $this->base->addMinutes($index),
            ]);
        }

        $this->assertSame(15, $this->wallet()->earnedFor($reflections));

        $concluded = User::factory()->create();
        Strategy::factory()
            ->for(Intention::factory()->for($concluded))
            ->create(['verdict' => Strategy::VERDICT_WORKED, 'verdict_note' => 'What the evidence showed.']);

        $this->assertSame(15, $this->wallet()->earnedFor($concluded));

        $corrected = User::factory()->create();
        Intention::factory()->for($corrected)->create([
            'metadata' => ['chain_revisions' => [['at' => $this->base->toIso8601String(), 'field' => 'cue']]],
        ]);

        $this->assertSame(8, $this->wallet()->earnedFor($corrected));

        $started = User::factory()->create();
        $theirLoop = Intention::factory()->for($started)->create();
        $first = Strategy::factory()->for($theirLoop)->create(['version' => 1]);
        Strategy::factory()->for($theirLoop)->create([
            'version' => 2,
            'parent_strategy_id' => $first->id,
        ]);

        $this->assertSame(10, $this->wallet()->earnedFor($started));
    }

    /** Spending is the only stored half, and the balance is the difference. */
    public function test_the_balance_is_earned_less_spent(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 4; $index++) {
            $this->log($user, $this->base->addDays($index));
        }

        $this->assertSame(12, $this->wallet()->balanceFor($user));

        $user->companion()->firstOrCreate([])->update(['xp_spent' => 20]);

        $this->assertSame(0, $this->wallet()->balanceFor($user));
        $this->assertSame(20, $this->wallet()->spentFor($user));
        // Earned is untouched by spending: the two are separate readings.
        $this->assertSame(12, $this->wallet()->earnedFor($user));
    }

    /**
     * Deleting history lowers earned and therefore the balance. It is clamped
     * at zero, and — the part that matters — it takes nothing away: the skill
     * row is still there.
     */
    public function test_the_balance_clamps_at_zero_and_learned_skills_survive(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 4; $index++) {
            $this->log($user, $this->base->addDays($index));
        }

        $companion = $user->companion()->firstOrCreate([]);
        $companion->update(['xp_spent' => 12]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->assertSame(0, $this->wallet()->balanceFor($user));

        $user->actionLogs()->delete();

        $this->assertSame(0, $this->wallet()->earnedFor($user));
        $this->assertSame(0, $this->wallet()->balanceFor($user));
        $this->assertSame(1, $companion->fresh()->skills()->count());
    }

    /** A user with no companion row has spent nothing, rather than erroring. */
    public function test_a_user_with_no_companion_row_has_spent_nothing(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $this->wallet()->spentFor($user));
        $this->assertSame(0, Companion::query()->count());
    }

    /** One person's record never pays another's Blob. */
    public function test_another_users_record_pays_nothing_here(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 5; $index++) {
            $this->log($stranger, $this->base->addDays($index));
        }

        $this->assertSame(0, $this->wallet()->earnedFor($user));
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Companion/CompanionWalletTest.php`
Expected: FAIL — `Target class [App\Services\Companion\CompanionWallet] does not exist.`

- [ ] **Step 4: Write the wallet**

```php
<?php

namespace App\Services\Companion;

use App\Models\Companion;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What the record has paid, what has been spent, and what is left.
 *
 * The property worth protecting from the pre-F1 design is that nothing can
 * silently drift, and it survives here because only the spending is stored:
 *
 *     balance = max(0, earned − spent)
 *               ^^^^^^        ^^^^^
 *               derived       companions.xp_spent
 *
 * Earned is recomputed from the record on every read, exactly as the ladder's
 * counts are. Deleting history lowers it and therefore the balance — but skills
 * already learned are stored and are never revoked, and the clamp stops the
 * balance going negative. The worst case is being unable to buy until more is
 * recorded, which takes nothing away.
 *
 * Reads the record through {@see CompanionResolver} rather than querying it
 * again: two readers deriving the same two streams separately is how they come
 * to disagree about what happened.
 *
 * NOTHING HERE MAY EVER BRANCH ON AN OUTCOME. A `failed` outcome pays exactly
 * what a `completed` one pays; see config('companion.xp') for why.
 */
final readonly class CompanionWallet
{
    public function __construct(private CompanionResolver $resolver) {}

    /** What the record has paid, over the whole of it. */
    public function earnedFor(User $user): int
    {
        return $this->outcomeXp($user) + $this->insightXp($user);
    }

    /** What has been spent. Zero before there is anything to spend it with. */
    public function spentFor(User $user): int
    {
        return (int) Companion::query()
            ->where('user_id', $user->id)
            ->value('xp_spent');
    }

    /** What is left. Clamped, so a deleted record cannot owe anything. */
    public function balanceFor(User $user): int
    {
        return max(0, $this->earnedFor($user) - $this->spentFor($user));
    }

    /**
     * Outcomes, tapered within each day.
     *
     * The day is the user's own, not the server's: "the first outcome of the
     * day" is a claim about when they sat down, and a user in Auckland logging
     * at nine in the evening has not started a second day.
     */
    private function outcomeXp(User $user): int
    {
        /** @var list<int> $taper */
        $taper = array_values(array_map(
            static fn ($value): int => (int) $value,
            (array) config('companion.xp.outcome', [1]),
        ));

        if ($taper === []) {
            return 0;
        }

        $floor = count($taper) - 1;
        $timezone = $user->timezone ?? (string) config('app.timezone');

        $perDay = [];

        foreach ($this->resolver->logMoments($user) as $moment) {
            $day = $moment->setTimezone($timezone)->toDateString();

            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $total = 0;

        foreach ($perDay as $count) {
            for ($index = 0; $index < $count; $index++) {
                $total += $taper[min($index, $floor)];
            }
        }

        return $total;
    }

    /**
     * Insights, at a flat rate per kind and outside the taper — they are rarer
     * by nature, and rate-limiting them would be double-counting.
     *
     * A kind config does not price pays nothing rather than throwing: an
     * unpriced source is a config omission, and the record is not the place to
     * discover it.
     */
    private function insightXp(User $user): int
    {
        $rates = (array) config('companion.xp.insight', []);

        $total = 0;

        foreach ($this->resolver->insightMoments($user) as $moment) {
            $total += (int) ($rates[$moment['kind']] ?? 0);
        }

        return $total;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/CompanionWalletTest.php`
Expected: PASS, 11 tests.

Note on `CarbonImmutable::setTimezone` — it returns a new instance and does not mutate, which is why the grouping is safe to do inline.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionWallet.php config/companion.php tests/Feature/Companion/CompanionWalletTest.php
git commit -m "feat(companion): the record pays, and only the spending is stored"
```

---

### Task 6: Buying a skill

The only writer of `xp_spent`.

**Files:**
- Create: `app/Actions/LearnSkill.php`, `app/Services/Companion/CompanionEconomyException.php`
- Modify: `config/companion.php`
- Test: `tests/Feature/Companion/LearnSkillTest.php`

**Interfaces:**
- Consumes: `CompanionWallet::balanceFor()` (Task 5), `User::companion()` (Task 4)
- Produces:
  - `LearnSkill::handle(User $user, string $skill): CompanionSkill`
  - `CompanionEconomyException::cannotAfford(string $thing, int $price, int $balance): self`
  - `config('companion.skills')` — `array<string, array{price: int, node: string, label: string}>`

- [ ] **Step 1: Add the skills to config**

Append to `config/companion.php`, after the `xp` block:

```php
    /*
    |--------------------------------------------------------------------------
    | Skills
    |--------------------------------------------------------------------------
    |
    | What XP buys. A skill unlocks an interaction with one node; the node
    | yields a material; the material builds a thing. Two layers, and they pace
    | each other — the record controls what Blob CAN do, the world controls how
    | fast. (arc §2)
    |
    | A skill is listed to the user only once Blob has MET its node (F1 §2), so
    | this map is never rendered whole and its length is never shown. `label` is
    | what the row says; `price` is what it costs; `node` is what it unlocks.
    |
    | F1 ships two. F2 widens this without touching any of the code that reads
    | it.
    |
    */

    'skills' => [
        'gather-fibre' => ['price' => 20, 'node' => 'reeds', 'label' => 'gather fibre'],
        'gather-wood' => ['price' => 20, 'node' => 'deadfall', 'label' => 'gather wood'],
    ],
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Companion/LearnSkillTest.php`:

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\LearnSkill;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionWallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Spending, which is the only thing about Blob the user chooses.
 *
 * The gift ladder is untouched by any of this — it keeps handing out the body
 * and the wearables from the record, unchosen and unpurchasable. This is the
 * other track. (arc §2)
 */
class LearnSkillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    /** Enough outcomes, spread over enough days, to clear a 20 xp price. */
    private function richUser(int $days = 8): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $base = CarbonImmutable::parse('2026-04-01T09:00:00+00:00');

        for ($index = 0; $index < $days; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        return $user;
    }

    public function test_learning_a_skill_debits_the_balance_and_writes_the_row(): void
    {
        $user = $this->richUser();

        $this->assertSame(24, app(CompanionWallet::class)->balanceFor($user));

        $skill = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertSame('gather-fibre', $skill->name);
        $this->assertNotNull($skill->learned_at);
        $this->assertSame(20, $user->companion()->first()->xp_spent);
        $this->assertSame(4, app(CompanionWallet::class)->balanceFor($user));
    }

    /** The row is the epoch for the node's stock, so the moment matters. */
    public function test_the_skill_records_when_it_was_learned(): void
    {
        $user = $this->richUser();

        $skill = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertTrue(now()->equalTo($skill->learned_at));
    }

    public function test_a_short_balance_is_refused_and_nothing_is_written(): void
    {
        $user = $this->richUser(2);

        $this->assertSame(6, app(CompanionWallet::class)->balanceFor($user));

        try {
            app(LearnSkill::class)->handle($user, 'gather-fibre');
            $this->fail('A short balance should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('gather-fibre', $exception->getMessage());
        }

        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertSame(6, app(CompanionWallet::class)->balanceFor($user));
        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }

    /**
     * Learning the same skill twice is not a second purchase. The unique index
     * would refuse it anyway; this makes the refusal quiet and free rather than
     * an error, because a double-submitted click is not a user mistake.
     */
    public function test_learning_the_same_skill_again_costs_nothing(): void
    {
        $user = $this->richUser();

        $first = app(LearnSkill::class)->handle($user, 'gather-fibre');
        $second = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertTrue($first->is($second));
        $this->assertSame(20, (int) $user->companion()->value('xp_spent'));
        $this->assertDatabaseCount('companion_skills', 1);
    }

    /** Both forks are reachable, and buying one never blocks the other. */
    public function test_both_skills_can_be_bought_when_the_record_affords_both(): void
    {
        $user = $this->richUser(14);

        app(LearnSkill::class)->handle($user, 'gather-fibre');
        app(LearnSkill::class)->handle($user, 'gather-wood');

        $this->assertSame(40, (int) $user->companion()->value('xp_spent'));
        $this->assertSame(
            ['gather-fibre', 'gather-wood'],
            $user->companion()->first()->skills()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_a_skill_nobody_authored_is_rejected(): void
    {
        $user = $this->richUser();

        $this->expectException(InvalidArgumentException::class);

        app(LearnSkill::class)->handle($user, 'gather-moonlight');
    }

    /** The debit and the row are one write or neither. */
    public function test_the_debit_and_the_row_are_written_together(): void
    {
        $user = $this->richUser();

        DB::beginTransaction();
        app(LearnSkill::class)->handle($user, 'gather-fibre');
        DB::rollBack();

        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Companion/LearnSkillTest.php`
Expected: FAIL — `Target class [App\Actions\LearnSkill] does not exist.`

- [ ] **Step 4: Write the exception**

`app/Services/Companion/CompanionEconomyException.php`:

```php
<?php

namespace App\Services\Companion;

use RuntimeException;

/**
 * A refusal the economy makes.
 *
 * All three cases are the same shape: something was asked for that the current
 * state cannot pay for. None of them destroys anything and none of them is a
 * failure on the user's part — the bag being full is a reason to build the next
 * container, not a mistake, and the words say so.
 */
class CompanionEconomyException extends RuntimeException
{
    public static function cannotAfford(string $thing, int $price, int $balance): self
    {
        return new self("[{$thing}] costs {$price} xp and the balance is {$balance}.");
    }

    public static function bagIsFull(string $node): self
    {
        return new self("There is no room left in the bag for anything from [{$node}].");
    }

    /** @param  array<string, int>  $missing */
    public static function missingMaterials(string $thing, array $missing): self
    {
        $shortfall = implode(', ', array_map(
            static fn (string $item, int $count): string => "{$count} {$item}",
            array_keys($missing),
            array_values($missing),
        ));

        return new self("[{$thing}] still needs {$shortfall}.");
    }
}
```

- [ ] **Step 5: Write the action**

`app/Actions/LearnSkill.php`:

```php
<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionSkill;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionWallet;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Buys one skill, which is the one consequential choice in the whole feature:
 * two identical records can produce different Blobs, permanently, because of
 * what was bought first.
 *
 * The ONLY writer of `companions.xp_spent`. Everything about the balance is
 * derived from the record and this column; a second writer would be a second
 * opinion.
 *
 * Learning a skill Blob already has is a no-op rather than an error. A
 * double-submitted click is not a user mistake, and the unique index on
 * (companion_id, name) means the alternative is a QueryException reaching a
 * controller.
 */
final readonly class LearnSkill
{
    public function __construct(private CompanionWallet $wallet) {}

    /**
     * @throws InvalidArgumentException when no such skill is authored.
     * @throws CompanionEconomyException when the balance is short.
     */
    public function handle(User $user, string $skill): CompanionSkill
    {
        /** @var array<string, array{price: int, node: string, label: string}> $authored */
        $authored = (array) config('companion.skills', []);

        if (! array_key_exists($skill, $authored)) {
            throw new InvalidArgumentException("[{$skill}] is not a skill Blob can learn.");
        }

        $price = (int) ($authored[$skill]['price'] ?? 0);

        return DB::transaction(function () use ($user, $skill, $price): CompanionSkill {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $already = $companion->skills()->where('name', $skill)->first();

            if ($already instanceof CompanionSkill) {
                return $already;
            }

            $balance = $this->wallet->balanceFor($user);

            if ($balance < $price) {
                throw CompanionEconomyException::cannotAfford($skill, $price, $balance);
            }

            $companion->increment('xp_spent', $price);

            return $companion->skills()->create([
                'name' => $skill,
                // The epoch for this skill's node: stock accrues only from here.
                'learned_at' => Date::now(),
            ]);
        });
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/LearnSkillTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Run the whole companion suite**

Run: `php artisan test --compact tests/Feature/Companion tests/Unit/Companion tests/Feature/Mcp`
Expected: PASS. `CompanionLadderPinTest` and `CompanionLadderTest` in particular — the gift ladder must be exactly where Batch 1 found it.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/LearnSkill.php app/Services/Companion/CompanionEconomyException.php config/companion.php tests/Feature/Companion/LearnSkillTest.php
git commit -m "feat(companion): spend the record on a skill"
```

---

**■ STOP. Batch 1 review.**

What to look at: the layout mockup and its recommendation; that `xp_spent` has exactly one writer; that `CompanionLadderPinTest` never needed editing; that nothing in `CompanionWallet` branches on an outcome.

---

# BATCH 2 — the world

Still server-side. The clearing fills up, and things come out of it.

---

### Task 7: The world's content, in config

**Files:**
- Modify: `config/companion.php`
- Test: `tests/Unit/Companion/CompanionContentTest.php`

**Interfaces:**
- Produces:
  - `config('companion.nodes')` — `array<string, array{skill: string, yields: string, label: string}>`
  - `config('companion.bag')` — `array<string, array{category: string, label: string, recipe?: array<string,int>, capacity?: int}>`
  - `config('companion.capacity')` — `array{base: int, carried: list<string>}`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Companion/CompanionContentTest.php` — a pure `PHPUnit\Framework\TestCase` reading `config/companion.php` directly, in the same style as `CompanionLadderTest`:

```php
<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

/**
 * F1's content, checked for the ways config can lie.
 *
 * The four-type wearable cap is NOT policed here — it belongs to `item_types`
 * and stays guarded by CompanionLadderTest. These categories are uncapped on
 * purpose (arc §5); the cap was always about clutter on a 64×64 sprite, and
 * nothing here is drawn on Blob.
 */
class CompanionContentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require __DIR__.'/../../../config/companion.php';
    }

    public function test_every_skill_names_a_node_that_exists(): void
    {
        $config = $this->config();

        foreach ($config['skills'] as $name => $skill) {
            $this->assertArrayHasKey('price', $skill, "{$name} has no price");
            $this->assertGreaterThan(0, $skill['price'], "{$name} is free");
            $this->assertArrayHasKey($skill['node'], $config['nodes'], "{$name} unlocks a node that does not exist");
        }
    }

    public function test_every_node_names_a_skill_and_a_material_that_exist(): void
    {
        $config = $this->config();

        foreach ($config['nodes'] as $name => $node) {
            $this->assertArrayHasKey($node['skill'], $config['skills'], "{$name} needs a skill that does not exist");
            $this->assertArrayHasKey($node['yields'], $config['bag'], "{$name} yields an item that does not exist");
            $this->assertSame('material', $config['bag'][$node['yields']]['category']);
        }
    }

    public function test_every_recipe_asks_for_items_that_exist(): void
    {
        $config = $this->config();

        foreach ($config['bag'] as $name => $item) {
            foreach ($item['recipe'] ?? [] as $ingredient => $count) {
                $this->assertArrayHasKey($ingredient, $config['bag'], "{$name} needs an item that does not exist");
                $this->assertGreaterThan(0, $count);
            }
        }
    }

    /**
     * The fork is real on day one: each of F1's two materials builds exactly one
     * container, so the first 20 xp spent decides which one is standing in the
     * clearing. (F1 §1)
     */
    public function test_each_material_builds_exactly_one_container(): void
    {
        $config = $this->config();

        $materials = array_keys(array_filter(
            $config['bag'],
            static fn (array $item): bool => $item['category'] === 'material',
        ));

        foreach ($materials as $material) {
            $built = array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container'
                    && array_key_exists($material, $item['recipe'] ?? []),
            );

            $this->assertCount(1, $built, "{$material} should build exactly one container");
        }
    }

    public function test_every_container_raises_capacity(): void
    {
        foreach ($this->config()['bag'] as $name => $item) {
            if ($item['category'] !== 'container') {
                continue;
            }

            $this->assertGreaterThan(0, $item['capacity'] ?? 0, "{$name} adds no capacity");
        }
    }

    /** A container does not occupy the space it creates. */
    public function test_only_carried_categories_take_up_room(): void
    {
        $config = $this->config();

        $this->assertSame(['material', 'consumable'], $config['capacity']['carried']);
        $this->assertSame(5, $config['capacity']['base']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Unit/Companion/CompanionContentTest.php`
Expected: FAIL — `Undefined array key "nodes"`.

- [ ] **Step 3: Add the content**

Append to `config/companion.php`, after `skills`:

```php
    /*
    |--------------------------------------------------------------------------
    | Nodes
    |--------------------------------------------------------------------------
    |
    | What stands in the clearing. Both are VISIBLE FROM THE START and unusable
    | without their skill — clicking one before you have it does not fail and
    | does not show a lock. Blob turns it over and puts it down again, and that
    | encounter is what puts the skill in the list. (F1 §2)
    |
    | Each outcome recorded adds one unit to each node whose skill is learned,
    | and only from the moment it was learned. Stock is uncapped and nothing
    | expires: away for two weeks, it is all still there.
    |
    | `deadfall` is deliberately both a node and a material. They live in
    | different maps and different tables, and calling the fallen branches one
    | thing and what you carry away another would be two words for one object.
    |
    */

    'nodes' => [
        'reeds' => ['skill' => 'gather-fibre', 'yields' => 'fibre', 'label' => 'the reeds'],
        'deadfall' => ['skill' => 'gather-wood', 'yields' => 'deadfall', 'label' => 'the fallen branches'],
    ],

    /*
    |--------------------------------------------------------------------------
    | The bag
    |--------------------------------------------------------------------------
    |
    | Everything Blob can hold or build, keyed by the name stored in
    | `companion_items.item`. The CATEGORY LIVES HERE AND NOWHERE ELSE — a row
    | that carried its own category would be a second copy of this, and the two
    | would eventually disagree.
    |
    | This is not `item_types`, which is the four WEARABLES and stays capped
    | forever. Nothing here goes on Blob. (arc §5)
    |
    | Building needs no skill: assembling by hand is what hands are for, and it
    | keeps F1 at two skills.
    |
    */

    'bag' => [
        'fibre' => ['category' => 'material', 'label' => 'fibre'],
        'deadfall' => ['category' => 'material', 'label' => 'deadfall'],

        'basket' => [
            'category' => 'container',
            'label' => 'basket',
            'recipe' => ['fibre' => 4],
            'capacity' => 5,
        ],
        'barrow' => [
            'category' => 'container',
            'label' => 'barrow',
            'recipe' => ['deadfall' => 4],
            'capacity' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity
    |--------------------------------------------------------------------------
    |
    | What Blob's hands hold, and which categories take up that room. A
    | container does not occupy the space it creates; a tool, when F2 adds one,
    | is on the belt.
    |
    | Capacity CAPS WHAT BLOB HOLDS, never what the world has. When the bag is
    | full, harvesting stops and says so — nothing is destroyed, and there is a
    | reason to build the next container rather than hoard.
    |
    */

    'capacity' => [
        'base' => 5,
        'carried' => ['material', 'consumable'],
    ],
```

- [ ] **Step 4: Run the tests and commit**

Run: `php artisan test --compact tests/Unit/Companion/CompanionContentTest.php`
Expected: PASS, 6 tests.

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php tests/Unit/Companion/CompanionContentTest.php
git commit -m "feat(companion): two nodes, two materials, two containers"
```

---

### Task 8: The record stocks the world

**Files:**
- Create: `app/Listeners/StockCompanionNodes.php`
- Test: `tests/Feature/Companion/StockCompanionNodesTest.php`

**Interfaces:**
- Consumes: `App\Events\ActionLogged`, `config('companion.nodes')`, `CompanionSkill::$learned_at`
- Produces: `StockCompanionNodes::handle(ActionLogged $event): void`

Laravel 13 auto-discovers listeners by their `handle()` type-hint, so no registration is needed — but assert that with a test rather than trusting it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\LogAction;
use App\Events\ActionLogged;
use App\Listeners\StockCompanionNodes;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The world is stocked by the record, not by the clock. Each outcome recorded
 * puts one unit into each node whose skill Blob has learned — and only from the
 * moment it was learned, which is what stops a long-established account arriving
 * to a clearing holding a thousand units. (F1 §4)
 */
class StockCompanionNodesTest extends TestCase
{
    use RefreshDatabase;

    /** Wired by discovery rather than by a provider — assert it, do not assume. */
    public function test_the_listener_is_wired_to_the_event(): void
    {
        Event::fake();

        Event::assertListening(ActionLogged::class, StockCompanionNodes::class);
    }

    private function logOnce(User $user): void
    {
        $action = Action::factory()->create();

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);
    }

    public function test_an_outcome_adds_one_unit_to_each_unlocked_node(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()->subDay()]);
        $companion->skills()->create(['name' => 'gather-wood', 'learned_at' => now()->subDay()]);

        $this->logOnce($user);
        $this->logOnce($user);

        $this->assertSame(2, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
        $this->assertSame(2, (int) $companion->nodes()->where('node', 'deadfall')->value('available'));
    }

    /** A node whose skill is not learned gets nothing, met or not. */
    public function test_a_node_without_its_skill_gets_nothing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()->subDay()]);
        // Met but unlearned: the row exists at zero and must stay there.
        $companion->nodes()->create(['node' => 'deadfall', 'available' => 0]);

        $this->logOnce($user);

        $this->assertSame(1, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
        $this->assertSame(0, (int) $companion->nodes()->where('node', 'deadfall')->value('available'));
    }

    /**
     * The retroactivity rule. XP is retroactive over the whole record; the world
     * is not. An established account learning a skill today starts at zero.
     */
    public function test_stock_accrues_only_from_the_moment_the_skill_was_learned(): void
    {
        $user = User::factory()->create();

        for ($index = 0; $index < 20; $index++) {
            $this->logOnce($user);
        }

        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->assertSame(0, (int) $companion->nodes()->where('node', 'reeds')->value('available'));

        $this->logOnce($user);

        $this->assertSame(1, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** A user with no companion row is not given one by logging. */
    public function test_logging_alone_does_not_create_a_companion(): void
    {
        $user = User::factory()->create();

        $this->logOnce($user);

        $this->assertDatabaseCount('companions', 0);
        $this->assertDatabaseCount('companion_nodes', 0);
    }

    /** Insights do not stock the world in F1; that is an F2 question. */
    public function test_an_insight_does_not_stock_the_world(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()->subDay()]);

        Strategy::factory()
            ->for(Intention::factory()->for($user))
            ->create(['verdict' => Strategy::VERDICT_WORKED, 'verdict_note' => 'What the evidence showed.']);

        $this->assertSame(0, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }
}
```

`Action::factory()` needs a strategy to attach to — check `ActionFactory`'s definition before writing `logOnce()`; if it requires an intention/strategy graph, build it there the way `tests/Feature/Workflows/*` already does.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Companion/StockCompanionNodesTest.php`
Expected: FAIL — `Class "App\Listeners\StockCompanionNodes" not found`.

- [ ] **Step 3: Write the listener**

```php
<?php

namespace App\Listeners;

use App\Events\ActionLogged;
use App\Models\Companion;
use App\Models\CompanionSkill;

/**
 * The record stocks the world.
 *
 * Each outcome recorded adds one unit to each node whose skill Blob has
 * learned. Not a clock and not a cron: nothing grows while you are away, and
 * nothing expires either — the world holds whatever you cannot carry, forever.
 * (F1 §4)
 *
 * Stock accrues ONLY FROM `learned_at`. Blob could not see reeds as reeds
 * before it knew what they were, which is both the in-fiction reason and the
 * mechanical one: it stops a long-established account arriving to a clearing
 * holding a thousand units. XP is retroactive over the whole record; the world
 * is deliberately not.
 *
 * Not queued. It is two small writes inside a request that has already written,
 * and a queued listener would need the companion row to exist by the time the
 * job ran.
 */
class StockCompanionNodes
{
    public function handle(ActionLogged $event): void
    {
        $companion = $event->user->companion;

        // No companion row means nothing has been chosen yet, so there is no
        // clearing to stock. Logging alone never creates one.
        if (! $companion instanceof Companion) {
            return;
        }

        /** @var array<string, array{skill: string, yields: string, label: string}> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $learned = $companion->skills()
            ->get()
            ->keyBy(static fn (CompanionSkill $skill): string => $skill->name);

        foreach ($nodes as $name => $node) {
            $skill = $learned->get($node['skill']);

            if (! $skill instanceof CompanionSkill) {
                continue;
            }

            // At or after: an outcome logged in the same second the skill was
            // learned is the first thing the new eyes see, not the last thing
            // the old ones missed.
            if ($event->log->logged_at->lessThan($skill->learned_at)) {
                continue;
            }

            $companion->nodes()->updateOrCreate(
                ['node' => $name],
                [],
            )->increment('available');
        }
    }
}
```

- [ ] **Step 4: Run the tests and commit**

Run: `php artisan test --compact tests/Feature/Companion/StockCompanionNodesTest.php`
Expected: PASS, 6 tests.

```bash
vendor/bin/pint --dirty --format agent
git add app/Listeners/StockCompanionNodes.php tests/Feature/Companion/StockCompanionNodesTest.php
git commit -m "feat(companion): the record stocks the clearing"
```

---

### Task 9: Meeting, harvesting, building

Three actions, one test file each. They are one task because they share `CompanionEconomyException` and none is reviewable without the others.

**Files:**
- Create: `app/Actions/MeetNode.php`, `app/Actions/HarvestNode.php`, `app/Actions/BuildItem.php`
- Test: `tests/Feature/Companion/MeetNodeTest.php`, `tests/Feature/Companion/HarvestNodeTest.php`, `tests/Feature/Companion/BuildItemTest.php`

**Interfaces:**
- Produces:
  - `MeetNode::handle(User $user, string $node): CompanionNode` — idempotent; creates the row at zero
  - `HarvestNode::handle(User $user, string $node): int` — returns how many units moved; throws `CompanionEconomyException::bagIsFull()` when there is no room
  - `BuildItem::handle(User $user, string $item): CompanionItem` — throws `CompanionEconomyException::missingMaterials()`

- [ ] **Step 1: Write the three failing tests**

`MeetNodeTest` asserts: meeting a node creates its row at zero; meeting it twice changes nothing; meeting a node that does not exist throws `InvalidArgumentException`; meeting a node harvests nothing even when stock is standing there and the skill is learned (the encounter and the harvest are separate acts — F1 §2).

`HarvestNodeTest` asserts:
- harvesting without the skill throws `CompanionEconomyException` and moves nothing;
- harvesting moves all standing stock into the bag when there is room, and the node drops to zero;
- harvesting stops at capacity: with base capacity 5, nothing held and 9 standing, 5 move and **4 remain standing** — `assertSame(4, …)` on `available`, which is the "destroys nothing" assertion and the reason it is written as a remainder rather than as "the bag is full";
- harvesting into a full bag throws `bagIsFull` and leaves the node untouched;
- a built `basket` raises capacity to 10, so the next harvest takes 5 more;
- another user's node is never harvested.

`BuildItemTest` asserts:
- building with exactly the recipe consumes exactly the recipe and leaves a zero-quantity or deleted stack (pick one and assert it — deleting the stack is cleaner, and `assertDatabaseMissing` on `companion_items` is safe because the columns exist);
- building with a short recipe throws `missingMaterials` and consumes nothing;
- building raises `capacity()` by the container's `capacity`;
- building the same container twice is allowed and stacks the capacity (F1 §1: each container adds 5, cumulative) — so `quantity` increments rather than a second row;
- building needs no skill.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Companion/MeetNodeTest.php tests/Feature/Companion/HarvestNodeTest.php tests/Feature/Companion/BuildItemTest.php`
Expected: FAIL — three "does not exist" errors.

- [ ] **Step 3: Write the three actions**

`MeetNode` — `$user->companion()->firstOrCreate([])->nodes()->firstOrCreate(['node' => $node])`, guarded by an `array_key_exists` check against `config('companion.nodes')`. Its docblock carries F1 §2's reasoning: the row existing is the record of the encounter, and the encounter is what puts the skill in the list.

`HarvestNode` — inside `DB::transaction`: resolve the node from config; require the row and the skill; `$moved = min($row->available, $companion->room())`; `throw CompanionEconomyException::bagIsFull($node)` when `$moved === 0`; `$row->decrement('available', $moved)`; `updateOrCreate` the item stack and increment by `$moved`; return `$moved`. The docblock states the invariant plainly: **the remainder stays standing; nothing is ever destroyed.**

`BuildItem` — inside `DB::transaction`: resolve the recipe from `config('companion.bag')`; collect the shortfall across every ingredient and throw `missingMaterials` with all of it at once rather than the first miss; decrement each ingredient, deleting a stack that reaches zero; `updateOrCreate` the built item and increment. The docblock states arc §4's restatement: **spending fibre on a basket is not losing fibre, it is the basket having fibre in it.**

- [ ] **Step 4: Run the tests and commit**

Run: `php artisan test --compact tests/Feature/Companion`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/MeetNode.php app/Actions/HarvestNode.php app/Actions/BuildItem.php tests/Feature/Companion
git commit -m "feat(companion): meet it, gather it, build it"
```

---

### Task 10: The bag as a read model

One place that answers "what does the screen need", so the controller does not assemble it and neither does the page.

**Files:**
- Create: `app/Services/Companion/CompanionBag.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Produces: `CompanionBag::forUser(User $user): array` with exactly this shape:

```php
[
    'xp' => 48,                 // the balance, and nothing about a target
    'capacity' => 5,
    'held' => 3,
    'items' => [                // only what is actually held
        ['item' => 'fibre', 'label' => 'fibre', 'category' => 'material', 'quantity' => 3],
    ],
    'nodes' => [                // only nodes Blob has MET
        ['node' => 'reeds', 'label' => 'the reeds', 'available' => 3, 'skill' => 'gather-fibre', 'known' => true],
    ],
    'skills' => [               // only skills whose node Blob has MET
        ['skill' => 'gather-fibre', 'label' => 'gather fibre', 'price' => 20, 'known' => true],
    ],
    'recipes' => [              // only containers whose ingredients Blob has MET
        ['item' => 'basket', 'label' => 'basket', 'recipe' => ['fibre' => 4], 'buildable' => false],
    ],
    'name' => 'Blob',
]
```

Note what is **not** in it: no count of skills that exist, no count of nodes that exist, no percentage, no "next". The guard in `companion.test.tsx` is about the rendered text, but the payload is where a total would first appear — so the test asserts the keys as well as the values.

- [ ] **Step 1: Write the failing test** covering: an untouched account returns empty lists and `xp` equal to the balance; a met-but-unlearned node lists its skill with a price and `known: false`; a learned node lists its stock; a recipe appears only once one of its ingredients has been met; `buildable` flips when the materials are there; the payload has no key matching `/total|count|of|percent|next|remaining/`.

- [ ] **Step 2–4:** run it red, write `CompanionBag`, run it green, commit.

```bash
git commit -m "feat(companion): one answer to what the bag is"
```

---

**■ STOP. Batch 2 review.**

---

# BATCH 3 — the name

F1 §7. The column shipped in Batch 1; this is the copy.

---

### Task 11: The `{name}` token

**Files:**
- Modify: `config/companion.php` (13 ladder messages, 6 tail templates), `app/Services/Companion/CompanionAnnouncement.php`, `app/Services/Companion/CompanionResolver.php`
- Test: `tests/Unit/Companion/CompanionLadderTest.php` (new guard), `tests/Feature/Companion/CompanionNameTest.php`

- [ ] **Step 1: Write the guard first**

In `CompanionLadderTest`, which already reads config directly:

```php
    /**
     * Blob answers to a name now, and authored copy that hardcodes "Blob" would
     * have a renamed companion referring to itself in the third person. The
     * token is substituted server-side in CompanionAnnouncement and at the one
     * client-side call site; this stops a later rung quietly reintroducing the
     * literal.
     */
    public function test_no_authored_message_hardcodes_the_word_blob(): void
    {
        $config = $this->config();

        $messages = [
            ...array_map(static fn (array $entry): string => $entry['message'], $config['ladder']),
            ...$config['tail']['messages'],
        ];

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('Blob', $message, "[{$message}] hardcodes the name");
            $this->assertStringContainsString('{name}', $message, "[{$message}] never names the companion");
        }
    }
```

- [ ] **Step 2: Run it red**, then rewrite all 19 strings with `{name}`. `'Blob is here. New to all this…'` becomes `'{name} is here. New to all this…'`, and so on through the tail templates.

- [ ] **Step 3: Substitute server-side.** `CompanionResolver::forUser()` reads `$user->companion?->displayName() ?? Companion::DEFAULT_NAME` once and `str_replace('{name}', $name, …)` every message it emits — both the authored walk and `tailUnlocks()`. `CompanionState` gains a `public string $name` so the client has it without a second prop.

- [ ] **Step 4:** assert a renamed companion is named in every message, in `CompanionNameTest`, and that an unnamed one still reads exactly as it did before the column existed — compare against the literal strings the old tests asserted (`'Blob is here.'`, `'Blob has legs now.'`).

- [ ] **Step 5:** run `php artisan test --compact tests/Feature/Companion tests/Unit/Companion tests/Feature/Mcp`, pint, commit.

---

### Task 12: Renaming

**Files:**
- Create: `app/Http/Controllers/CompanionNameController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Companion/CompanionNameTest.php`

`PATCH /companion/name`, `->name('companion.name')`, inside the same auth group as the existing `companion` GET. Validation: `nullable|string|max:24`. Empty string clears back to null, which reads as "Blob" again — renaming is never destructive and never final.

Tests: a rename persists and shows up in the next render; clearing restores "Blob"; another user's companion cannot be renamed; over-long names are rejected with a 422.

```bash
git commit -m "feat(companion): let Blob be called something else"
```

---

### Task 13: The name on the client

**Files:**
- Modify: `resources/js/patyourself/companion.tsx` (`describe()`, `CompanionData`), `resources/js/patyourself/companion.fixture.ts`
- Test: `resources/js/patyourself/companion.test.tsx`

`describe()` currently hardcodes `'Blob'` and `'Blob, with …'`. It takes the name from `companion.name`. The fixture gains `name: 'Blob'` so the four other screens are unaffected.

Vitest: a renamed companion is named in the `aria-label`; an unnamed one still reads `Blob`.

Run: `npx vitest run resources/js/patyourself resources/js/pages/companion.test.tsx`

**Known gap, accepted for F1 and recorded rather than discovered:** coach-written remarks (`CompanionRemarks`, `WriteBlobRemark`, and the MCP surfaces that feed them) will still say "Blob". The name should be exposed wherever the coach reads companion state; if that proves larger than it looks it moves to F2. Note it in the batch report either way.

```bash
git commit -m "feat(companion): the name reaches the screen"
```

---

**■ STOP. Batch 3 review.**

---

# BATCH 4 — the bag modal

Builds Task 1's settled layout: the page stays at two panels, and the bag opens over them.

---

### Task 14: The payload reaches the page

**Files:**
- Modify: `app/Http/Controllers/CompanionController.php`, `resources/js/patyourself/companion.tsx`, `resources/js/patyourself/companion.fixture.ts`
- Test: `tests/Feature/Companion/CompanionScreenTest.php`

The controller adds `'bag' => $bag->forUser($user)` beside the existing `companion` and `remark` props. `CompanionData` gains `bag: CompanionBagData`, typed to match `CompanionBag`'s shape exactly. The fixture gains an empty bag so `dashboard.test.tsx`, `companion-room.test.tsx`, `companion-glyph.test.tsx` and `app-rail.test.tsx` keep passing untouched.

Feature test: the rendered Inertia page carries the bag prop, and its balance matches the wallet.

---

### Task 15: The bag modal

**Files:**
- Create: `resources/js/patyourself/companion-bag.tsx`, `resources/js/patyourself/companion-bag.test.tsx`
- Modify: `resources/js/pages/companion.tsx`, `resources/css/patyourself.css`

**Interfaces:**
- Produces: `<CompanionBag bag={...} open={boolean} onOpenChange={(open: boolean) => void} />` — a controlled dialog. Controlled rather than self-managed because Task 18 has to open it from outside, when an encounter reveals a skill.

- [ ] **Step 1: Build on the primitives, not the wrapper**

```tsx
import * as Dialog from '@radix-ui/react-dialog';
```

Not `@/components/ui/dialog`. That wrapper hardcodes `bg-background`, `rounded-lg`, `p-6`, `shadow-lg` and a lucide close button, and **the close button cannot be removed through `className`** — it would put a notebook X in the corner of a pixel panel. The primitives give the focus trap, Esc-to-close, the overlay and the scroll lock; the skin is the panels' own.

`Dialog.Title` is not optional: Radix warns without one, and it is what the dialog is announced as. Use the `.c-ribbon` ribbon as the title element — `THE BAG`, with the capacity beside it exactly as `.c-ribbon span` already handles the record's "since 20 Aug 2026".

The close control is the remark bubble's `×` (`.c-saidx`), reused rather than reinvented — it is already the app's one pixel-panel dismiss affordance, and it already carries a visible focus ring.

- [ ] **Step 2: The contents**

Four groups in one `.pixel-frame .c-panel`, in this order, each absent entirely when empty:

| Group | Rows | Rule |
| --- | --- | --- |
| *(ribbon)* | `THE BAG` · `3 / 5` | Held-of-capacity. Never the word "of". |
| `HELD` | `fibre` · `3` | Only what is held. |
| `BUILD` | `basket` · `4 fibre` | A price. Never "you need". |
| `BLOB COULD LEARN` | `gather fibre` · `20 xp` | Only skills whose node has been met. |

An empty bag renders the ribbon and one plain line that owes nothing — the same register as `.c-first`'s "that is the whole of it, so far".

- [ ] **Step 3: Write the tests**

These are the acceptance criteria rather than coverage:

- the modal's contents pass `/locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i`. `3 / 5` does not match `\d+ of \d+` — but `held` and `capacity` must never be joined by the word "of", and the test exists to keep it that way;
- a skill whose node has not been met is **absent from the DOM**, not hidden;
- an unaffordable skill is still listed, with its price, and its *button* is disabled while the row stays readable — a price you cannot pay yet is a menu; a greyed row is a lock;
- an empty bag says something plain and owes nothing;
- the dialog has an accessible name, closes on Esc, and returns focus to the button that opened it;
- `open={false}` renders nothing — assert on `queryByRole('dialog')` being null, so a modal that is merely visually hidden fails.

- [ ] **Step 4: CSS**

Append after `.c-first`, hand-formatted in the existing block's idiom. Needed: the overlay (a flat dark wash, no blur — blur is notebook chrome), the centred frame with a `max-height` and its own `overflow-y: auto` so a long skill list scrolls inside the wood rather than growing the page, and the group/row/price rules.

**Do not run prettier over `resources/css/patyourself.css`.** It is hand-formatted and already fails `format:check` on `main`; `--write` turns a small addition into a multi-thousand-line diff.

---

### Task 16: The XP readout and the button that opens the bag

**Files:**
- Modify: `resources/js/pages/companion.tsx` (`RoomCard`'s `.c-place` and `.c-plinth`), `resources/css/patyourself.css`
- Test: `resources/js/pages/companion.test.tsx`

- [ ] **Step 1: The balance in the place bar**

`the forest · 48 xp · dusk 19:30`. Balance only.

The existing place-bar tests (`names where Blob is and what part of the day it is`, `reads the same hour the room and the ambient do`) must keep passing. They use `getByText('the forest')` and `getByText('dusk · 19:30')`, which are exact-string matchers — so the XP goes in **its own element**, never concatenated into either of those strings, or both tests break on a change that is not about them.

- [ ] **Step 2: The button in the plinth**

`Bag 3 / 5`, after Poke. A real `<button>` in `.c-plinth`, styled with the same `.pixel-button` the others use.

It is the odd one out in that row — every other button there fires an animation through `onReact`, and this one opens a dialog. The plinth already has a documented odd one out (Poke, "deliberately last"), and the shared argument holds: what these buttons act on is inside this frame and nothing else, and everything in the bag came out of this frame.

The capacity is on the button because it is the bag's one **ambient** fact — the number that is true whether or not you are looking. Everything else in the bag is static until you act on it, which is the whole argument for it being a modal.

- [ ] **Step 3: Write the tests**

- the bar shows the balance and nothing resembling a target, a bar, a delta or a "next at";
- the plinth's bag button shows `3 / 5` and opens the dialog when clicked;
- the three existing plinth buttons still work and are still enabled — earning something adds a button here, it never takes one away;
- the page still passes `never shows what has not happened` with the bag **closed**, and again with it **open**. Two separate cases: the guard queries the whole rendered tree, and a modal that only appears on click would otherwise never be inside it.

- [ ] **Step 4: Run, lint, commit**

```bash
npx vitest run resources/js/pages/companion.test.tsx resources/js/patyourself
npx eslint resources/js/pages/companion.tsx resources/js/patyourself/companion-bag.tsx
```

```bash
git commit -m "feat(companion): a bag you open, in the same wood"
```

---

**■ STOP. Batch 4 review.**

---

# BATCH 5 — the clearing

---

### Task 17: Nodes in the scene

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx`, `resources/js/patyourself/scenes.ts`
- Test: `resources/js/patyourself/companion-room.test.tsx`

Nodes are drawn in the forest scene only, beside the foliage layers, at fixed positions in the room's coordinate system — reeds by the water on the left, deadfall under the tree on the right. **Both are visible from the start**, whatever the record says.

Art: `foliage-grass.png` may stand in for reeds, which leaves deadfall as the one small asset needing Pixel Lab. Keep it to the minimum that reads — `docs/BLOB.md` §7 is clear that art is the slowest and least predictable part of this feature, and F1 does not need it to be good.

Each node is a real `<button>` over the SVG rather than a click handler on a `<g>`: the scene is `role="img"` and everything reachable in it must also be reachable from a keyboard, which is the rule `onPoke` already follows.

---

### Task 18: The encounter

**Files:**
- Create: `app/Http/Controllers/CompanionNodeController.php`
- Modify: `routes/web.php`, `resources/js/pages/companion.tsx`
- Test: `tests/Feature/Companion/CompanionNodeScreenTest.php`, `resources/js/pages/companion.test.tsx`

`POST /companion/nodes/{node}`. Without the skill it calls `MeetNode` and flashes *"{name} turns the reeds over and puts them down again."* With the skill it calls `HarvestNode` and flashes what moved — or, when the bag is full, *"The basket is full. The reeds stay by the water."*

The line is authored copy and lives in `config('companion.nodes')` beside the label, under the same copy rules as everything else: sentence case, no exclamation marks, never congratulating.

**The modal opens itself here, and only here.** This is the payment for Task 1's decision: a panel would have made "click a node, its skill appears in the list" visible, and a modal hides it. So the controller flags the response when the encounter revealed a skill that was not in the list before — `'bag_opened' => true` beside the flash — and the page passes that straight into `<CompanionBag open>` on the render that follows.

Three rules on that, because an auto-opening modal is one wrong turn from being a nag:

1. **Only on an encounter that revealed something.** Meeting a node whose skill is already listed opens nothing. Harvesting opens nothing.
2. **Only as the response to a click.** Nothing opens on page load, on a poll, or on a visit.
3. **Dismissing it is final for that visit.** The flag is per-response, not sticky, so closing it does not re-open on the next render.

Vitest, and the first two are the acceptance criteria for the whole discovery mechanic:
- clicking a node without its skill reveals the skill in the list and harvests nothing;
- before that click, the skill is absent from the DOM entirely — including inside the modal, so open it and check;
- the bag opens on the response to that first encounter, and does **not** open on a second encounter with the same node.

---

### Task 19: Buying and building from inside the modal

**Files:**
- Create: `app/Http/Controllers/CompanionSkillController.php`, `app/Http/Controllers/CompanionBuildController.php`
- Modify: `routes/web.php`, `resources/js/patyourself/companion-bag.tsx`
- Test: `tests/Feature/Companion/CompanionSkillScreenTest.php`, `tests/Feature/Companion/CompanionBuildScreenTest.php`

Two Inertia `<Form>` posts from inside the dialog, each redirecting back to `/companion`. `CompanionEconomyException` is caught at the controller and turned into a flash message rather than a 500 — a full bag and a short balance are ordinary states of the world, not errors.

**The modal must survive its own submit.** An Inertia post re-renders the page, and an uncontrolled dialog would close on the way through — so you would buy a skill and watch the bag vanish. `CompanionBag` is controlled (Task 15), so the page holds the open state across the visit and the dialog stays up showing the result. Assert it: buying a skill leaves the dialog open with the skill moved out of `BLOB COULD LEARN`.

Where the flash line goes matters too. The room card's `.c-said` bubble is behind the overlay while the bag is open, so a refusal shown only there is a button that appears to do nothing. Render the flash **inside** the dialog when the dialog is open.

Then the closing checks, all of them:

```bash
php artisan test --compact
npx vitest run
npx tsc --noEmit
npx eslint resources/js
vendor/bin/pint --dirty --format agent
```

```bash
git commit -m "feat(companion): spend it, gather it, build it, from the screen"
```

---

**■ STOP. Batch 5 review, and F1 is done.**

---

## What would make this wrong

From arc §8, so a later reader can check rather than re-derive:

- If any surface shows a completion total, the rewritten rule in arc §4 is broken and the feature is a checklist again.
- If XP ever varies by whether an outcome was a success, arc §3 is broken and the app is paying people to hide failures.
- If anything is ever removed from Blob for inactivity, arc §4's third rule is broken.
- If the fastest route to progression is creating more loops, the taper is mistuned.
