# Companion F2 — The tools: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blob builds an axe, reaches a trunk it could not reach without one, saws timber into planks with a handsaw it also built, and every gate it passes through is either the record's permission or a thing in its hands.

**Architecture:** F1's node rails carry F2 unchanged; F1's recipe rails do not. This plan adds three optional config keys (`nodes.*.tool`, `bag.*.tool`, `bag.*.makes`), three guards (a tool gate on harvesting, a tool gate on building, a capacity check on building), and one visibility rule (`CompanionBag::recipes()` becomes transitive and tool-revealed — the second half of which fixes an F1 defect where any recipe naming a built ingredient is invisible forever). The rule the whole phase establishes: **a node gates on skill + tool; a recipe gates on tool, never skill.**

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit 12 (feature tests preferred), Inertia v3 + React 19, Vitest, Tailwind v4 plus the hand-written `resources/css/patyourself.css`, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-09-17-companion-f2-the-tools-design.md`, whose reasoning lives in `docs/superpowers/specs/2026-09-17-companion-progression-arc-design.md` and whose rails are described in `docs/superpowers/specs/2026-09-17-companion-f1-the-bag-design.md`. Read all three; this plan argues from them and does not restate their justifications.

---

## Global Constraints

Every task's requirements implicitly include this section. Values are copied verbatim from the spec.

- **The rule.** A node gates on **skill + tool**. A recipe gates on **tool, never skill**. `BuildItemTest::test_building_needs_no_skill` must stay green and unedited. (F2 §2)
- **A `failed` outcome pays exactly what a `completed` one pays.** Also a `skipped` one. F2 touches nothing that reads an outcome and must go on touching nothing. (arc §3)
- **Never show a total, a completion percentage, or an end state. Show what is choosable now.** The guard in `resources/js/pages/companion.test.tsx` — `never shows what has not happened`, matching `/locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i` — **runs twice, bag closed and bag open**, and both must stay green. (arc §4)
- **Nothing regresses.** No decay, no expiry, no upkeep, nothing removed from Blob for inactivity, ever. Materials *transform*; they are never taken. **A build that cannot complete consumes nothing** — a half-consumed recipe would be the first thing in this feature that ever took something away. (arc §4, F2 §4.4)
- **Never show a locked row.** A skill Blob has not met is absent, not greyed. A recipe row lists its price — ingredients *and* tool — with the button disabled, never the row. (F1 §2, F2 §4.6)
- **Tools take no bag room.** `config('companion.capacity.carried')` stays `['material', 'consumable']`. A tool is permanent, so a tool in a slot is a permanent tax, which is regression in everything but name. (F2 §6)
- **A tool does not wear out.** No durability, no repair, no sharpening. Progression past a tool is a second, better tool — addition, never repair. (F2 §6)
- **Insight events do not stock the world.** There is no insight event in this application; the resolver derives insights from database state. Do not add one. (F2 §6)
- **Copy rules** for anything authored: sentence case, one or two sentences, no exclamation marks, never congratulating, no second person keeping score. Say what Blob can now do — never how well the user is performing. Never name the tool the user should go and build: that is the app stating a plan rather than leaving an inference.
- **No authored message may contain the literal "Blob".** Every one carries `{name}`, substituted server-side. (F1 §7)
- **`CompanionVocabularyTest` scans source files including comments** for `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. **`points` is a substring trap** — *appoints* and *disappoints* trip it. Read the list before naming anything.
- **`CompanionBagTest` asserts the payload's shape**, matching every key against `/total|count|percent|progress|next|remaining|locked|streak/i`. `tool` and `makes` pass.
- **Model convention:** the `#[Fillable([...])]` **attribute**, never a `$fillable` property.
- **Tests are SQLite, production is MySQL.** Bind values in raw SQL; never embed quoted literals. `assertDatabaseMissing` on a column that does not exist is a constant-false predicate that passes forever on SQLite.
- **Column-limited eager loads hide new columns.** `CompanionBag` eager-loads `['items','nodes','skills']` whole. Keep it that way.
- **Run `vendor/bin/pint --dirty --format agent`** before every commit that touches PHP.
- **Never run `npm run lint` from the repo root** — eslint descends into `.claude/worktrees/*` and auto-edits files there. Scope it: `npx eslint resources/js/<path>`.
- **Never run `npx prettier --write` on `resources/css/*.css`.** Both CSS files are hand-formatted and already fail `format:check` on `main`.
- **`className`: use `cn()`, never a template literal** — prettier's tailwind plugin eats the leading space and ships `class="c-bagrowis-short"`. Assert with `toHaveClass`, never `toContain`.

---

## File structure

**Modified — the economy**

| File | Change |
| --- | --- |
| `app/Services/Companion/CompanionEconomyException.php` | Two new factories: `toolNotHeld()`, `noRoomFor()`. |
| `app/Actions/HarvestNode.php` | Reads `nodes.*.tool` and refuses without it, after the skill check. |
| `app/Actions/BuildItem.php` | Reads `bag.*.tool` and `bag.*.makes`; gains the capacity check. |
| `app/Services/Companion/CompanionBag.php` | Knowability becomes transitive and tool-revealed; recipe rows carry `tool`; `buildable` accounts for it. |
| `app/Http/Controllers/CompanionNodeController.php` | Tells the tool refusal apart from a full bag, using the new `blunt` copy key. |

**Modified — content**

| File | Change |
| --- | --- |
| `config/companion.php` | `skills.chop-wood`; `nodes.trunk`; `bag.timber`, `bag.rope`, `bag.axe`, `bag.handsaw`, `bag.planks`, `bag.crate`. |

**Modified — the surfaces**

| File | Change |
| --- | --- |
| `resources/js/patyourself/companion.tsx` | `BagRecipeData` gains `tool: string \| null`. |
| `resources/js/patyourself/companion-bag.tsx` | The tool renders as part of the recipe's price. |
| `resources/js/patyourself/companion.fixture.ts` | The third node, and `tool: null` on recipe rows. |
| `resources/js/patyourself/scenes.ts` | `trunk` joins `SCENES.forest.nodes`. |

**Modified — tests**

| File | Change |
| --- | --- |
| `tests/Feature/Companion/HarvestNodeTest.php` | The tool gate. |
| `tests/Feature/Companion/BuildItemTest.php` | The tool gate, `makes`, the capacity check. |
| `tests/Feature/Companion/CompanionBagTest.php` | Transitive and tool-revealed knowability; the `tool` key. |
| `tests/Feature/Companion/CompanionClearingTest.php` | The `blunt` line on the route. |
| `tests/Unit/Companion/CompanionContentTest.php` | The fork narrowed; no dead ends; `blunt` in the copy rules. |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | The economy's PHP files join the scanned list. |
| `resources/js/patyourself/companion-bag.test.tsx` | The tool in the price. |

**Created**

| File | Responsibility |
| --- | --- |
| `tests/Feature/Companion/CompanionRulingsTest.php` | The three refusals in F2 §6, each as a test that fails if the ruling is ever quietly reversed. |

---

## Batches

| Batch | What it delivers | Risk |
| --- | --- | --- |
| **1** | The node gate: `toolNotHeld`, `HarvestNode`, the controller's `blunt` line, transitive and tool-revealed knowability, and the content that exercises it — rope, axe, timber, the trunk, `chop-wood`. Plus the three rulings' guards and the vocabulary-list gap. | None on screen. |
| **2** | The recipe gate: `bag.*.tool`, `bag.*.makes`, the build capacity check, the `tool` key in the payload, and the handsaw, planks and crate. | None on screen. |
| **3** | The clearing: the third hotspot, the tool in the recipe row, the fixture. Then `docs/BLOB.md`, as its own commit. | First UI. |

**Stop for review after every batch.**

Tasks 1–4 change mechanisms and are tested with `config()->set()` against F1's existing content, so each lands on `main` correct: no authored node has a `tool` key until Task 5, and no authored recipe has one until Task 9. That is deliberate — a node carrying a `tool` key that `HarvestNode` did not yet read would be harvestable without the axe, which is a wrong behaviour sitting on `main` between two reviews.

---

# BATCH 1 — the node gate

---

### Task 1: A node can require a tool

**Files:**
- Modify: `app/Services/Companion/CompanionEconomyException.php`
- Modify: `app/Actions/HarvestNode.php:33-81`
- Test: `tests/Feature/Companion/HarvestNodeTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `CompanionEconomyException::toolNotHeld(string $thing, string $tool): self` — used by Task 7 as well, for recipes
  - `HarvestNode::handle()` unchanged in signature; it now reads the optional `tool` key on a node

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/HarvestNodeTest.php`. These set the `tool` key through `config()` rather than relying on authored content, because the mechanism is what is under test and no node is authored with one until Task 5.

```php
    /**
     * A node may require a tool as well as a skill. The two gates are the two
     * economic layers meeting on one gesture: the record says what Blob CAN
     * do, and the world says what it has in hand. (F2 §2)
     */
    public function test_a_node_that_needs_a_tool_refuses_without_it(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        [$user, $companion] = $this->clearing(4);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');

            $this->fail('Harvesting without the tool should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('axe', $exception->getMessage());
        }

        // Nothing moved and nothing was destroyed: the stock is still standing
        // and the bag is still empty.
        $this->assertSame(4, $this->standing($companion, 'reeds'));
        $this->assertSame(0, $this->held($companion, 'fibre'));
    }

    /** With the tool in the bag, the same click gathers as it always did. */
    public function test_a_node_that_needs_a_tool_yields_once_it_is_held(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        [$user, $companion] = $this->clearing(4);
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $moved = app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(4, $moved);
        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $this->standing($companion, 'reeds'));
    }

    /**
     * The skill is checked first. A node whose skill has not been bought is a
     * node whose tool is not yet the problem, and refusing for the further of
     * the two reasons would send the reader past the nearer one.
     */
    public function test_the_skill_is_refused_before_the_tool(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');

            $this->fail('Harvesting without the skill should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('gather-fibre', $exception->getMessage());
            $this->assertStringNotContainsString('axe', $exception->getMessage());
        }
    }

    /** A node with no tool key behaves exactly as F1's two always have. */
    public function test_a_node_with_no_tool_needs_none(): void
    {
        [$user, $companion] = $this->clearing(2);

        $this->assertSame(2, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertSame(2, $this->held($companion, 'fibre'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=needs_a_tool`
Expected: FAIL — nothing reads the key, so the first test harvests successfully and hits `$this->fail(...)`.

- [ ] **Step 3: Add the refusal**

In `app/Services/Companion/CompanionEconomyException.php`, beside `skillNotLearned()`:

```php
    /**
     * Something was asked for that needs a thing Blob is not carrying.
     *
     * Takes a `$thing` rather than a node, because both halves of F2's rule
     * raise this: a node that needs an axe and a recipe that needs a handsaw
     * are the same refusal about the same kind of object.
     */
    public static function toolNotHeld(string $thing, string $tool): self
    {
        return new self("[{$thing}] needs [{$tool}], which Blob is not carrying.");
    }
```

- [ ] **Step 4: Read the key in `HarvestNode`**

In `app/Actions/HarvestNode.php`, the docblock's `@param` shape and the two lines that read config:

```php
        /** @var array<string, array{skill: string, yields: string, label: string, tool?: string}> $authored */
        $authored = (array) config('companion.nodes', []);

        if (! array_key_exists($node, $authored)) {
            throw new InvalidArgumentException("[{$node}] is not something Blob can gather from.");
        }

        $skill = (string) $authored[$node]['skill'];
        $yields = (string) $authored[$node]['yields'];
        $tool = (string) ($authored[$node]['tool'] ?? '');
```

Then inside the transaction, immediately after the existing skill check and before the node is read:

```php
            if ($companion->skills()->where('name', $skill)->doesntExist()) {
                throw CompanionEconomyException::skillNotLearned($node, $skill);
            }

            // The skill first, then the tool. A node whose skill has not been
            // bought is a node whose tool is not yet the problem, and refusing
            // for the further of the two reasons would send the reader past
            // the nearer one.
            //
            // Absent is "needs none": every node F1 authored has no tool key
            // and must go on behaving exactly as it always has.
            if ($tool !== '' && $companion->items()->where('item', $tool)->doesntExist()) {
                throw CompanionEconomyException::toolNotHeld($node, $tool);
            }
```

Update the method's `@throws` line to name the new case:

```php
     * @throws CompanionEconomyException when the skill is unlearned, the tool
     *                                   is not held, or the bag is full while
     *                                   stock is standing.
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/HarvestNodeTest.php`
Expected: PASS, all of it — the four new cases and every F1 case unedited.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionEconomyException.php app/Actions/HarvestNode.php tests/Feature/Companion/HarvestNodeTest.php
git commit -m "feat(companion): a node can ask for something in hand"
```

---

### Task 2: The clearing says which refusal it was

**Files:**
- Modify: `app/Http/Controllers/CompanionNodeController.php:36-104`
- Test: `tests/Feature/Companion/CompanionClearingTest.php`

**Interfaces:**
- Consumes: Task 1's `nodes.*.tool` gate
- Produces: a fifth authored copy key, `nodes.*.blunt`, said when the skill is known and the tool is not held

`CompanionNodeController::gather()` wraps `HarvestNode` in a `catch (CompanionEconomyException)` that says the node's `full` line. Task 1's new refusal would be caught by it and announced as a full bag, which is the wrong sentence about the wrong thing. The controller checks the tool itself, exactly as it already checks the skill itself — the action's throw is the guarantee, the controller's check is the copy.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Companion/CompanionClearingTest.php`:

```php
    /** The skill is bought and the tool is not, which is the third case. */
    private function blunted(): array
    {
        config()->set('companion.nodes.reeds.tool', 'axe');
        config()->set(
            'companion.nodes.reeds.blunt',
            '{name} looks at the reeds. Nothing it is carrying will cut them.',
        );

        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);

        return [$user, $companion];
    }

    /**
     * A node that needs a tool Blob has not built says so in Blob's own voice,
     * and never as a full bag — which is what the broad catch in `gather()`
     * would otherwise announce.
     *
     * The line describes what Blob is carrying. It never names the thing the
     * user should go and build: that is the app stating a plan rather than
     * leaving an inference, which is the one thing BLOB.md forbids outright.
     */
    public function test_a_node_that_needs_a_tool_says_so_rather_than_saying_the_bag_is_full(): void
    {
        [$user, $companion] = $this->blunted();

        $this->touch($user, 'reeds')->assertRedirect();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'Blob looks at the reeds. Nothing it is carrying will cut them.')
                // Nothing was revealed, so nothing opens. A refusal at a node
                // Blob has already met is not a discovery.
                ->where('revealed', false),
            );

        // And nothing moved, in either direction.
        $this->assertSame(4, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
        $this->assertSame(0, $companion->items()->count());
    }

    /** A renamed companion is named in the refusal too. */
    public function test_the_tool_refusal_carries_the_companions_name(): void
    {
        [$user, $companion] = $this->blunted();

        $companion->update(['name' => 'Pebble']);

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'Pebble looks at the reeds. Nothing it is carrying will cut them.'),
            );
    }

    /** With the axe in the bag, the same click gathers. */
    public function test_the_same_click_gathers_once_the_tool_is_held(): void
    {
        [$user, $companion] = $this->blunted();

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'Blob comes back with 4 fibre.'),
            );
    }
```

`CompanionClearingTest` already imports `CompanionController`, `User` and `Assert`, and already has `richUser()` and `touch()` — use them rather than adding new ones.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=needs_a_tool_says_so`
Expected: FAIL — the session carries the `full` line, not the `blunt` line.

- [ ] **Step 3: Branch in the controller**

In `app/Http/Controllers/CompanionNodeController.php`, `store()` becomes:

```php
    public function store(Request $request, string $node): RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        abort_unless(array_key_exists($node, $authored), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $entry = $authored[$node];
        $name = Companion::nameFor($user);

        if ($this->hasSkill($user, (string) $entry['skill'])) {
            $tool = (string) ($entry['tool'] ?? '');

            // Checked here as well as in HarvestNode, for the same reason the
            // skill is: the action's throw is the guarantee, and this is the
            // copy. Letting the action throw and catching it in `gather()`
            // would announce a full bag, which is a true sentence about the
            // wrong thing.
            if ($tool !== '' && ! $this->hasTool($user, $tool)) {
                return back()->with(
                    CompanionController::SAID_KEY,
                    $this->say($entry['blunt'] ?? '', $name),
                );
            }

            return $this->gather($user, $node, $entry, $name);
        }

        // Whether this is the FIRST look is the only thing that decides if the
        // bag opens: meeting a node Blob has already met reveals nothing, so it
        // interrupts nothing.
        $firstLook = $this->neverMet($user, $node);

        app(MeetNode::class)->handle($user, $node);

        return back()
            ->with(CompanionController::SAID_KEY, $this->say($entry['met'] ?? '', $name))
            ->with(CompanionController::REVEALED_KEY, $firstLook);
    }
```

And a sibling to `hasSkill()`, beside it:

```php
    private function hasTool(User $user, string $tool): bool
    {
        return Companion::query()
            ->where('user_id', $user->id)
            ->whereHas('items', fn ($query) => $query->where('item', $tool))
            ->exists();
    }
```

Extend the class docblock's `WITHOUT THE SKILL` / `WITH THE SKILL` pair with the third case:

```php
 * WITH THE SKILL BUT WITHOUT THE TOOL, Blob looks and leaves it. Nothing is
 * revealed — the skill list already has the row and the bag already has the
 * recipe — so nothing opens, and the line says what Blob is carrying rather
 * than what the reader should go and build.
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/CompanionClearingTest.php`
Expected: PASS, all of it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CompanionNodeController.php tests/Feature/Companion/CompanionClearingTest.php
git commit -m "feat(companion): say which of the two gates it was"
```

---

### Task 3: A recipe made of built things is knowable

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php:237-290`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `CompanionBag` lists a recipe whose ingredients are themselves knowable recipes

This is finding 1 in F2 §1 and it is an **F1 defect**, not a new requirement. `recipes()` asks whether every ingredient is the yield of a met node. Rope is built rather than gathered, so `axe = [deadfall 2, rope 1]` fails `array_diff` and can never be listed, however much rope Blob is carrying.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * An ingredient does not have to come out of the ground. A recipe whose
     * ingredients are themselves knowable recipes is knowable too — otherwise
     * nothing built from something built could ever be listed, however much of
     * it Blob is carrying.
     */
    public function test_a_recipe_made_of_built_things_is_knowable(): void
    {
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // rope from fibre, and the axe from deadfall and that rope.
        $this->assertContains('rope', $listed);
        $this->assertContains('axe', $listed);
    }

    /** The chain still has to bottom out in something Blob has actually met. */
    public function test_a_recipe_whose_chain_never_reaches_a_met_node_is_absent(): void
    {
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);

        $user = User::factory()->create();

        // The reeds only. Rope is reachable; the axe needs deadfall, which
        // Blob has never seen.
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertContains('rope', $listed);
        $this->assertNotContains('axe', $listed);
    }

    /**
     * Config is authored data, and an authored cycle would recurse forever on
     * an ordinary page load. Resolving by fixed point rather than by recursion
     * is what makes this terminate; this is the test that says so.
     */
    public function test_an_authored_recipe_cycle_terminates(): void
    {
        config()->set('companion.bag.knot', [
            'category' => 'consumable',
            'label' => 'knot',
            'recipe' => ['loop' => 1],
        ]);
        config()->set('companion.bag.loop', [
            'category' => 'consumable',
            'label' => 'loop',
            'recipe' => ['knot' => 1],
        ]);

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // Neither is reachable from a met node, and asking took finite time.
        $this->assertNotContains('knot', $listed);
        $this->assertNotContains('loop', $listed);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=made_of_built_things`
Expected: FAIL — `axe` is absent, because `rope` is not the yield of any node.

- [ ] **Step 3: Replace the knowability calculation**

In `app/Services/Companion/CompanionBag.php`, add this private method beside `recipes()`:

```php
    /**
     * Every item whose existence Blob can account for.
     *
     * Seeded from the materials met nodes yield, then grown: an item is
     * knowable if it is one of those, or if it is a recipe every one of whose
     * ingredients is already knowable. Without the second half, nothing built
     * from something built could ever be listed — an axe made from rope would
     * be invisible for as long as rope is a thing you make rather than a thing
     * you find.
     *
     * A FIXED POINT RATHER THAN RECURSION, and that is the whole reason this is
     * shaped the way it is. `config` is authored data; an authored `a -> b -> a`
     * pair would send a recursive resolver into a loop on an ordinary page
     * load. Each pass adds whatever became reachable and the loop stops when a
     * pass adds nothing, which is at most one pass per catalogue entry.
     *
     * @param  list<string>  $met
     * @return list<string>
     */
    private function knowable(array $met): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $known = [];

        foreach ($met as $node) {
            if (isset($nodes[$node]['yields'])) {
                $known[(string) $nodes[$node]['yields']] = true;
            }
        }

        do {
            $added = false;

            foreach ($catalogue as $name => $item) {
                if (isset($known[$name])) {
                    continue;
                }

                /** @var array<string, int> $recipe */
                $recipe = (array) ($item['recipe'] ?? []);

                if ($recipe === [] || array_diff(array_keys($recipe), array_keys($known)) !== []) {
                    continue;
                }

                $known[$name] = true;
                $added = true;
            }
        } while ($added);

        return array_keys($known);
    }
```

Then `recipes()` loses its `$metMaterials` block and its `$knowable` line. Its signature and the top of its body become:

```php
    /**
     * Recipes Blob can account for, whether or not it is carrying the
     * ingredients yet. Knowable, not held: the point of a recipe is to tell you
     * what the thing in front of you is for.
     *
     * @param  list<string>  $met
     * @param  Collection<string, CompanionItem>  $held
     * @return list<array{item: string, label: string, recipe: array<string, int>, buildable: bool}>
     */
    private function recipes(array $met, Collection $held): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        $knowable = $this->knowable($met);

        $listed = [];

        foreach ($catalogue as $name => $item) {
            /** @var array<string, int> $recipe */
            $recipe = (array) ($item['recipe'] ?? []);

            if ($recipe === [] || ! in_array($name, $knowable, true)) {
                continue;
            }

            $buildable = true;

            foreach ($recipe as $ingredient => $needed) {
                if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                    $buildable = false;

                    break;
                }
            }

            $listed[] = [
                'item' => $name,
                'label' => (string) $item['label'],
                'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                'buildable' => $buildable,
            ];
        }

        return $listed;
    }
```

Delete the now-unused `$nodes` lookup at the top of the old `recipes()` body — `knowable()` owns it.

- [ ] **Step 4: Run the bag's whole suite**

Run: `php artisan test --compact tests/Feature/Companion/CompanionBagTest.php`
Expected: PASS, all of it — including F1's `test_a_recipe_appears_once_its_ingredient_has_been_met`, unedited. The basket is still seeded straight from the reeds.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "fix(companion): an ingredient need not come out of the ground"
```

---

### Task 4: A tool is revealed by the thing it is for

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: Task 3's `knowable()`
- Produces: a recipe whose item is named as some node's `tool` is listed only once that node has been met

A tool for a thing you have never seen is a tool with no reason attached. The reverse lookup is over `config('companion.nodes')` and stores nothing.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * A tool's recipe waits for the thing it is for.
     *
     * The axe is knowable from deadfall and rope the moment both are met — but
     * listing it then would be a tool with no reason attached. Meeting the node
     * that needs it is what gives it one, and that is the same encounter that
     * puts the node's skill in the skill list: one click, both halves of what
     * the trunk needs.
     */
    public function test_a_tool_is_not_listed_until_its_node_has_been_met(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // Both of the axe's ingredients are knowable, and the axe is still not
        // listed, because Blob has never seen the trunk.
        $this->assertContains('rope', $listed);
        $this->assertNotContains('axe', $listed);

        app(MeetNode::class)->handle($user, 'trunk');

        $this->assertContains('axe', array_column($this->bag($user)['recipes'], 'item'));
    }

    /**
     * Meeting the trunk first, on a clearing where nothing else has been
     * touched, reveals the skill and no recipes at all — every chain here
     * bottoms out in fibre or deadfall. Nothing special-cases that: the lists
     * are what Blob has met, and it has met one thing.
     */
    public function test_meeting_only_the_trunk_reveals_a_skill_and_no_recipes(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');

        $bag = $this->bag($user);

        $this->assertSame(['chop-wood'], array_column($bag['skills'], 'skill'));
        $this->assertSame([], $bag['recipes']);
    }

    /** A recipe no node names is knowable from its ingredients alone. */
    public function test_a_recipe_no_node_needs_is_listed_on_its_ingredients_alone(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        // Rope gates nothing, so nothing has to be met for it beyond the fibre
        // it is made of.
        $this->assertContains('rope', array_column($this->bag($user)['recipes'], 'item'));
    }
```

And the helper, beside `richUser()`:

```php
    /**
     * A third node that needs both a skill and a tool, authored through config
     * so these cases test the mechanism rather than the day's content.
     */
    private function authorATrunk(): void
    {
        config()->set('companion.skills.chop-wood', [
            'price' => 20,
            'node' => 'trunk',
            'label' => 'chop wood',
        ]);
        config()->set('companion.nodes.trunk', [
            'skill' => 'chop-wood',
            'tool' => 'axe',
            'yields' => 'timber',
            'label' => 'the fallen trunk',
            'met' => '{name} climbs onto the fallen trunk and sits there a while.',
            'blunt' => '{name} looks at the trunk. Nothing it is carrying will bite into it.',
            'took' => '{name} drags back {count} timber.',
            'empty' => '{name} checks the trunk. There is nothing loose on it.',
            'full' => 'There is nowhere to put it. The timber stays by the trunk.',
        ]);
        config()->set('companion.bag.timber', ['category' => 'material', 'label' => 'timber']);
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=not_listed_until_its_node`
Expected: FAIL — the axe is listed as soon as its ingredients are knowable.

- [ ] **Step 3: Add the reverse lookup**

In `app/Services/Companion/CompanionBag.php`, beside `knowable()`:

```php
    /**
     * Items that gate a node, and whether Blob has seen what they are for.
     *
     * A tool's recipe is knowable from its ingredients like any other, but
     * listing it before Blob has met anything that needs it would be a tool
     * with no reason attached. Meeting that node is what supplies the reason —
     * and it is the same encounter that puts the node's skill in the skill
     * list, so one click reveals both halves of what the node needs.
     *
     * Met at ANY node that names it is enough: seeing one reason is seeing a
     * reason. Stores nothing — this is a read of authored config against what
     * Blob has already met.
     *
     * @param  list<string>  $met
     * @return array<string, bool>
     */
    private function toolsAndTheirReasons(array $met): array
    {
        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $tools = [];

        foreach ($nodes as $name => $node) {
            $tool = (string) ($node['tool'] ?? '');

            if ($tool === '') {
                continue;
            }

            $tools[$tool] = ($tools[$tool] ?? false) || in_array($name, $met, true);
        }

        return $tools;
    }
```

In `recipes()`, after `$knowable = $this->knowable($met);`:

```php
        $tools = $this->toolsAndTheirReasons($met);
```

and inside the loop, after the existing `continue`:

```php
            // Knowable, but nothing Blob has seen needs it yet.
            if (($tools[$name] ?? true) === false) {
                continue;
            }
```

- [ ] **Step 4: Run the bag's whole suite**

Run: `php artisan test --compact tests/Feature/Companion/CompanionBagTest.php`
Expected: PASS, all of it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): a tool waits for the thing it is for"
```

---

### Task 5: The trunk, the axe and the rope

**Files:**
- Modify: `config/companion.php`
- Test: `tests/Unit/Companion/CompanionContentTest.php`

**Interfaces:**
- Consumes: Tasks 1–4
- Produces: authored content — `skills.chop-wood`, `nodes.trunk`, `bag.timber`, `bag.rope`, `bag.axe`

Everything the mechanisms need now exists, so the content can land and be correct the moment it does.

- [ ] **Step 1: Write the failing content tests**

In `tests/Unit/Companion/CompanionContentTest.php`, **replace** `test_each_material_builds_exactly_one_container` with the narrowed version, and add the two new cases:

```php
    /**
     * F1's fork, kept and named.
     *
     * This was written as "each material builds exactly one container", which
     * was true of F1 and was never a law — it was F1 §1's claim that the fork
     * is real on day one, expressed as a test. F2 breaks the general form
     * truthfully: timber builds a tool and a material and no container at all.
     * The claim worth keeping is the specific one.
     */
    public function test_the_first_fork_is_still_real_on_day_one(): void
    {
        $config = $this->config();

        foreach (['fibre' => 'basket', 'deadfall' => 'barrow'] as $material => $expected) {
            $built = array_keys(array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container'
                    && array_key_exists($material, $item['recipe'] ?? []),
            ));

            $this->assertSame([$expected], $built, "{$material} should build exactly {$expected}");
        }
    }

    /**
     * Every tool a node or a recipe asks for is a thing that exists, is
     * categorised as a tool, and can actually be built. A tool named but not
     * buildable is a gate with no key.
     */
    public function test_every_tool_asked_for_exists_and_can_be_built(): void
    {
        $config = $this->config();

        $asked = [];

        foreach ($config['nodes'] as $name => $node) {
            if (isset($node['tool'])) {
                $asked[$node['tool']] = "node {$name}";
            }
        }

        foreach ($config['bag'] as $name => $item) {
            if (isset($item['tool'])) {
                $asked[$item['tool']] = "recipe {$name}";
            }
        }

        $this->assertNotEmpty($asked);

        foreach ($asked as $tool => $who) {
            $this->assertArrayHasKey($tool, $config['bag'], "{$who} needs a tool that does not exist");
            $this->assertSame('tool', $config['bag'][$tool]['category'], "{$who} needs something that is not a tool");
            $this->assertNotEmpty($config['bag'][$tool]['recipe'] ?? [], "[{$tool}] is a gate with no key");
        }
    }

    /** A tool is on the belt. It never occupies the room it lets you use. */
    public function test_a_tool_is_never_a_carried_category(): void
    {
        $this->assertNotContains('tool', $this->config()['capacity']['carried']);
    }
```

Then extend the copy-rules case to cover the new line — in `test_a_nodes_copy_follows_the_same_rules_as_the_ladders`, change the inner loop's list and add one assertion:

```php
            foreach (['met', 'blunt', 'full'] as $line) {
                if ($line === 'blunt' && ! array_key_exists($line, $node)) {
                    // Only a node that asks for a tool has a blunt line.
                    continue;
                }

                $this->assertArrayHasKey($line, $node, "{$name} has no {$line} line");
                $this->assertStringNotContainsString('!', $node[$line], "{$name}.{$line} exclaims");
                $this->assertStringNotContainsString('Blob', $node[$line], "{$name}.{$line} hardcodes the name");
            }

            if (isset($node['tool'])) {
                $this->assertArrayHasKey('blunt', $node, "{$name} asks for a tool and says nothing about it");
                $this->assertStringContainsString('{name}', $node['blunt'], "{$name}.blunt never names the companion");
                $this->assertStringNotContainsString(
                    $node['tool'],
                    $node['blunt'],
                    "{$name}.blunt names the tool, which is the app stating a plan",
                );
            }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Unit/Companion/CompanionContentTest.php`
Expected: FAIL — `test_every_tool_asked_for_exists_and_can_be_built` fails on `assertNotEmpty($asked)`, because no node or recipe asks for a tool yet.

- [ ] **Step 3: Author the skill**

In `config/companion.php`, add to `skills`:

```php
        'chop-wood' => ['price' => 20, 'node' => 'trunk', 'label' => 'chop wood'],
```

Extend that block's comment with the sentence that explains why F2 adds one skill rather than two:

```
    | Sawing is a recipe rather than a node, and a recipe never gates on a
    | skill — hands have never needed permission, they need the right thing in
    | them. So F2 adds one skill, not two. Pacing has never come from XP
    | breadth; it comes from the world, which now accrues across three nodes.
```

- [ ] **Step 4: Author the node**

Add to `nodes`, after `deadfall`:

```php
        'trunk' => [
            'skill' => 'chop-wood',
            // The second gate. A node may ask for a thing in hand as well as
            // for the record's permission, and this is the one place in the
            // feature where the two economic layers meet on one gesture.
            'tool' => 'axe',
            'yields' => 'timber',
            'label' => 'the fallen trunk',
            'met' => '{name} climbs onto the fallen trunk and sits there a while.',
            // Said when the skill is known and the tool is not held. It
            // describes what {name} is carrying and never names what to go and
            // build: the pile of deadfall and the recipe already in the bag
            // are the inference, and the app doing the saying is the one thing
            // this feature refuses.
            'blunt' => '{name} looks at the trunk. Nothing it is carrying will bite into it.',
            'took' => '{name} drags back {count} timber.',
            'empty' => '{name} checks the trunk. There is nothing loose on it.',
            'full' => 'There is nowhere to put it. The timber stays by the trunk.',
        ],
```

Extend that block's comment:

```
    | `blunt` is the fifth line and the only one a node without a `tool` key
    | does not need: it is what Blob does at a node it has the record's
    | permission for and not the thing in hand.
```

- [ ] **Step 5: Author the three items**

Add to `bag`, after `deadfall`:

```php
        'timber' => ['category' => 'material', 'label' => 'timber'],

        'rope' => [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ],
        'axe' => [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ],
```

Extend that block's comment:

```
    | `timber`, not `logs`. `logs` already means something two hundred lines up
    | — `'trigger' => 'logs'`, and `logCount` and `logMoments()` beyond this
    | file. A material by that name would sit here meaning something else
    | entirely, and every future grep for either would find both.
    |
    | A `tool` is on the belt: it is not in `capacity.carried`, so it never
    | occupies the room it lets you use. A tool is permanent, and a permanent
    | thing in a slot would be a permanent tax — gaining the axe would shrink
    | the bag forever, which is regression in everything but name.
```

- [ ] **Step 6: Update the three assertions that name the world whole**

A third node in config changes what `CompanionBag` returns, and three tests assert that list exactly. **This is the assertion doing its job, not a reason to loosen it** — add the trunk, do not switch to `assertContains`.

In `tests/Feature/Companion/CompanionBagTest.php`:

```php
    // test_an_untouched_account_is_empty_rather_than_absent
    $this->assertSame(['reeds', 'deadfall', 'trunk'], array_column($bag['nodes'], 'node'));
    $this->assertSame([false, false, false], array_column($bag['nodes'], 'met'));
    $this->assertSame([0, 0, 0], array_column($bag['nodes'], 'available'));

    // test_another_users_bag_is_not_this_one
    $this->assertSame([false, false, false], array_column($bag['nodes'], 'met'));
    $this->assertSame([0, 0, 0], array_column($bag['nodes'], 'available'));
```

And `test_the_nodes_carry_what_is_standing_at_them` gains a third expected entry:

```php
            [
                'node' => 'trunk',
                'label' => 'the fallen trunk',
                'available' => 0,
                'skill' => 'chop-wood',
                'met' => false,
                'known' => false,
            ],
```

`CompanionClearingTest`'s `->where('bag.nodes.0.node', 'reeds')` and `->where('bag.nodes.1.node', 'deadfall')` are index-addressed and unaffected — the trunk is appended after them, because `nodes()` walks config in authored order and Task 4 of Batch 1 authored it last.

- [ ] **Step 7: Run the content tests and the whole companion suite**

Run: `php artisan test --compact tests/Unit/Companion tests/Feature/Companion`
Expected: PASS, all of it.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php tests/Unit/Companion/CompanionContentTest.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): a trunk, and something sharp enough for it"
```

---

### Task 6: The three refusals, guarded

**Files:**
- Create: `tests/Feature/Companion/CompanionRulingsTest.php`

**Interfaces:**
- Consumes: Task 5's content
- Produces: nothing the application reads — three tests that fail if a ruling is reversed

F2 §6 refuses three things. A ruling nobody can falsify erodes, and the insight one is the most at risk: a later phase could add insight stocking by accident while implementing something else.

- [ ] **Step 1: Write the file**

```php
<?php

namespace Tests\Feature\Companion;

use App\Actions\ConcludeExperiment;
use App\Actions\LogAction;
use App\Actions\UpdateIntention;
use App\Actions\WriteReflection;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three things F2 refuses, each written as a test that fails if the
 * refusal is ever quietly reversed.
 *
 * None of these asserts a feature. They assert the ABSENCE of one, which is
 * why they exist: a ruling recorded only in prose erodes, and each of these
 * three is the kind a later phase could undo while meaning to do something
 * else entirely.
 */
class CompanionRulingsTest extends TestCase
{
    use RefreshDatabase;

    private int $occasion = 0;

    private function clearing(): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        return [$user, $companion];
    }

    private function logOnce(User $user): void
    {
        $loop = Intention::factory()->for($user)->create();

        $action = Action::factory()
            ->for($loop)
            ->for(Strategy::factory()->for($loop))
            ->create();

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subMinutes(++$this->occasion),
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);
    }

    private function standing(Companion $companion, string $node): int
    {
        return (int) $companion->nodes()->where('node', $node)->value('available');
    }

    /**
     * There is no insight event in this application: the four kinds are
     * DERIVED from database state by CompanionResolver::insightMoments(), with
     * no seam to listen to. Stocking from them would mean new domain events
     * and a second opinion about what happened, alongside a resolver that
     * already derives it — architecture for a pacing adjustment the world does
     * not need, now that it accrues across three nodes.
     *
     * XP is where insights are paid, generously and outside the taper. This is
     * the guard that keeps the ruling from being undone by accident.
     */
    public function test_insight_events_do_not_stock_the_world(): void
    {
        [$user, $companion] = $this->clearing();

        $before = $this->standing($companion, 'reeds');

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);

        // A reflection written.
        app(WriteReflection::class)->handle($loop, 'Still reads as a cue problem.');

        // A loop's chain corrected.
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);

        // An experiment concluded.
        $strategy = Strategy::factory()->for($loop)->create(['version' => 1]);
        app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED, 'What the evidence showed.');

        // A new strategy version started, written as rows because that is what
        // the resolver reads — a child strategy with a parent.
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'parent_strategy_id' => $strategy->id,
        ]);

        $this->assertSame($before, $this->standing($companion->fresh(), 'reeds'));
    }

    /**
     * A tool is on the belt. It is permanent, so a tool that occupied a slot
     * would be a permanent tax — gaining the axe would shrink the bag forever,
     * which is regression in everything but name.
     */
    public function test_a_tool_takes_up_no_room(): void
    {
        [, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(2, $fresh->held());
        $this->assertSame(5, $fresh->capacity());

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $withTheAxe = $companion->fresh()->load('items');

        // The axe changed nothing about what can be carried, in either
        // direction. It neither costs room nor creates it.
        $this->assertSame(2, $withTheAxe->held());
        $this->assertSame(5, $withTheAxe->capacity());
        $this->assertSame(3, $withTheAxe->room());
    }

    /**
     * Nothing wears out and nothing is removed for inactivity. The only thing
     * in this feature that ever reduces a quantity is a recipe consuming it,
     * and this walks a week of the record past a bag to say so.
     */
    public function test_nothing_is_taken_by_the_passage_of_the_record(): void
    {
        [$user, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        for ($index = 0; $index < 7; $index++) {
            $this->logOnce($user);
        }

        $fresh = $companion->fresh();

        $this->assertSame(1, (int) $fresh->items()->where('item', 'axe')->value('quantity'));
        $this->assertSame(4, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));

        // And the world only ever grew.
        $this->assertGreaterThan(3, $this->standing($fresh, 'reeds'));
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Companion/CompanionRulingsTest.php`
Expected: PASS. These characterize behaviour that is already correct; they exist so it stays correct.

If `test_insight_events_do_not_stock_the_world` fails, something already stocks from insights and that is the finding — do not weaken the test.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Companion/CompanionRulingsTest.php
git commit -m "test(companion): make the three refusals falsifiable"
```

---

### Task 7: The economy's own files join the vocabulary scan

**Files:**
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php:29-119`
- Modify: `app/Services/Companion/CompanionBag.php:18-21`

**Interfaces:**
- Consumes: nothing
- Produces: nothing — closes a gap

`CompanionVocabularyTest::sourceFiles()` scans `companion-bag.tsx` but not one PHP file of the economy behind it. `CompanionBag`, `CompanionWallet`, `CompanionEconomyException` and the four Actions are currently scanned by nothing, and BLOB.md's rule is that a companion source file absent from that list is a file no rule applies to. F2 edits three of them.

**This task will go red before it goes green, and that is the point.** `CompanionBag`'s own docblock contains the word *percentage*.

- [ ] **Step 1: Add the files to the list**

In `tests/Feature/Companion/CompanionVocabularyTest.php`, inside `sourceFiles()`, after the `CompanionRemarks.php` entry:

```php
            // The economy behind the bag. Scanned by nothing until F2, which
            // is exactly the gap BLOB.md warns about: a companion source file
            // absent from this list is one no rule applies to, and this
            // project has been bitten by that before.
            $root.'/app/Services/Companion/CompanionBag.php',
            $root.'/app/Services/Companion/CompanionWallet.php',
            $root.'/app/Services/Companion/CompanionEconomyException.php',
            $root.'/app/Actions/LearnSkill.php',
            $root.'/app/Actions/MeetNode.php',
            $root.'/app/Actions/HarvestNode.php',
            $root.'/app/Actions/BuildItem.php',
            $root.'/app/Listeners/StockCompanionNodes.php',
            $root.'/app/Http/Controllers/CompanionNodeController.php',
            $root.'/app/Http/Controllers/CompanionSkillController.php',
            $root.'/app/Http/Controllers/CompanionBuildController.php',
            $root.'/app/Http/Controllers/CompanionNameController.php',
```

- [ ] **Step 2: Run it to see what the gap was hiding**

Run: `php artisan test --compact tests/Feature/Companion/CompanionVocabularyTest.php`
Expected: FAIL — `CompanionBag.php says "percent"`, from its own class docblock: *"no percentage and no 'next'"*.

If any other file trips, reword it the same way in Step 3. Do not remove a file from the list to make the suite green; that is the failure mode this task exists to correct.

- [ ] **Step 3: Reword the docblock**

In `app/Services/Companion/CompanionBag.php`, the class docblock's second paragraph:

```php
 * WHAT IS ABSENT HERE IS THE DESIGN. There is no count of skills that exist, no
 * count of nodes that exist, no share of a whole and no "next". The payload is
 * where a total would first appear — before any pixel is drawn — so the shape
 * itself is guarded by CompanionBagTest, not just the rendering.
```

- [ ] **Step 4: Run it again**

Run: `php artisan test --compact tests/Feature/Companion/CompanionVocabularyTest.php`
Expected: PASS, all twelve providers.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Companion/CompanionVocabularyTest.php app/Services/Companion/CompanionBag.php
git commit -m "test(companion): scan the economy, not just the surface over it"
```

---

**STOP. Batch 1 is complete. Run the full suite and hand back for review.**

Run: `php artisan test --compact`

---

# BATCH 2 — the recipe gate

---

### Task 8: A recipe can require a tool

**Files:**
- Modify: `app/Actions/BuildItem.php:33-95`
- Test: `tests/Feature/Companion/BuildItemTest.php`

**Interfaces:**
- Consumes: Task 1's `CompanionEconomyException::toolNotHeld()`
- Produces: `BuildItem` reads the optional `bag.*.tool`

The other half of F2's rule. A recipe gates on a tool and **never** on a skill: hands have never needed permission, they need the right thing in them.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/BuildItemTest.php`:

```php
    /**
     * The second half of F2's rule: a recipe may ask for a tool. It never asks
     * for a skill — see test_building_needs_no_skill, which stays exactly as
     * it is, because the basket still needs none.
     */
    public function test_a_recipe_that_needs_a_tool_refuses_without_it(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user, $companion] = $this->carrying(['fibre' => 4]);

        try {
            app(BuildItem::class)->handle($user, 'basket');

            $this->fail('Building without the tool should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('handsaw', $exception->getMessage());
        }

        // Materials transform; they are never taken. A refusal takes nothing.
        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $companion->items()->where('item', 'basket')->count());
    }

    /** With the tool held, the recipe builds as it always did. */
    public function test_a_recipe_that_needs_a_tool_builds_once_it_is_held(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user, $companion] = $this->carrying(['fibre' => 4, 'handsaw' => 1]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
        // The tool is used, never used UP. Nothing here wears out.
        $this->assertSame(1, $this->held($companion, 'handsaw'));
    }

    /**
     * The tool is named before the materials. It is the harder of the two to
     * come by, and naming the nearer obstacle first would send the reader back
     * for fibre they still could not use.
     */
    public function test_the_tool_is_named_before_the_shortfall(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user] = $this->carrying(['fibre' => 1]);

        try {
            app(BuildItem::class)->handle($user, 'basket');

            $this->fail('Building should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('handsaw', $exception->getMessage());
            $this->assertStringNotContainsString('fibre', $exception->getMessage());
        }
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=recipe_that_needs_a_tool`
Expected: FAIL — the basket builds regardless.

- [ ] **Step 3: Read the key in `BuildItem`**

In `app/Actions/BuildItem.php`, after the recipe is read:

```php
        /** @var array<string, int> $recipe */
        $recipe = (array) ($catalogue[$item]['recipe'] ?? []);

        if ($recipe === []) {
            throw new InvalidArgumentException("[{$item}] has no recipe; it is gathered, not built.");
        }

        $tool = (string) ($catalogue[$item]['tool'] ?? '');
```

Widen the closure's `use` and add the check as the first thing inside the transaction:

```php
        return DB::transaction(function () use ($user, $item, $recipe, $tool): CompanionItem {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            // A recipe gates on a tool and NEVER on a skill: assembling by hand
            // is what hands are for, and what a hand needs is the right thing
            // in it. Checked before the materials because the tool is the
            // harder of the two to come by.
            if ($tool !== '' && $companion->items()->where('item', $tool)->doesntExist()) {
                throw CompanionEconomyException::toolNotHeld($item, $tool);
            }

            $stacks = $companion->items()
```

And the method's `@throws`:

```php
     * @throws CompanionEconomyException when the tool is not held or the
     *                                   materials are short.
```

Add a paragraph to the class docblock, replacing the line that currently reads *"Building needs no skill."*:

```php
 * Building needs no skill, and it never will. Assembling by hand is what hands
 * are for — but a hand may need the right thing in it, which is why a recipe
 * can name a `tool`. That is the whole of F2's rule from this side: a node
 * gates on skill and tool, a recipe gates on tool alone.
 *
 * A tool is USED, never used up. It is not an ingredient and nothing consumes
 * it; the check is that it is held, and the bag is unchanged by it afterwards.
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/BuildItemTest.php`
Expected: PASS, all of it — including `test_building_needs_no_skill`, unedited.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/BuildItem.php tests/Feature/Companion/BuildItemTest.php
git commit -m "feat(companion): a recipe can ask for a tool, never for a skill"
```

---

### Task 9: A recipe can make more than one

**Files:**
- Modify: `app/Actions/BuildItem.php`
- Test: `tests/Feature/Companion/BuildItemTest.php`

**Interfaces:**
- Consumes: Task 8
- Produces: `BuildItem` reads the optional `bag.*.makes`, defaulting to 1

`makes`, not `yields`: `nodes.*.yields` already exists and holds the *name of a material*. The same word holding an integer in the adjacent config block is two types under one name.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/BuildItemTest.php`:

```php
    /** One timber, three planks. The count is config's to say. */
    public function test_a_recipe_can_make_more_than_one(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);

        [$user, $companion] = $this->carrying(['fibre' => 2]);

        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(3, $this->held($companion, 'planks'));
        $this->assertSame(1, $this->held($companion, 'fibre'));
    }

    /** Building the same thing twice stacks what it makes. */
    public function test_making_several_twice_stacks_them(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);
        config()->set('companion.capacity.base', 20);

        [$user, $companion] = $this->carrying(['fibre' => 2]);

        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(6, $this->held($companion, 'planks'));
        $this->assertSame(1, $companion->items()->where('item', 'planks')->count());
    }

    /** A recipe that does not say makes one, exactly as every F1 recipe does. */
    public function test_a_recipe_with_no_count_makes_one(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=make_more_than_one`
Expected: FAIL — one plank is made, not three.

- [ ] **Step 3: Read the key**

In `app/Actions/BuildItem.php`, beside the `$tool` line:

```php
        // `makes`, not `yields`: `nodes.*.yields` already holds the NAME of a
        // material, and the same word holding a count in the adjacent config
        // block would be two types under one name.
        //
        // Floored at one: a recipe that makes nothing is a config mistake, and
        // silently making nothing is worse than making the default.
        $makes = max(1, (int) ($catalogue[$item]['makes'] ?? 1));
```

Widen the closure's `use` to include `$makes`, and change the final increment:

```php
            $built = $companion->items()->firstOrCreate(['item' => $item], ['quantity' => 0]);

            $built->increment('quantity', $makes);

            return $built;
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/BuildItemTest.php`
Expected: PASS, all of it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/BuildItem.php tests/Feature/Companion/BuildItemTest.php
git commit -m "feat(companion): a recipe says how many it makes"
```

---

### Task 10: A build cannot overflow the bag

**Files:**
- Modify: `app/Services/Companion/CompanionEconomyException.php`
- Modify: `app/Actions/BuildItem.php`
- Test: `tests/Feature/Companion/BuildItemTest.php`

**Interfaces:**
- Consumes: Task 9's `$makes`
- Produces: `CompanionEconomyException::noRoomFor(string $thing): self`

F1 never needed this: a container is not carried, so every F1 build was net-negative on `held`. A recipe that makes three carried things out of one is net **+2**, and nothing stops it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/BuildItemTest.php`:

```php
    /**
     * A build that would not fit refuses WHOLE. Nothing is consumed and
     * nothing is partially built — a half-consumed recipe would be the first
     * thing in this feature that ever took something away.
     */
    public function test_a_build_that_would_not_fit_refuses_and_consumes_nothing(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);

        // Base capacity 5, and four fibre already in it. Spending one leaves
        // three held, and three planks would make six.
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        try {
            app(BuildItem::class)->handle($user, 'planks');

            $this->fail('Building past capacity should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('planks', $exception->getMessage());
        }

        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $companion->items()->where('item', 'planks')->count());
    }

    /** It fits the moment there is room for the net gain, not for the whole. */
    public function test_a_build_fits_when_there_is_room_for_the_net_gain(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);

        // Three fibre in a bag of five. Spending one leaves two held; three
        // planks makes five, which is exactly full.
        [$user, $companion] = $this->carrying(['fibre' => 3]);

        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(3, $this->held($companion, 'planks'));
        $this->assertSame(2, $this->held($companion, 'fibre'));
        $this->assertSame(5, $companion->fresh()->load('items')->held());
    }

    /**
     * A tool is not carried, so a recipe that makes one can never overflow
     * however full the bag is.
     */
    public function test_building_a_tool_can_never_overflow(): void
    {
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['fibre' => 1],
        ]);

        [$user, $companion] = $this->carrying(['fibre' => 5]);

        app(BuildItem::class)->handle($user, 'axe');

        $this->assertSame(1, $this->held($companion, 'axe'));
        $this->assertSame(4, $companion->fresh()->load('items')->held());
    }

    /** A container does not occupy the room it creates, so nor can it overflow. */
    public function test_building_a_container_can_never_overflow(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 5]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
        $this->assertSame(1, $companion->fresh()->load('items')->held());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=would_not_fit`
Expected: FAIL — the build succeeds and the bag holds six against a capacity of five.

- [ ] **Step 3: Add the refusal**

In `app/Services/Companion/CompanionEconomyException.php`, beside `bagIsFull()`:

```php
    /**
     * A build that would not fit.
     *
     * A second factory rather than a reworded `bagIsFull()`: the two say
     * different sentences about different things — one is about what stays
     * standing at a node, this is about a thing that cannot be put down — and
     * rewording the other would edit a message the clearing already ships.
     */
    public static function noRoomFor(string $thing): self
    {
        return new self("There is no room left in the bag for [{$thing}].");
    }
```

- [ ] **Step 4: Add the check**

In `app/Actions/BuildItem.php`, inside the transaction, **after** the `$missing` check and **before** the loop that consumes:

```php
            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($item, $missing);
            }

            // Capacity last, because you cannot overflow with materials you do
            // not have — a short recipe should say what it is short of rather
            // than complain about room.
            //
            // Only CARRIED categories count, on both sides. A recipe that makes
            // a tool or a container is always net-negative and can never
            // refuse here; one that makes three carried things out of one is
            // net +2, and that is the case this exists for.
            $companion->load('items');

            $carried = (array) config('companion.capacity.carried', []);

            $categoryOf = static fn (string $name): string => (string) ($catalogue[$name]['category'] ?? '');

            $consumed = 0;

            foreach ($recipe as $ingredient => $needed) {
                if (in_array($categoryOf($ingredient), $carried, true)) {
                    $consumed += $needed;
                }
            }

            $made = in_array($categoryOf($item), $carried, true) ? $makes : 0;

            if ($companion->held() - $consumed + $made > $companion->capacity()) {
                throw CompanionEconomyException::noRoomFor($item);
            }
```

Widen the closure's `use` to include `$catalogue`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Companion/BuildItemTest.php`
Expected: PASS, all of it.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionEconomyException.php app/Actions/BuildItem.php tests/Feature/Companion/BuildItemTest.php
git commit -m "feat(companion): a build that would not fit takes nothing"
```

---

### Task 11: The tool is part of the price

**Files:**
- Modify: `app/Services/Companion/CompanionBag.php`
- Test: `tests/Feature/Companion/CompanionBagTest.php`

**Interfaces:**
- Consumes: Task 8's `bag.*.tool`
- Produces: `recipes[]` rows gain `tool: string|null`, and `buildable` accounts for it

A recipe has always been a price rather than a requirement, and a tool is part of what the thing costs. `4 fibre` names something Blob may not have and has since F1; a tool is the same kind of statement.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Companion/CompanionBagTest.php`:

```php
    /**
     * A recipe carries its tool as part of its price. Not a "requires" and not
     * a lock: the row stays readable and only the button is disabled, exactly
     * as an unaffordable skill stays listed with its price.
     */
    public function test_a_recipe_carries_its_tool_as_part_of_the_price(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $this->assertSame([[
            'item' => 'basket',
            'label' => 'basket',
            'recipe' => ['fibre' => 4],
            'tool' => 'handsaw',
            'buildable' => false,
        ]], $this->bag($user)['recipes']);
    }

    /** A recipe that needs nothing in hand says so with null, never an empty string. */
    public function test_a_recipe_that_needs_no_tool_carries_null(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $this->assertNull($this->bag($user)['recipes'][0]['tool']);
    }

    /** Holding the materials is not enough when the tool is missing. */
    public function test_buildable_stays_false_without_the_tool(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        $this->assertFalse($this->bag($user)['recipes'][0]['buildable']);

        $user->companion->items()->create(['item' => 'handsaw', 'quantity' => 1]);

        $this->assertTrue($this->bag($user)['recipes'][0]['buildable']);
    }
```

`test_a_recipe_appears_once_its_ingredient_has_been_met` and `test_buildable_flips_when_the_materials_are_there` both assert the recipe row whole. Add `'tool' => null` to their expected arrays — the shape assertion doing its job.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=tool_as_part_of_the_price`
Expected: FAIL — the row has no `tool` key.

- [ ] **Step 3: Carry the tool**

In `app/Services/Companion/CompanionBag.php`, update `recipes()`'s return docblock and the `forUser()` array-shape docblock to

```php
     *     recipes: list<array{item: string, label: string, recipe: array<string, int>, tool: string|null, buildable: bool}>,
```

and inside `recipes()`'s loop, replace the `$buildable` block and the row:

```php
            $tool = (string) ($item['tool'] ?? '');

            // Held, not consumed. A tool is used and never used up, so this
            // asks whether it is in the bag and takes nothing from it.
            $buildable = $tool === '' || (int) ($held->get($tool)?->quantity ?? 0) > 0;

            if ($buildable) {
                foreach ($recipe as $ingredient => $needed) {
                    if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                        $buildable = false;

                        break;
                    }
                }
            }

            $listed[] = [
                'item' => $name,
                'label' => (string) $item['label'],
                'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                // Null rather than '' so the client has one falsy case to test
                // and never has to tell an absent tool from an empty one.
                'tool' => $tool === '' ? null : $tool,
                'buildable' => $buildable,
            ];
```

- [ ] **Step 4: Run the bag's whole suite**

Run: `php artisan test --compact tests/Feature/Companion/CompanionBagTest.php`
Expected: PASS, all of it — including `test_the_payload_names_no_total_and_no_next`, because `tool` matches none of `/total|count|percent|progress|next|remaining|locked|streak/i`.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Companion/CompanionBag.php tests/Feature/Companion/CompanionBagTest.php
git commit -m "feat(companion): a tool is part of what a thing costs"
```

---

### Task 12: The handsaw, the planks and the crate

**Files:**
- Modify: `config/companion.php`
- Test: `tests/Unit/Companion/CompanionContentTest.php`

**Interfaces:**
- Consumes: Tasks 8–11
- Produces: authored content — `bag.handsaw`, `bag.planks`, `bag.crate`

This closes the chain: `fibre → rope → axe → timber → handsaw → planks → crate`.

- [ ] **Step 1: Write the failing content test**

Append to `tests/Unit/Companion/CompanionContentTest.php`:

```php
    /**
     * No material is a dead end.
     *
     * This is what replaces the general form of the old container rule. A
     * material or consumable nothing consumes is content that leads nowhere,
     * and it is the property worth holding across every later phase — unlike
     * "exactly one container", which was only ever true of F1.
     *
     * Tools and containers are terminal on purpose: they are what the chain is
     * FOR.
     */
    public function test_no_material_is_a_dead_end(): void
    {
        $config = $this->config();

        $consumed = [];

        foreach ($config['bag'] as $item) {
            foreach (array_keys($item['recipe'] ?? []) as $ingredient) {
                $consumed[$ingredient] = true;
            }
        }

        foreach ($config['bag'] as $name => $item) {
            if (! in_array($item['category'], ['material', 'consumable'], true)) {
                continue;
            }

            $this->assertArrayHasKey($name, $consumed, "[{$name}] is carried and nothing is made from it");
        }
    }

    /** A recipe that makes several says so with a count above one. */
    public function test_a_stated_count_is_a_real_count(): void
    {
        foreach ($this->config()['bag'] as $name => $item) {
            if (! array_key_exists('makes', $item)) {
                continue;
            }

            $this->assertGreaterThan(1, $item['makes'], "[{$name}] states a count of one, which is the default");
        }
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=dead_end`
Expected: FAIL — `[timber] is carried and nothing is made from it`.

- [ ] **Step 3: Author the three items**

In `config/companion.php`, add to `bag`, after `axe`:

```php
        'handsaw' => [
            'category' => 'tool',
            'label' => 'handsaw',
            'recipe' => ['timber' => 2, 'rope' => 1],
        ],
        'planks' => [
            'category' => 'material',
            'label' => 'planks',
            // The recipe gate. Sawing is hands-work, so it asks for nothing
            // the record has to grant — only for the thing that does the
            // sawing.
            'tool' => 'handsaw',
            'recipe' => ['timber' => 1],
            'makes' => 3,
        ],
        'crate' => [
            'category' => 'container',
            'label' => 'crate',
            'recipe' => ['planks' => 4],
            'capacity' => 5,
        ],
```

- [ ] **Step 4: Run the content tests and the whole companion suite**

Run: `php artisan test --compact tests/Unit/Companion tests/Feature/Companion`
Expected: PASS, all of it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php tests/Unit/Companion/CompanionContentTest.php
git commit -m "feat(companion): timber into planks, and somewhere to put them"
```

---

**STOP. Batch 2 is complete. Run the full suite and hand back for review.**

Run: `php artisan test --compact`

---

# BATCH 3 — the clearing

---

### Task 13: The tool renders in the recipe's price

**Files:**
- Modify: `resources/js/patyourself/companion.tsx:73-79`
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Modify: `resources/js/patyourself/companion-bag.tsx:129-175`
- Test: `resources/js/patyourself/companion-bag.test.tsx`

**Interfaces:**
- Consumes: Task 11's `recipes[].tool`
- Produces: `BagRecipeData.tool: string | null`

- [ ] **Step 1: Write the failing test**

Append to the `describe('the bag', ...)` block in `resources/js/patyourself/companion-bag.test.tsx`:

```tsx
    /**
     * A recipe is a PRICE, and a tool is part of what the thing costs. Not a
     * "requires" line, not an explanation and not a greyed row — the row stays
     * readable and the button alone is disabled, exactly as an unaffordable
     * skill stays listed with its price.
     */
    it('renders a recipe tool as part of the price', () => {
        open(
            bag({
                recipes: [
                    {
                        item: 'planks',
                        label: 'planks',
                        recipe: { timber: 1 },
                        tool: 'handsaw',
                        buildable: false,
                    },
                ],
            }),
        );

        expect(
            screen.getByRole('button', { name: '1 timber, handsaw' }),
        ).toBeDisabled();
    });

    /** A recipe needing nothing in hand prices only its ingredients. */
    it('prices a toolless recipe by its ingredients alone', () => {
        open(
            bag({
                recipes: [
                    {
                        item: 'basket',
                        label: 'basket',
                        recipe: { fibre: 4 },
                        tool: null,
                        buildable: true,
                    },
                ],
            }),
        );

        expect(
            screen.getByRole('button', { name: '4 fibre' }),
        ).toBeEnabled();
    });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/companion-bag.test.tsx`
Expected: FAIL — TypeScript rejects `tool` on `BagRecipeData`, and the button is named `1 timber`.

- [ ] **Step 3: Widen the type**

In `resources/js/patyourself/companion.tsx`:

```tsx
/** Something buildable out of things Blob has met. */
export interface BagRecipeData {
    item: string;
    label: string;
    recipe: Record<string, number>;
    /**
     * What has to be in hand, or null. Part of the PRICE rather than a
     * requirement — a tool is the same kind of statement as `4 fibre`, which
     * has named something Blob may not have since F1.
     */
    tool: string | null;
    buildable: boolean;
}
```

- [ ] **Step 4: Fix the rows the new key breaks**

`bag()` in `resources/js/patyourself/companion.fixture.ts` needs no change — `recipes` defaults to `[]`. But two inline recipe rows in `companion-bag.test.tsx` now fail to typecheck, at roughly lines 57–62 and 144–149. Both are `basket` rows; add `tool: null` to each:

```tsx
                    {
                        item: 'basket',
                        label: 'basket',
                        recipe: { fibre: 4 },
                        tool: null,
                        buildable: false,
                    },
```

No fixture helper for recipe rows: there are two call sites, and a helper used twice to save one line is indirection rather than reuse.

- [ ] **Step 5: Render it**

In `resources/js/patyourself/companion-bag.tsx`, inside `Build`'s button, replace the label expression:

```tsx
                                    <button
                                        type="submit"
                                        className="pixel-button"
                                        disabled={
                                            processing || !recipe.buildable
                                        }
                                    >
                                        {[
                                            ...Object.entries(
                                                recipe.recipe,
                                            ).map(
                                                ([item, count]) =>
                                                    `${count} ${item}`,
                                            ),
                                            // Last, and named plainly. A tool
                                            // is part of the price, so it sits
                                            // in the price and gets no label,
                                            // no "requires" and no explanation
                                            // of its own.
                                            ...(recipe.tool === null
                                                ? []
                                                : [recipe.tool]),
                                        ].join(', ')}
                                    </button>
```

- [ ] **Step 6: Run the bag's tests and the page's guard**

Run: `npx vitest run resources/js/patyourself/companion-bag.test.tsx resources/js/pages/companion.test.tsx`
Expected: PASS, all of it — including `never shows what has not happened`, **both times it runs**, bag closed and bag open.

- [ ] **Step 7: Lint the files you touched, and only those**

Run: `npx eslint resources/js/patyourself/companion.tsx resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.fixture.ts`
Expected: clean. Never run `npm run lint` from the repo root.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/companion.tsx resources/js/patyourself/companion-bag.tsx resources/js/patyourself/companion.fixture.ts resources/js/patyourself/companion-bag.test.tsx
git commit -m "feat(companion): price a recipe by its tool as well as its parts"
```

---

### Task 14: The trunk stands in the clearing

**Files:**
- Modify: `resources/js/patyourself/scenes.ts:142-146`
- Modify: `resources/js/patyourself/companion.fixture.ts`
- Test: `resources/js/pages/companion.test.tsx`

**Interfaces:**
- Consumes: Task 5's `nodes.trunk`
- Produces: a third hotspot in `SCENES.forest.nodes`

`pages/companion.tsx` already maps `sceneFor(scene).nodes` against `bag.nodes` and renders a `NodeSpot` for each match. Nothing there changes: the scene registry and the fixture are the whole of it.

- [ ] **Step 1: Write the failing test**

Append to `resources/js/pages/companion.test.tsx`, in whichever `describe` block holds the existing node cases:

```tsx
    /**
     * Three things stand in the clearing, all of them from the start. A node
     * you cannot use looks exactly like one you can — that is the whole
     * discovery mechanic, and a lock would undo it.
     */
    it('stands the trunk in the clearing beside the other two', () => {
        render(
            <CompanionPage
                companion={companion({ scene: 'forest' })}
                bag={bag({
                    nodes: [
                        {
                            node: 'deadfall',
                            label: 'the fallen branches',
                            available: 0,
                            skill: 'gather-wood',
                            met: false,
                            known: false,
                        },
                        {
                            node: 'reeds',
                            label: 'the reeds',
                            available: 0,
                            skill: 'gather-fibre',
                            met: false,
                            known: false,
                        },
                        {
                            node: 'trunk',
                            label: 'the fallen trunk',
                            available: 0,
                            skill: 'chop-wood',
                            met: false,
                            known: false,
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'the fallen trunk' }),
        ).toBeEnabled();
    });
```

There is no `renderPage` helper in that file — every case calls `render(<CompanionPage bag={bag()} companion={companion()} />)` directly, and this follows it. `scene: 'forest'` is explicit because the clearing's nodes are drawn outdoors only.

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/pages/companion.test.tsx`
Expected: FAIL — the scene knows of two nodes, so nothing renders for `trunk`.

- [ ] **Step 3: Place it in the scene**

In `resources/js/patyourself/scenes.ts`:

```ts
        nodes: [
            { node: 'deadfall', at: [-46, 40] },
            { node: 'reeds', at: [44, 36] },
            // Low and near, between the tree's foot and the centre, so it
            // reads as foreground without standing where Blob does.
            { node: 'trunk', at: [-24, 52] },
        ],
```

- [ ] **Step 4: Add it to the fixture**

In `resources/js/patyourself/companion.fixture.ts`, append to `bag()`'s `nodes` array:

```ts
            {
                node: 'trunk',
                label: 'the fallen trunk',
                available: 0,
                skill: 'chop-wood',
                met: false,
                known: false,
            },
```

- [ ] **Step 5: Run the page's tests**

Run: `npx vitest run resources/js/pages/companion.test.tsx resources/js/patyourself/companion-room.test.tsx`
Expected: PASS, all of it. If a case asserts on the number of hotspots, it will need the third — that is the assertion doing its job, not a reason to skip the fixture.

- [ ] **Step 6: Look at it**

Run: `npm run build`, then open `/companion` with `COMPANION_SCENE=forest` and **look at where the trunk sits.**

BLOB.md trap 4: class-name assertions and geometry cannot judge a visual — the shoes shipped into the bottom-left corner with 340 tests green. If `[-24, 52]` overlaps Blob, the grass or the deadfall, move it and look again. The coordinate in Step 3 is a starting point, not a measurement.

Herd serves the main checkout and never a worktree (BLOB.md trap 8). From here, dump the page to HTML and serve it with `php -S 127.0.0.1:8899 -t .`, or check the change from the main checkout after the merge.

- [ ] **Step 7: Lint and commit**

```bash
npx eslint resources/js/patyourself/scenes.ts resources/js/patyourself/companion.fixture.ts
git add resources/js/patyourself/scenes.ts resources/js/patyourself/companion.fixture.ts resources/js/pages/companion.test.tsx
git commit -m "feat(companion): a third thing standing in the clearing"
```

---

### Task 15: Bring `docs/BLOB.md` current

**Files:**
- Modify: `docs/BLOB.md`

**Interfaces:**
- Consumes: everything above
- Produces: nothing the application reads

`docs/BLOB.md` declares *"where this file and a spec disagree, this file is right"* and predates F1. A reference that claims precedence and is stale is worse than no reference. **Its own commit**, so the documentation fix is reviewable apart from the behaviour.

- [ ] **Step 1: Correct §3**

It opens *"There is no companion table. Nothing about Blob is stored, so there is nothing to backfill, nothing to repair, and nothing that can drift out of step with the record it describes."* F1 shipped four tables. Replace that paragraph with:

```markdown
**Store only what cannot be derived.** Four tables hold the chosen half of Blob — `companions`
(the name and `xp_spent`), `companion_skills`, `companion_items` and `companion_nodes`. Nothing
else. Counts, earned XP, the scene and the whole gift ladder are still recomputed from the record
on every read, so the parts that could drift still cannot.

The rule survived its own replacement: the pre-F1 premise was "nothing is stored, so nothing can
drift", and what made that valuable was never the storage count. It was that no stored number
claims to describe the record. A choice is not a claim about the record, which is why a choice may
be stored and a count may not.
```

Keep the diagram below it and add the bag's path beside the resolver's:

```
outcomes + summaries in the DB          companions + its three tables
        |                                          |
        v                                          v
CompanionResolver::forUser()              CompanionBag::forUser()
CompanionWallet::balanceFor()             what is held, buildable, learnable
        |                                          |
        +------------------ + ---------------------+
                            v
                   CompanionController
                            |
                            v
        Inertia payload -> pages/companion.tsx
```

- [ ] **Step 2: Correct §4 and §5**

Add the XP table and the taper beside the ladder, and note that the ladder is the **gift** track: unchosen, unpurchasable, and untouched by F1 or F2.

- [ ] **Step 3: Add a section for the economy**

The two layers, the rule F2 established (`node → skill + tool`, `recipe → tool, never skill`), the chain `fibre → rope → axe → timber → handsaw → planks → crate`, and the three refusals from F2 §6 with their reasons.

- [ ] **Step 4: Correct §8's file map**

It lists none of F1's or F2's files. Add the four models, `CompanionWallet`, `CompanionBag`, `CompanionEconomyException`, the five Actions, `StockCompanionNodes`, the four controllers and `companion-bag.tsx`.

- [ ] **Step 5: Correct §9 and §11**

Add to the rulings table: the tool ruling, the wear ruling and the insight-stocking ruling, each with what it costs if reversed. In §11, replace *"E2 (tools and materials) and E3 (shelter) are sketched in the Phase E spec"* with the current naming — F1 and F2 are built, **F3 (the shelter) and F4 (depth)** are what remain, and point at the arc doc.

- [ ] **Step 6: Check it against its own rule**

`docs/BLOB.md` is deliberately **not** on `CompanionVocabularyTest::sourceFiles()` and must not be added — it quotes the banned words in order to document them. Re-read §2's banned list after editing and make sure the list itself is still accurate.

Run: `php artisan test --compact tests/Feature/Companion/CompanionVocabularyTest.php`
Expected: PASS — unchanged, since this file is not scanned.

- [ ] **Step 7: Commit**

```bash
git add docs/BLOB.md
git commit -m "docs(blob): the record is no longer the only thing stored"
```

---

**STOP. Batch 3 is complete.**

Run: `php artisan test --compact` and `npx vitest run`

---

## What would make this wrong

From the spec's §12, repeated here because this is the document the implementer has open:

- If a recipe ever gates on a skill, F2 §2's rule has been broken and the two layers have collapsed into one.
- If a tool ever appears in `capacity.carried`, F2 §6 has been broken and progressing has started costing the player room.
- If anything ever removes an item outside a recipe that consumed it, arc §4 has been broken.
- If a build ever consumes part of a recipe it cannot complete, the "nothing is destroyed" rule has been broken in the one place this feature writes to two tables at once.
- If a node's `blunt` line ever names the tool the user should go and build, the app has stated a plan rather than left an inference.
- If the skill list ever shows a skill whose node has not been met, arc §4's rewritten rule has been broken and the bag is a checklist again.
