# Workflow Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the plug-in seam every future module (gym, journalling, running, meal prep) hangs off — a persisted `intentions.workflow` name, a registry on each side that says what a name attaches, and a fallback that can never blank the screen — with nothing plugged in.

**Architecture:** `workflow` is a nullable string on `intentions`, chosen from a registry rather than typed. A server-side registry (`config/workflows.php`, read through `WorkflowRegistry`) says which workflows exist and what each attaches at the two extension sites — configuration on an `Action`, a record on an `Occurrence`. A client-side registry (`resources/js/patyourself/workflows.ts`) says which name routes to which recording surface. Null and unknown both resolve to "no workflow", which renders today's plain done/missed screen unchanged. The production registry ships **empty**; the whole architecture is proven by a **test-only fake workflow** with real fixture tables at both sites.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12 (SQLite in-memory), Inertia v3, React 19, TypeScript, Vitest, Tailwind v4, Pint.

**Spec:** `docs/superpowers/specs/2026-09-05-workflow-architecture-design.md`
**Context only, do NOT build:** `docs/superpowers/specs/2026-09-04-training-module-design.md`
**The economy this must not break:** `docs/BLOB.md`

---

## Global Constraints

Copied from the spec and the batch brief. Every task's requirements implicitly include this section.

- **`intentions.workflow` is nullable, PERSISTED, registry-backed.** Persisted so analytics is later a `group by`, not an inference from which tables have rows.
- **Null is the normal case, not a missing value**, and stays the overwhelming majority of loops forever.
- **Unknown name → the plain UI. Never blank, never an error.** Use `Object.hasOwn` in TypeScript: a bare lookup walks the prototype chain and `'constructor'` resolves truthy. That trap was already found once here, in `scenes.ts`. The PHP analogue is `config()` dot-notation: `config('workflows.registry.'.$name)` with `$name = 'gym.label'` resolves into the nested value. Read the registry array **once** and use `array_key_exists`.
- **Two extension sites and ONLY two:** configuration on an `Action`, a record on an `Occurrence`. Anything fitting neither is re-solving something inherited or introducing a second currency.
- **ONE OCCASION PRODUCES EXACTLY ONE `ActionLog`**, whatever a workflow records. Nothing in this batch may make that easier to break.
- **Recording does not log.** Filling in a record creates no `ActionLog`. The verdict is pressed separately, by a person, always.
- **`Strategy`, `Action`, `Occurrence` and `ActionLog` gain NO columns.** Only `Intention`, only `workflow`, added once here and reused by every later module.
- **A workflow may not** create or count its own logs, add a counter Blob reads, schedule anything, write its own catch-up / reminders / streak logic, or grade the person.
- **Gym is NOT in scope.** It is the second batch, deliberately, so it does not become the accidental template.

### Banned vocabulary — read this before writing a single comment

`CompanionVocabularyTest` scans listed source files, **comments included**, for: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`.

**`points` is a substring trap and this plan's own subject matter walks straight into it.** In any file added to `sourceFiles()` in Task 7:

| Never write | Write instead |
| --- | --- |
| "extension **points**" | "extension **point**" (singular), "both extension sites", "the two attachment sites" |
| "end**points**" | "end**point**" (singular), "routes" |
| "check**points**", "ap**points**", "disap**points**" | anything else |

The affected files are `config/workflows.php`, `app/Services/Workflows/WorkflowRegistry.php`, `app/Services/Workflows/WorkflowDefinition.php`, `resources/js/patyourself/workflows.ts`, `resources/js/patyourself/workflow-record.tsx`. Test files and this plan are not scanned.

### Baseline — measured on this worktree at `fdfcfcd`, after `npm run build`

| Check | Baseline | Command |
| --- | --- | --- |
| JS tests | **341 passed** (31 files) | `npx vitest run` |
| PHP tests | **852 passed**, 4641 assertions | `php artisan test --compact` |
| TypeScript | **exactly 1 error**, `resources/js/pages/catch-up.tsx(132,33)` — pre-existing, out of scope, **must not grow** | `npx tsc --noEmit` |
| Pint | clean | `vendor/bin/pint --dirty --format agent` |

**Build BEFORE the PHP suite** or `PwaManifestTest` skips and the assertion count drops.

**Never run `vendor/bin/pint --test`.** Run `vendor/bin/pint --dirty --format agent`.

### Traps that have each cost a round on this project

1. **A test written against a fixture's default asserts nothing.** Five have shipped here. `resources/js/pages/dashboard.test.tsx` has a `makeOccasion(overrides)` helper — a test that relies on its default `workflow` value proves nothing. Pass the value explicitly, every time. **If you cannot name the mutation that turns a test red, it is decoration.**
2. **`assertDatabaseMissing` on a column that does not exist is a constant-false predicate.** SQLite degrades the unresolvable identifier to a string literal so it passes forever; MySQL errors outright. Tests here are SQLite, production is MySQL. Assert `assertDatabaseHas('intentions', ['id' => $id, 'workflow' => null])` — a real predicate on a real column.
3. **Feature tests that render an Inertia view need `$this->withoutVite()`** in `setUp()`.
4. **Column-limited eager loads silently drop new columns.** `CatchUpController` does `->with('action.intention:id,title')`. Adding `workflow` to that payload without adding it to the select list yields `null` for every row, forever, with a green suite.
5. **PHPUnit, not Pest.** `php artisan test --compact --filter=Name`.

---

## File Structure

**Created**

| File | Responsibility |
| --- | --- |
| `database/migrations/*_add_workflow_to_intentions_table.php` | The one column. |
| `config/workflows.php` | The registry data. Ships empty. |
| `app/Services/Workflows/WorkflowDefinition.php` | One workflow's entry as a value object. |
| `app/Services/Workflows/WorkflowRegistry.php` | Reads the config; null / unknown resolve to null. |
| `resources/js/patyourself/workflows.ts` | The client registry and `workflowFor()`. |
| `resources/js/patyourself/workflow-record.tsx` | The render seam: a workflow's recording surface, or nothing. |
| `resources/js/patyourself/workflows.test.ts` | Routing and fallback, including `'constructor'`. |
| `resources/js/patyourself/workflow-record.test.tsx` | The seam renders the surface, or nothing. |
| `tests/Fixtures/Workflows/SpecFakeConfig.php` | Fake configuration attached to an `Action`. |
| `tests/Fixtures/Workflows/SpecFakeRecord.php` | Fake record attached to an `Occurrence`. |
| `tests/Fixtures/Workflows/RegistersSpecFakeWorkflow.php` | Creates the two fixture tables and registers the fake. |
| `tests/Feature/Workflows/WorkflowRegistryTest.php` | Registry resolution and both fallback traps. |
| `tests/Feature/Workflows/WorkflowColumnTest.php` | The column, the write constraint, the payload. |
| `tests/Feature/Workflows/PlainLoopIsUnchangedTest.php` | **The test that matters most.** |
| `tests/Feature/Workflows/WorkflowInvariantTest.php` | One occasion one log; recording moves `logCount` by zero. |

**Modified**

| File | Change |
| --- | --- |
| `app/Models/Intention.php` | `workflow` in `#[Fillable]`. |
| `database/factories/IntentionFactory.php` | `withWorkflow(?string)` state. Default stays null. |
| `app/Http/Resources/IntentionResource.php` | `'workflow' => $this->workflow`. |
| `app/Http/Requests/StoreIntentionRequest.php` | `workflow` nullable, in registry names. |
| `app/Http/Requests/UpdateIntentionRequest.php` | Same, `sometimes`. |
| `app/Actions/CreateIntention.php` | Persist `workflow`. |
| `app/Actions/UpdateIntention.php` | Add `workflow` to the field allowlist. |
| `app/Http/Controllers/NotebookController.php` | `workflow` on each occasion. |
| `app/Http/Controllers/CatchUpController.php` | `workflow` on each occurrence — **and the eager-load select**. |
| `resources/js/patyourself/types.ts` | `workflow` on `IntentionData` and `PendingOccurrenceData`. |
| `resources/js/pages/dashboard.tsx` | `workflow` on `TodaysOccasionData`; render the seam. |
| `resources/js/pages/catch-up.tsx` | Render the seam. |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | The five new source files. |

---

## The decision this batch had to make

**How do you test a plug-in system with nothing plugged in?**

**Decided: a test-only fake workflow, with real tables at both extension sites.**

`tests/Fixtures/Workflows/` holds two Eloquent models — one keyed to `actions`, one keyed to `occurrences` — and a trait that creates their tables against the in-memory SQLite database and registers the entry via `config()->set()`. Nothing ships.

Why this and not the alternatives:

- **It keeps the architecture honest.** If attaching a fake is awkward to write, the extension site is wrong. That is the point of writing it before gym, not after.
- **It makes the guard that matters real rather than notional.** "Recording moves `logCount` by zero" can only be asserted if something can actually be recorded. With no fake, that guard degrades to "nothing wrote a log, because nothing wrote anything" — a constant-false predicate in disguise, and this project has shipped five of those.
- **A registry that ships a real entry for a module that does not exist** would be a lie in production config, and the first thing a reader would try to open.
- **A registry with no entry at all and no fake** leaves `Rule::in([])`, `array_key_exists` and the whole fallback path untested, because there would be no known name to contrast an unknown one against.

**What this batch cannot prove, stated rather than discovered:** the fake's tables are created by the test, so nothing here exercises a *migration* for a workflow's own tables, and nothing exercises a real recording UI's form submission. Both belong to gym, which is the next batch.

---

### Task 1: The column

**Files:**
- Create: `database/migrations/<generated>_add_workflow_to_intentions_table.php`
- Modify: `app/Models/Intention.php:21-32` (the `#[Fillable]` attribute)
- Modify: `database/factories/IntentionFactory.php` (append a state after `completed()`)
- Test: `tests/Feature/Workflows/WorkflowColumnTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the `intentions.workflow` column (`string`, nullable, no default); `IntentionFactory::withWorkflow(?string $workflow): static`.

- [ ] **Step 1: Generate the migration**

```bash
php artisan make:migration add_workflow_to_intentions_table --no-interaction
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Workflows/WorkflowColumnTest.php`:

```php
<?php

namespace Tests\Feature\Workflows;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one column every later module reuses. Null is the ordinary state, not a
 * missing value, so it is asserted as a value rather than as an absence.
 */
class WorkflowColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_loop_created_without_a_workflow_stores_null(): void
    {
        $intention = Intention::factory()->for(User::factory())->create();

        // Asserted against the column, not against its absence:
        // assertDatabaseMissing on a column SQLite cannot resolve degrades to a
        // string literal and passes forever.
        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'workflow' => null,
        ]);
        $this->assertNull($intention->fresh()->workflow);
    }

    public function test_a_workflow_name_round_trips_through_the_column(): void
    {
        $intention = Intention::factory()
            ->for(User::factory())
            ->withWorkflow('spec-fake')
            ->create();

        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'workflow' => 'spec-fake',
        ]);
        $this->assertSame('spec-fake', $intention->fresh()->workflow);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=WorkflowColumnTest`
Expected: FAIL — `Call to undefined method ...IntentionFactory::withWorkflow()`, and the column does not exist.

- [ ] **Step 4: Write the migration**

Replace the generated file's body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which recording surface a loop uses, chosen from the registry in
 * config/workflows.php rather than typed.
 *
 * Nullable, and null is the ordinary case: a loop with no workflow is the plain
 * loop this app has always had, and stays the overwhelming majority forever.
 * Persisted rather than derived from "does it have any records yet" for two
 * reasons — the intent has to exist before the data does, or nothing ever
 * offers you the chance to create the data; and comparing loops by workflow
 * should be a `group by`, not a guess at which tables happen to have rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intentions', function (Blueprint $table) {
            $table->string('workflow')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('intentions', function (Blueprint $table) {
            $table->dropColumn('workflow');
        });
    }
};
```

- [ ] **Step 5: Add `workflow` to the model's fillable list**

In `app/Models/Intention.php`, add `'workflow',` to the `#[Fillable([...])]` array, immediately after `'status',`.

- [ ] **Step 6: Add the factory state**

Append to `database/factories/IntentionFactory.php`, after `completed()`:

```php
    /**
     * Which recording surface this loop uses. The default is deliberately
     * absent from `definition()` — every existing test builds a plain loop, and
     * that is what pins "nothing was special-cased".
     */
    public function withWorkflow(?string $workflow): static
    {
        return $this->state(['workflow' => $workflow]);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=WorkflowColumnTest`
Expected: PASS, 2 tests.

- [ ] **Step 8: Prove the guard bites**

Temporarily change the migration to `$table->string('workflow')->nullable()->default('spec-fake')->after('status');` and rerun.
Expected: `test_a_loop_created_without_a_workflow_stores_null` FAILS.
**Revert the change** and rerun to confirm green.

- [ ] **Step 9: Run the full PHP suite**

Run: `php artisan test --compact`
Expected: 852 passed + 2 new = **854 passed**. No failures.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(workflows): intentions carry a nullable workflow name

The one column every later module reuses. Nullable because null is the
ordinary state — a loop with no workflow is the plain loop this app has
always had — and persisted rather than derived, so comparing loops by
workflow is later a group by rather than a guess at which tables have rows."
```

---

### Task 2: The server registry, and the fake that proves it

**Files:**
- Create: `config/workflows.php`
- Create: `app/Services/Workflows/WorkflowDefinition.php`
- Create: `app/Services/Workflows/WorkflowRegistry.php`
- Create: `tests/Fixtures/Workflows/SpecFakeConfig.php`
- Create: `tests/Fixtures/Workflows/SpecFakeRecord.php`
- Create: `tests/Fixtures/Workflows/RegistersSpecFakeWorkflow.php`
- Test: `tests/Feature/Workflows/WorkflowRegistryTest.php`

**Interfaces:**
- Consumes: `intentions.workflow` (Task 1).
- Produces:
  - `App\Services\Workflows\WorkflowDefinition` — `readonly`, public `string $name`, `string $label`, `?string $config`, `?string $record`.
  - `App\Services\Workflows\WorkflowRegistry` — `for(?string $name): ?WorkflowDefinition`, `has(?string $name): bool`, `names(): array<int, string>`, `all(): array<string, WorkflowDefinition>`.
  - `Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow` — trait, `const SPEC_FAKE = 'spec-fake'`, `registerSpecFakeWorkflow(): void`.
  - `Tests\Fixtures\Workflows\SpecFakeConfig` (table `spec_fake_configs`, columns `action_id`, `body`), `Tests\Fixtures\Workflows\SpecFakeRecord` (table `spec_fake_records`, columns `occurrence_id`, `body`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Workflows/WorkflowRegistryTest.php`:

```php
<?php

namespace Tests\Feature\Workflows;

use App\Services\Workflows\WorkflowRegistry;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\Fixtures\Workflows\SpecFakeConfig;
use Tests\Fixtures\Workflows\SpecFakeRecord;
use Tests\TestCase;

/**
 * Naming a workflow the registry does not know must never break anything, and
 * the two ways a lookup can accidentally succeed are both closed here.
 */
class WorkflowRegistryTest extends TestCase
{
    use RegistersSpecFakeWorkflow;

    private WorkflowRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSpecFakeWorkflow();
        $this->registry = new WorkflowRegistry;
    }

    public function test_the_shipped_registry_is_empty(): void
    {
        // Nothing is plugged in yet, and a registry that quietly grew an entry
        // would make every fallback assertion below stop meaning anything.
        $this->assertSame([], config('workflows.registry.gym', []));
        $this->assertNull((new WorkflowRegistry)->for('gym'));
    }

    public function test_a_registered_name_resolves_to_what_it_attaches(): void
    {
        $definition = $this->registry->for(self::SPEC_FAKE);

        $this->assertNotNull($definition);
        $this->assertSame(self::SPEC_FAKE, $definition->name);
        $this->assertSame('Spec fake', $definition->label);
        $this->assertSame(SpecFakeConfig::class, $definition->config);
        $this->assertSame(SpecFakeRecord::class, $definition->record);
    }

    public function test_null_resolves_to_no_workflow(): void
    {
        $this->assertNull($this->registry->for(null));
        $this->assertFalse($this->registry->has(null));
    }

    public function test_an_unknown_name_resolves_to_no_workflow(): void
    {
        $this->assertNull($this->registry->for('gimnasio'));
        $this->assertFalse($this->registry->has('gimnasio'));
    }

    /**
     * The PHP mirror of the prototype-chain trap scenes.ts already records.
     * `config('workflows.registry.'.$name)` with a dotted name walks into the
     * entry and hands back a nested value, which is truthy and therefore never
     * triggers a `??` fallback — the caller then reads ->record off a string.
     * Reading the array once and using array_key_exists closes it.
     */
    public function test_a_dotted_name_cannot_walk_into_an_entry(): void
    {
        $this->assertNull($this->registry->for(self::SPEC_FAKE.'.label'));
        $this->assertNull($this->registry->for(self::SPEC_FAKE.'.record'));
        $this->assertFalse($this->registry->has(self::SPEC_FAKE.'.label'));
    }

    public function test_names_lists_every_registered_workflow(): void
    {
        $this->assertSame([self::SPEC_FAKE], $this->registry->names());
    }

    /**
     * The registry reads config at call time, not at construction: a workflow
     * registered after the service was resolved must still be found, which is
     * what lets a test register a fake at all.
     */
    public function test_the_registry_is_not_frozen_at_construction(): void
    {
        $registry = new WorkflowRegistry;

        config()->set('workflows.registry.late', [
            'label' => 'Late',
            'config' => null,
            'record' => null,
        ]);

        $this->assertNotNull($registry->for('late'));
    }

    /**
     * Both attachment sites are optional. A workflow with no configuration is
     * not a special case — it is an empty site.
     */
    public function test_a_workflow_may_attach_nothing_at_either_site(): void
    {
        config()->set('workflows.registry.bare', ['label' => 'Bare']);

        $definition = $this->registry->for('bare');

        $this->assertNotNull($definition);
        $this->assertNull($definition->config);
        $this->assertNull($definition->record);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=WorkflowRegistryTest`
Expected: FAIL — `Class "App\Services\Workflows\WorkflowRegistry" not found`.

- [ ] **Step 3: Write the config file**

Create `config/workflows.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The workflow registry
    |--------------------------------------------------------------------------
    |
    | Every workflow this codebase knows, keyed by the value stored in
    | intentions.workflow. A loop names one to reach a recording surface; a loop
    | with no workflow — every loop today, and the ordinary case forever —
    | reaches nothing here and records its outcome through the plain screen.
    |
    | Each entry says what the workflow attaches at the two extension sites:
    |
    |   config  a model keyed to `actions`     — what an occasion is meant to contain
    |   record  a model keyed to `occurrences` — what it actually contained
    |
    | Both are optional. A workflow that attaches nothing at a site is not a
    | special case; it is an empty site.
    |
    | Empty until the first module ships. Adding a name here is what makes it
    | choosable — a workflow is spelled by this file, never typed by a user,
    | which is the whole difference between this and the free-form tag it
    | replaced.
    |
    |   'gym' => [
    |       'label'  => 'Gym',
    |       'config' => \App\Models\ActionExercise::class,
    |       'record' => \App\Models\PerformedSet::class,
    |   ],
    |
    */

    'registry' => [
        //
    ],

];
```

- [ ] **Step 4: Write the definition**

Create `app/Services/Workflows/WorkflowDefinition.php`:

```php
<?php

namespace App\Services\Workflows;

/**
 * One workflow's registry entry: its name, how it is written for a person, and
 * what it attaches at each of the two extension sites.
 *
 * `config` is a model keyed to `actions` — what an occasion is meant to
 * contain. `record` is a model keyed to `occurrences` — what it actually
 * contained. Either may be null, which means the workflow attaches nothing
 * there rather than that something is missing.
 */
final readonly class WorkflowDefinition
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $config = null,
        public ?string $record = null,
    ) {}
}
```

- [ ] **Step 5: Write the registry**

Create `app/Services/Workflows/WorkflowRegistry.php`:

```php
<?php

namespace App\Services\Workflows;

/**
 * Which workflows exist, and what each one attaches.
 *
 * Naming a workflow the registry does not know must never be able to break a
 * screen — the same rule scenes, room objects and animations already follow —
 * so an unknown name and a null one both resolve to "no workflow", which is the
 * plain loop this app has always had.
 *
 * The whole array is read and matched with `array_key_exists` rather than asked
 * for by dot path. `config('workflows.registry.'.$name)` walks into an entry
 * whenever the name contains a dot: a loop naming `gym.label` would be handed
 * back a string, which is truthy and so never triggers a fallback, and the
 * caller would then read ->record off it. That is the same shape as the
 * prototype-chain trap `scenes.ts` records, arriving by a different route.
 *
 * Config is read on every call rather than cached on the instance, so a
 * workflow registered after this service was resolved is still found.
 */
final class WorkflowRegistry
{
    /**
     * Every workflow, keyed by the value stored in `intentions.workflow`.
     *
     * @return array<string, WorkflowDefinition>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $entries */
        $entries = config('workflows.registry', []);

        $definitions = [];

        foreach ($entries as $name => $entry) {
            $definitions[(string) $name] = $this->definition((string) $name, $entry);
        }

        return $definitions;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        /** @var array<string, mixed> $entries */
        $entries = config('workflows.registry', []);

        return array_key_exists($name, $entries);
    }

    /**
     * The workflow a loop names, or null for "no workflow" — which is what both
     * a null name and an unrecognised one mean.
     */
    public function for(?string $name): ?WorkflowDefinition
    {
        if (! $this->has($name)) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $entries */
        $entries = config('workflows.registry', []);

        return $this->definition((string) $name, $entries[$name]);
    }

    /** @param  array<string, mixed>  $entry */
    private function definition(string $name, array $entry): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: $name,
            label: (string) ($entry['label'] ?? $name),
            config: isset($entry['config']) ? (string) $entry['config'] : null,
            record: isset($entry['record']) ? (string) $entry['record'] : null,
        );
    }
}
```

- [ ] **Step 6: Write the fake workflow's two models**

Create `tests/Fixtures/Workflows/SpecFakeConfig.php`:

```php
<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuration a fake workflow attaches to an Action: what an occasion is
 * meant to contain. The gym module's exercise template will sit exactly here.
 *
 * Exists only in the test suite. It is how the architecture is exercised while
 * no real module is built — if attaching one of these is awkward, the extension
 * site is wrong, and finding that out before gym is the reason this was written
 * first.
 */
class SpecFakeConfig extends Model
{
    protected $table = 'spec_fake_configs';

    protected $guarded = [];
}
```

Create `tests/Fixtures/Workflows/SpecFakeRecord.php`:

```php
<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Eloquent\Model;

/**
 * The record a fake workflow attaches to an Occurrence: what the occasion
 * actually contained. The gym module's performed sets will sit exactly here.
 *
 * Keyed to the occurrence rather than to the log on purpose. A record is
 * written *during* an occasion, long before anyone presses a verdict, so there
 * is no log to hang it on yet — and creating one early to hold it would pay the
 * user for starting rather than for recording.
 *
 * Exists only in the test suite.
 */
class SpecFakeRecord extends Model
{
    protected $table = 'spec_fake_records';

    protected $guarded = [];
}
```

- [ ] **Step 7: Write the registration trait**

Create `tests/Fixtures/Workflows/RegistersSpecFakeWorkflow.php`:

```php
<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plugs a fake workflow into the registry, with real tables at both extension
 * sites, so the architecture can be tested with nothing real plugged in.
 *
 * The tables are created against the in-memory test database and go away with
 * it. Nothing here ships.
 */
trait RegistersSpecFakeWorkflow
{
    public const SPEC_FAKE = 'spec-fake';

    protected function registerSpecFakeWorkflow(): void
    {
        Schema::create('spec_fake_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_id');
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('spec_fake_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->string('body');
            $table->timestamps();
        });

        config()->set('workflows.registry.'.self::SPEC_FAKE, [
            'label' => 'Spec fake',
            'config' => SpecFakeConfig::class,
            'record' => SpecFakeRecord::class,
        ]);
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --compact --filter=WorkflowRegistryTest`
Expected: PASS, 8 tests.

- [ ] **Step 9: Prove the two fallback guards bite**

Mutation A — replace the body of `WorkflowRegistry::for()` with the naive dot lookup:

```php
$entry = config('workflows.registry.'.$name);
return $entry === null ? null : $this->definition((string) $name, $entry);
```

Rerun. Expected: `test_a_dotted_name_cannot_walk_into_an_entry` FAILS (a dotted name resolves). **Revert.**

Mutation B — cache the entries in a constructor-promoted property instead of reading config per call. Rerun. Expected: `test_the_registry_is_not_frozen_at_construction` FAILS. **Revert.**

Rerun after reverting both and confirm 8 pass.

- [ ] **Step 10: Run the full PHP suite, format and commit**

Run: `php artisan test --compact` — expected **862 passed** (854 + 8).

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(workflows): a registry, and a fake workflow that proves it

The registry says which workflows exist and what each attaches at the two
extension sites. It ships empty, so the whole thing is exercised by a
test-only fake with real tables at both sites: if attaching a fake is
awkward, the site is wrong, and finding that out before gym is the point.

An unknown name and a null one both resolve to no workflow. The array is
matched with array_key_exists rather than asked for by dot path, because a
dotted name would otherwise walk into an entry and hand back a truthy
string — the same shape as the prototype-chain trap scenes.ts records."
```

---

### Task 3: A workflow is chosen, never typed

**Files:**
- Modify: `app/Http/Requests/StoreIntentionRequest.php`
- Modify: `app/Http/Requests/UpdateIntentionRequest.php`
- Modify: `app/Actions/CreateIntention.php`
- Modify: `app/Actions/UpdateIntention.php:35-42` (the field allowlist)
- Modify: `app/Http/Resources/IntentionResource.php`
- Test: `tests/Feature/Workflows/WorkflowColumnTest.php` (append)

**Interfaces:**
- Consumes: `WorkflowRegistry::names()` (Task 2), `RegistersSpecFakeWorkflow` (Task 2).
- Produces: `workflow` accepted on `POST /loops` and `PATCH /loops/{intention}` when it names a registered workflow or is null; `workflow` present on every `IntentionResource` payload.

**Note the asymmetry, and keep it:** writes are constrained to the registry, reads are forgiving. A value that reached the column another way — a seeder, tinker, a row written before a workflow was retired — must still render the plain screen rather than error. Task 2's fallback is what holds that; this task holds the other half.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Workflows/WorkflowColumnTest.php` — add the imports and trait first:

```php
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
```

```php
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSpecFakeWorkflow();
    }
```

Then append these tests to the class:

```php
    public function test_a_registered_workflow_may_be_set_on_a_loop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/loops/'.$this->loopFor($user)->id, [
            'workflow' => self::SPEC_FAKE,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'workflow' => self::SPEC_FAKE,
        ]);
    }

    public function test_a_workflow_the_registry_does_not_know_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        $this->actingAs($user)->patch('/loops/'.$loop->id, [
            'workflow' => 'gimnasio',
        ])->assertSessionHasErrors('workflow');

        // The rejection has to leave the column alone, not blank it.
        $this->assertDatabaseHas('intentions', [
            'id' => $loop->id,
            'workflow' => null,
        ]);
    }

    public function test_a_loop_may_be_returned_to_no_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);

        $this->actingAs($user)->patch('/loops/'.$loop->id, [
            'workflow' => null,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('intentions', [
            'id' => $loop->id,
            'workflow' => null,
        ]);
    }

    public function test_the_loop_payload_carries_the_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);

        $this->withoutVite()->actingAs($user)->get('/loops/'.$loop->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intention.workflow', self::SPEC_FAKE));
    }

    public function test_the_loop_payload_carries_null_for_a_plain_loop(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        $this->withoutVite()->actingAs($user)->get('/loops/'.$loop->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intention.workflow', null));
    }

    private function loopFor(User $user, ?string $workflow = null): Intention
    {
        return Intention::factory()->for($user)->withWorkflow($workflow)->create();
    }
```

Add `use Inertia\Testing\AssertableInertia;` only if the project's other Inertia tests import it — **check a sibling test first** (`tests/Feature/` has several) and match their `assertInertia` style exactly.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter=WorkflowColumnTest`
Expected: FAIL — the unknown name is accepted (no `workflow` rule exists) and `intention.workflow` is missing from the payload.

- [ ] **Step 3: Add the validation rule to both requests**

In `app/Http/Requests/StoreIntentionRequest.php`, add to `rules()`:

```php
            // Chosen from the registry, never typed. A tag was the earlier
            // answer and it fails silently on `Gym`, `gimnasio` or a trailing
            // space, with nothing on screen to say why.
            'workflow' => ['sometimes', 'nullable', 'string', Rule::in(app(WorkflowRegistry::class)->names())],
```

and `use App\Services\Workflows\WorkflowRegistry;` at the top.

In `app/Http/Requests/UpdateIntentionRequest.php`, add the identical rule and import.

- [ ] **Step 4: Persist it on create**

In `app/Actions/CreateIntention.php`, add to the created array, after `'reward' => $data['reward'],`:

```php
            'workflow' => $data['workflow'] ?? null,
```

- [ ] **Step 5: Allow it on update**

In `app/Actions/UpdateIntention.php`, add `'workflow',` to the `array_flip([...])` allowlist in `handle()`, after `'status',`.

- [ ] **Step 6: Add it to the resource**

In `app/Http/Resources/IntentionResource.php`, add after `'status' => $this->status,`:

```php
            // Which recording surface this loop uses, or null for the plain
            // screen. Always present, because the client registry has to be
            // able to route on it rather than infer from an absent key.
            'workflow' => $this->workflow,
```

- [ ] **Step 7: Run to verify they pass**

Run: `php artisan test --compact --filter=WorkflowColumnTest`
Expected: PASS, 7 tests.

- [ ] **Step 8: Prove the guards bite**

Mutation A — drop the `Rule::in(...)` from `UpdateIntentionRequest`. Rerun. Expected: `test_a_workflow_the_registry_does_not_know_is_rejected` FAILS. **Revert.**

Mutation B — remove `'workflow',` from the `UpdateIntention` allowlist. Rerun. Expected: `test_a_registered_workflow_may_be_set_on_a_loop` FAILS. **Revert.**

Mutation C — remove the `'workflow'` line from `IntentionResource`. Rerun. Expected: both payload tests FAIL. **Revert.**

- [ ] **Step 9: Run the full PHP suite, format and commit**

Run: `php artisan test --compact` — expected **867 passed**.

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(workflows): a workflow is chosen from the registry, not typed

Writes are constrained to registered names; reads stay forgiving, because a
value that reached the column another way must still render the plain
screen rather than error. The payload always carries the key, so the client
routes on a value rather than inferring from an absent one."
```

---

### Task 4: The client registry

**Files:**
- Create: `resources/js/patyourself/workflows.ts`
- Test: `resources/js/patyourself/workflows.test.ts`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `WorkflowRecordProps` — `{ occurrenceId: number | null; actionId: number }`
  - `WorkflowSpec` — `{ name: string; label: string; record: ComponentType<WorkflowRecordProps> | null }`
  - `WorkflowRegistry` — `Record<string, WorkflowSpec>`
  - `WORKFLOWS: WorkflowRegistry` — the shipped registry, empty
  - `workflowFor(name: string | null | undefined, registry?: WorkflowRegistry): WorkflowSpec | null`

- [ ] **Step 1: Write the failing test**

Create `resources/js/patyourself/workflows.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import type { WorkflowRegistry } from './workflows';
import { WORKFLOWS, workflowFor } from './workflows';

function Surface() {
    return null;
}

const FAKE: WorkflowRegistry = {
    'spec-fake': { name: 'spec-fake', label: 'Spec fake', record: Surface },
    bare: { name: 'bare', label: 'Bare', record: null },
};

describe('workflowFor', () => {
    it('routes a registered name to its recording surface', () => {
        expect(workflowFor('spec-fake', FAKE)?.record).toBe(Surface);
    });

    it('routes a registered name that records nothing to an entry with no surface', () => {
        const spec = workflowFor('bare', FAKE);

        expect(spec).not.toBeNull();
        expect(spec?.record).toBeNull();
    });

    it('routes null to no workflow', () => {
        expect(workflowFor(null, FAKE)).toBeNull();
    });

    it('routes undefined to no workflow', () => {
        expect(workflowFor(undefined, FAKE)).toBeNull();
    });

    it('routes an unknown name to no workflow', () => {
        expect(workflowFor('gimnasio', FAKE)).toBeNull();
    });

    // A bare lookup walks the prototype chain, so these resolve to an
    // inherited Object value that is truthy and never triggers a `??`
    // fallback — the caller then reads .record off it. Found once already in
    // scenes.ts; this is the same trap in a second registry.
    it.each(['constructor', 'toString', 'hasOwnProperty', '__proto__'])(
        'routes the inherited property %s to no workflow',
        (name) => {
            expect(workflowFor(name, FAKE)).toBeNull();
        },
    );

    it('applies the same fallback to the shipped registry by default', () => {
        expect(workflowFor('constructor')).toBeNull();
        expect(workflowFor('toString')).toBeNull();
        expect(workflowFor('gimnasio')).toBeNull();
        expect(workflowFor(null)).toBeNull();
    });

    it('ships no workflows yet', () => {
        // Nothing is plugged in. A registry that quietly grew an entry would
        // make the default-registry assertions above stop meaning anything.
        expect(Object.keys(WORKFLOWS)).toEqual([]);
    });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx vitest run resources/js/patyourself/workflows.test.ts`
Expected: FAIL — cannot resolve `./workflows`.

- [ ] **Step 3: Write the registry**

Create `resources/js/patyourself/workflows.ts`:

```ts
/**
 * Which workflow a loop uses, and what that routes to on screen.
 *
 * A workflow is how the app records *what happened* during an occasion, on top
 * of whether it happened at all. The loop, its experiment, its schedule and its
 * verdict are unchanged by one — a workflow brings a recording surface and
 * nothing else.
 *
 * Empty until the first module ships. A loop with no workflow — every loop
 * today, and the ordinary case forever — routes to nothing here and keeps the
 * plain screen it has always had.
 *
 * The registry is mirrored on the server in `config/workflows.php`, which is
 * the one that decides what a name may be set to. This side decides only what
 * it draws.
 */
import type { ComponentType } from 'react';

/** What a recording surface is told about the occasion it is recording. */
export interface WorkflowRecordProps {
    /**
     * The occasion, when one has been materialised. Null for an anchored action
     * whose slot does not exist yet — the same case the plain screen already
     * handles by posting to the action route instead.
     */
    occurrenceId: number | null;
    actionId: number;
}

export interface WorkflowSpec {
    name: string;
    label: string;
    /**
     * What this workflow draws to record an occasion, or null when it draws
     * nothing. Null is an empty attachment site, not a missing surface.
     */
    record: ComponentType<WorkflowRecordProps> | null;
}

export type WorkflowRegistry = Record<string, WorkflowSpec>;

/** Every workflow this app draws, keyed by the name stored on the loop. */
export const WORKFLOWS: WorkflowRegistry = {};

/**
 * The workflow a loop names, or null for "no workflow" — which is what both a
 * null name and an unrecognised one mean, and which draws the plain screen.
 *
 * Naming a workflow the registry does not know must never be able to break the
 * screen — the same rule scenes, room objects and animations already follow.
 *
 * `Object.hasOwn` rather than `registry[name] ?? null`: a plain object's lookup
 * walks the prototype chain, so names like `'constructor'` or `'toString'`
 * resolve to an inherited `Object` value that is truthy and therefore never
 * triggers the fallback — the lookup then fails further down where the caller
 * reads `.record` off what it thinks is a `WorkflowSpec`. `scenes.ts` records
 * the same trap; this is the second registry to hold the rule.
 *
 * The registry is a parameter so a test can route against its own entries
 * without one being shipped for it. The default is the real one, and the
 * fallback is asserted against that default too.
 */
export function workflowFor(
    name: string | null | undefined,
    registry: WorkflowRegistry = WORKFLOWS,
): WorkflowSpec | null {
    if (name === null || name === undefined) {
        return null;
    }

    return Object.hasOwn(registry, name) ? registry[name] : null;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx vitest run resources/js/patyourself/workflows.test.ts`
Expected: PASS, 11 tests (the `it.each` counts as 4).

- [ ] **Step 5: Prove the guard bites**

Mutation — replace the return with `return registry[name] ?? null;`. Rerun.
Expected: all four inherited-property cases FAIL, and `applies the same fallback to the shipped registry by default` FAILS. **Revert** and confirm green.

- [ ] **Step 6: Typecheck, run the JS suite, commit**

Run: `npx tsc --noEmit` — expected **exactly 1 error**, the pre-existing `catch-up.tsx(132,33)`.
Run: `npx vitest run` — expected **352 passed** (341 + 11).

```bash
git add -A
git commit -m "feat(workflows): the client registry, and the fallback that cannot blank a screen

Object.hasOwn rather than a bare lookup: a plain object's lookup walks the
prototype chain, so 'constructor' resolves to something truthy and never
triggers the fallback. scenes.ts records the same trap; this is the second
registry to hold the rule, and the guard is asserted against the shipped
registry as well as against a test one."
```

---

### Task 5: The render seam

**Files:**
- Create: `resources/js/patyourself/workflow-record.tsx`
- Create: `resources/js/patyourself/workflow-record.test.tsx`
- Modify: `app/Http/Controllers/NotebookController.php:37-51`
- Modify: `app/Http/Controllers/CatchUpController.php:46` and `:57-64`
- Modify: `resources/js/patyourself/types.ts` (`PendingOccurrenceData`, `IntentionData`)
- Modify: `resources/js/pages/dashboard.tsx:13-35` and `:193-273`
- Modify: `resources/js/pages/catch-up.tsx:66-142`
- Modify: `resources/js/pages/dashboard.test.tsx` (the `makeOccasion` helper's base object)
- Test: `tests/Feature/Workflows/WorkflowColumnTest.php` (append two payload tests)

**Interfaces:**
- Consumes: `workflowFor`, `WorkflowSpec`, `WorkflowRegistry`, `WorkflowRecordProps` (Task 4); `intentions.workflow` on the payload (Task 3).
- Produces: `<WorkflowRecord workflow={…} occurrenceId={…} actionId={…} registry={…} />`, rendering the named workflow's surface or nothing.

**The seam sits OUTSIDE the verdict `<Form>`, above it.** Recording does not log: if a recording surface's inputs sat inside the outcome form, pressing "Log it" would submit them together and the two would stop being independent. Filling in a record and then marking the occasion missed is a real thing that happens, and the invariant depends on the verdict being pressed separately, by a person, always.

- [ ] **Step 1: Write the failing component test**

Create `resources/js/patyourself/workflow-record.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WorkflowRecordProps, WorkflowRegistry } from './workflows';
import { WorkflowRecord } from './workflow-record';

function Surface({ occurrenceId, actionId }: WorkflowRecordProps) {
    return (
        <p data-testid="surface">
            {String(occurrenceId)}/{actionId}
        </p>
    );
}

const FAKE: WorkflowRegistry = {
    'spec-fake': { name: 'spec-fake', label: 'Spec fake', record: Surface },
    bare: { name: 'bare', label: 'Bare', record: null },
};

describe('WorkflowRecord', () => {
    it('draws the named workflow surface', () => {
        render(
            <WorkflowRecord
                workflow="spec-fake"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('7/3');
    });

    it('draws nothing for a plain loop', () => {
        const { container } = render(
            <WorkflowRecord
                workflow={null}
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for a workflow the registry does not know', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="gimnasio"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for an inherited property name', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="constructor"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for a registered workflow that records nothing', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="bare"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('passes a null occurrence through to the surface', () => {
        render(
            <WorkflowRecord
                workflow="spec-fake"
                occurrenceId={null}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('null/3');
    });
});
```

Match the import style of a sibling test (`resources/js/patyourself/outcome-history.test.tsx`) — check whether `@testing-library/jest-dom` matchers are set up globally before using `toHaveTextContent`/`toBeEmptyDOMElement`.

- [ ] **Step 2: Run to verify it fails**

Run: `npx vitest run resources/js/patyourself/workflow-record.test.tsx`
Expected: FAIL — cannot resolve `./workflow-record`.

- [ ] **Step 3: Write the component**

Create `resources/js/patyourself/workflow-record.tsx`:

```tsx
/**
 * Where a workflow draws what happened during an occasion.
 *
 * Every screen that logs an outcome renders this above its verdict controls,
 * and for a plain loop it draws nothing at all — which is every loop today.
 * That is the fallback: a name the registry does not know is the same as no
 * name, and neither can leave a blank screen behind, because the verdict
 * controls are not this component's to remove.
 *
 * Deliberately rendered *outside* the verdict form. Recording does not log: a
 * record written here creates nothing, and the verdict is pressed separately,
 * by a person, always. Inputs living inside that form would submit with the
 * outcome and quietly join the two.
 */
import type { WorkflowRegistry } from '@/patyourself/workflows';
import { WORKFLOWS, workflowFor } from '@/patyourself/workflows';

interface WorkflowRecordSlotProps {
    /** The name stored on the loop. Null for a plain loop. */
    workflow: string | null;
    occurrenceId: number | null;
    actionId: number;
    /** Injectable so a test can route without a workflow being shipped. */
    registry?: WorkflowRegistry;
}

export function WorkflowRecord({
    workflow,
    occurrenceId,
    actionId,
    registry = WORKFLOWS,
}: WorkflowRecordSlotProps) {
    const spec = workflowFor(workflow, registry);

    if (spec === null || spec.record === null) {
        return null;
    }

    const Record = spec.record;

    return <Record occurrenceId={occurrenceId} actionId={actionId} />;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx vitest run resources/js/patyourself/workflow-record.test.tsx`
Expected: PASS, 6 tests.

- [ ] **Step 5: Carry `workflow` on the dashboard payload**

In `app/Http/Controllers/NotebookController.php`, add to the occasion map, after `'loop_title' => …,`:

```php
                    // Which recording surface this loop uses, or null for the
                    // plain screen. Always sent, so the client routes on a
                    // value rather than inferring from an absent key.
                    'workflow' => $occasion->action->intention->workflow,
```

- [ ] **Step 6: Carry `workflow` on the catch-up payload — and widen the eager load**

In `app/Http/Controllers/CatchUpController.php`:

Change the eager load on line 46 from `->with('action.intention:id,title')` to:

```php
            ->with('action.intention:id,title,workflow')
```

**This is load-bearing.** A column-limited eager load that does not name `workflow` returns null for every row, forever, with the suite green.

Then add to the occurrence map, after `'loop_title' => …,`:

```php
                'workflow' => $occurrence->action->intention->workflow,
```

- [ ] **Step 7: Write the failing payload tests**

Append to `tests/Feature/Workflows/WorkflowColumnTest.php`:

```php
    public function test_the_catch_up_payload_carries_the_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $this->withoutVite()->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('occurrences.0.id', $occurrence->id)
                ->where('occurrences.0.workflow', self::SPEC_FAKE));
    }

    public function test_the_catch_up_payload_carries_null_for_a_plain_loop(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);
        $action = Action::factory()->for($loop, 'intention')->create();
        Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $this->withoutVite()->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('occurrences.0.workflow', null));
    }
```

Check `database/factories/ActionFactory.php` and `OccurrenceFactory.php` for the correct relation names and required states before writing these — match how `tests/Feature/CatchUpTest.php` (or the nearest existing catch-up test) builds its fixtures, including any `status` the action needs to be listed.

- [ ] **Step 8: Run and confirm both fail, then pass**

Run: `php artisan test --compact --filter=WorkflowColumnTest`
Expected: with the eager load left at `:id,title`, `test_the_catch_up_payload_carries_the_workflow` FAILS with `null` instead of `spec-fake`. Apply Step 6's widened select and rerun: PASS, 9 tests.

**This is the named mutation for the eager-load trap.** Verify it in this order — revert the select to `:id,title`, see it go red, restore it.

- [ ] **Step 9: Add `workflow` to the client types**

In `resources/js/patyourself/types.ts`:

Add to `IntentionData`, after `status: string;`:

```ts
    /** Which recording surface this loop uses. Null for the plain screen. */
    workflow: string | null;
```

Add to `PendingOccurrenceData`, after `loop_title: string;`:

```ts
    /** The loop's recording surface. Null for the plain screen. */
    workflow: string | null;
```

In `resources/js/pages/dashboard.tsx`, add the same field to the exported `TodaysOccasionData` interface after `loop_title`.

- [ ] **Step 10: Render the seam on both logging screens**

In `resources/js/pages/dashboard.tsx`, inside `OccasionRow`, between the title/time header `</div>` and the `<Form>`:

```tsx
            <WorkflowRecord
                workflow={occasion.workflow}
                occurrenceId={occasion.occurrence_id}
                actionId={occasion.action_id}
            />
```

and import `import { WorkflowRecord } from '@/patyourself/workflow-record';`.

In `resources/js/pages/catch-up.tsx`, inside `CatchUpRow`, in the same position — between the header `</div>` and the `<Form>`:

```tsx
            <WorkflowRecord
                workflow={occurrence.workflow}
                occurrenceId={occurrence.id}
                actionId={occurrence.action_id}
            />
```

with the same import.

- [ ] **Step 11: Fix the dashboard test fixture**

`resources/js/pages/dashboard.test.tsx`'s `makeOccasion` helper builds a `TodaysOccasionData`. Add `workflow: null,` to its base object so the file typechecks.

**Then add one test that does not rely on that default**, in `resources/js/pages/dashboard.test.tsx`:

```tsx
    it('renders the plain verdict controls for a loop whose workflow is unknown', () => {
        render(
            <Dashboard
                {...baseProps()}
                occasions={[makeOccasion({ workflow: 'gimnasio' })]}
            />,
        );

        expect(screen.getByLabelText('Did it')).toBeInTheDocument();
    });
```

Match `baseProps()`/`render` to whatever the file already does — read it first and copy its existing setup exactly. The point of the explicit `workflow: 'gimnasio'` is that a test relying on the helper's `null` default would assert nothing about the fallback.

- [ ] **Step 12: Typecheck, run both suites**

Run: `npx tsc --noEmit` — expected **exactly 1 error**, the pre-existing one. If a second appears, the payload types and the components disagree; fix before continuing.
Run: `npx vitest run` — expected **359 passed** (352 + 6 + 1).
Run: `npm run build` then `php artisan test --compact` — expected **869 passed**.

- [ ] **Step 13: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(workflows): the render seam, above the verdict and outside its form

Both logging screens draw a workflow's recording surface above the verdict
controls, and nothing at all for a plain loop — which is every loop today.
Outside the form deliberately: recording does not log, so a record's inputs
must not ride along with the outcome.

The catch-up eager load had to widen to :id,title,workflow. A column-limited
load that does not name a new column returns null for every row forever,
with the suite green."
```

---

### Task 6: The invariants

**Files:**
- Create: `tests/Feature/Workflows/PlainLoopIsUnchangedTest.php`
- Create: `tests/Feature/Workflows/WorkflowInvariantTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–5.
- Produces: nothing. This task is entirely guards.

**Read the existing fixtures first.** Before writing either file, read `tests/Feature/CatchUpTest.php` (or the nearest test that exercises the catch-up → log lifecycle) and `database/factories/{Action,Occurrence,Strategy}Factory.php`, and build fixtures the way they already do. A lifecycle test that silently builds an action the scheduler ignores is the "asserts nothing" failure in its purest form.

- [ ] **Step 1: Write the regression that matters most**

Create `tests/Feature/Workflows/PlainLoopIsUnchangedTest.php`:

```php
<?php

namespace Tests\Feature\Workflows;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\TestCase;

/**
 * The claim this whole design rests on is that nothing was special-cased. That
 * is only true if it is pinned, so this walks a plain loop through its entire
 * life — schedule, fall due, appear in catch-up, log, count toward Blob — and
 * then walks a workflow loop through the same life and asserts the two records
 * are indistinguishable.
 *
 * The parity half is the sharper of the two: a plain-loop assertion can be made
 * to pass by a bug that skips workflow loops entirely, and only comparing the
 * two catches that.
 */
class PlainLoopIsUnchangedTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->registerSpecFakeWorkflow();
    }

    public function test_a_plain_loop_falls_due_catches_up_and_logs_as_it_always_has(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(null)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('occurrences.0.id', $occurrence->id));

        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $occurrence->id)->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);

        // The column is still what it was. Nothing on the logging path writes
        // to it, and nothing defaults it.
        $this->assertDatabaseHas('intentions', ['id' => $loop->id, 'workflow' => null]);
    }

    public function test_a_workflow_loop_and_a_plain_loop_leave_the_same_record(): void
    {
        $user = User::factory()->create();

        $plain = $this->loopWithADueOccasion($user, null);
        $workflow = $this->loopWithADueOccasion($user, self::SPEC_FAKE);

        $this->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('occurrences', 2));

        foreach ([$plain, $workflow] as $occurrence) {
            $this->actingAs($user)
                ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
                ->assertSessionHasNoErrors();
        }

        // One occasion, one log — on both, and the same one.
        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $plain->id)->count());
        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $workflow->id)->count());

        // Blob is never told which was which.
        $this->assertSame(2, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_a_workflow_survives_a_strategy_revision(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();

        $this->actingAs($user)
            ->post('/loops/'.$loop->id.'/experiments', $this->experimentPayload())
            ->assertSessionHasNoErrors();

        // The plan changed; the routing did not.
        $this->assertSame(self::SPEC_FAKE, $loop->fresh()->workflow);
    }

    private function loopWithADueOccasion(User $user, ?string $workflow): Occurrence
    {
        $loop = Intention::factory()->for($user)->withWorkflow($workflow)->create();
        $action = Action::factory()->for($loop, 'intention')->create();

        return Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function experimentPayload(): array
    {
        // Copy this verbatim from the existing experiment test rather than
        // inventing it — StoreExperimentRequest decides the shape.
        return [];
    }
}
```

**`experimentPayload()` is the one place you must go and read `app/Http/Requests/StoreExperimentRequest.php` and the existing experiment feature test, and fill in the real payload.** Leaving it empty makes the revision test assert nothing.

- [ ] **Step 2: Run and iterate until green**

Run: `php artisan test --compact --filter=PlainLoopIsUnchangedTest`
Expected: PASS, 3 tests. Fix fixture shapes against the existing tests until it does — do **not** weaken an assertion to make it pass.

- [ ] **Step 3: Prove these bite**

Mutation A — in `CatchUpController`, add `->whereNull('intentions.workflow')`-equivalent filtering (e.g. `->whereHas('action.intention', fn ($q) => $q->whereNull('workflow'))`). Rerun.
Expected: `test_a_workflow_loop_and_a_plain_loop_leave_the_same_record` FAILS on `has('occurrences', 2)`. **Revert.**

Mutation B — in the migration, give `workflow` a default of `'spec-fake'`. Rerun.
Expected: `test_a_plain_loop_falls_due_catches_up_and_logs_as_it_always_has` FAILS on the final `assertDatabaseHas`. **Revert**, and re-run the migration cleanly.

Mutation C — in `UpdateIntention` or `StartExperiment`, blank `workflow` on revision. Rerun.
Expected: `test_a_workflow_survives_a_strategy_revision` FAILS. **Revert.**

- [ ] **Step 4: Write the economy guard**

Create `tests/Feature/Workflows/WorkflowInvariantTest.php`:

```php
<?php

namespace Tests\Feature\Workflows;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\TestCase;

/**
 * The rule the whole idea rests on: one occasion produces exactly one
 * ActionLog, whatever a workflow recorded.
 *
 * The moment a workflow can mint fuel faster by being more granular, the app
 * has to hold a position on what each module is worth, and every new module
 * reopens the argument. Every workflow that follows must add its own version of
 * the guard below.
 */
class WorkflowInvariantTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->registerSpecFakeWorkflow();
    }

    public function test_recording_at_either_attachment_site_creates_no_log(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);
        $this->assertNotNull($definition);

        // Configuration on the action: what the occasion is meant to contain.
        $definition->config::query()->create([
            'action_id' => $action->id,
            'body' => 'three of the thing',
        ]);

        // A record on the occurrence, forty times over: what it actually
        // contained. A granular workflow must not out-earn a glass of water.
        for ($set = 1; $set <= 40; $set++) {
            $definition->record::query()->create([
                'occurrence_id' => $occurrence->id,
                'body' => 'set '.$set,
            ]);
        }

        $this->assertSame(40, $definition->record::query()->count());
        $this->assertSame(1, $definition->config::query()->count());

        // Nothing above pressed a verdict, so nothing above is worth anything.
        $this->assertSame(0, ActionLog::query()->count());
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_one_occasion_produces_exactly_one_log_however_much_it_recorded(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);

        for ($set = 1; $set <= 40; $set++) {
            $definition->record::query()->create([
                'occurrence_id' => $occurrence->id,
                'body' => 'set '.$set,
            ]);
        }

        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasNoErrors();

        // A second verdict on the same occasion is refused, not appended.
        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasErrors('outcome');

        $this->assertSame(1, ActionLog::query()->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);

        // Forty sets and a glass of water are worth the same, and the record
        // survives the verdict either way.
        $this->assertSame(40, $definition->record::query()->count());
    }

    public function test_a_failed_occasion_still_carries_what_was_recorded(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);
        $definition->record::query()->create([
            'occurrence_id' => $occurrence->id,
            'body' => 'the two things managed before it went wrong',
        ]);

        $this->actingAs($user)->post('/occurrences/'.$occurrence->id.'/logs', [
            'outcome' => 'failed',
            'reason' => 'cut it short',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $definition->record::query()->where('occurrence_id', $occurrence->id)->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);
    }
}
```

- [ ] **Step 5: Run and confirm**

Run: `php artisan test --compact --filter=WorkflowInvariantTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Prove these bite**

Mutation — add a `created` model event to `Tests\Fixtures\Workflows\SpecFakeRecord` that creates an `ActionLog`. Rerun.
Expected: `test_recording_at_either_attachment_site_creates_no_log` FAILS on `ActionLog::count() === 0`, and `test_one_occasion_produces_exactly_one_log_however_much_it_recorded` FAILS on `=== 1`. **Revert.**

This is the mutation a future module would actually make, which is why it is the one worth naming.

- [ ] **Step 7: Run the full suite, format and commit**

Run: `npm run build` then `php artisan test --compact` — expected **875 passed** (869 + 6).

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "test(workflows): pin the invariant and the plain loop

A plain loop's whole life is asserted against a workflow loop's, because a
plain-loop-only assertion passes just as well when workflow loops are being
skipped entirely. Forty fake records and a configuration row move logCount
by zero; the occasion is still worth exactly one log, and a failed occasion
still carries what was recorded before it went wrong."
```

---

### Task 7: The vocabulary list

**Files:**
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php:29-51`

**Interfaces:**
- Consumes: the five source files created in Tasks 2, 4 and 5.
- Produces: nothing.

- [ ] **Step 1: Add the five files to the list**

In `sourceFiles()`, append before the closing `];`:

```php
            $root.'/config/workflows.php',
            $root.'/app/Services/Workflows/WorkflowRegistry.php',
            $root.'/app/Services/Workflows/WorkflowDefinition.php',
            $root.'/resources/js/patyourself/workflows.ts',
            $root.'/resources/js/patyourself/workflow-record.tsx',
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact --filter=CompanionVocabularyTest`
Expected: PASS. **If it fails on `points`, one of the five files says "extension points" or "endpoints"** — fix the prose, do not remove the file from the list.

- [ ] **Step 3: Prove the list bites, by name**

Add the word `streak` to a comment in `config/workflows.php`. Rerun.
Expected: FAIL with a message naming `workflows.php says "streak"`. **Confirm the failure names that file**, not a different one — a list that does not actually reach the new file is the exact failure BLOB.md records having been bitten by. **Remove the word** and rerun.

Repeat once for `resources/js/patyourself/workflows.ts` with the word `percent`, and confirm it fails naming `workflows.ts`. **Remove it.**

- [ ] **Step 4: Full verification**

```bash
npm run build
npx tsc --noEmit          # exactly 1 error: catch-up.tsx(132,33)
npx vitest run            # 359 passed
php artisan test --compact # 875 passed
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test(workflows): the architecture's source files are scanned too

A file absent from the list is scanned by nothing, and this project has been
bitten by exactly that. Proved by planting a banned word in each new file
and watching the failure name it."
```

---

### Task 8: Whole-branch review and integration

- [ ] **Step 1: Request a whole-branch code review**

Use `superpowers:requesting-code-review` against the full diff `origin/main..HEAD`. It has caught real defects on every phase of this project, including two criticals no per-task review could see.

While a review subagent is running, **do not touch the worktree**, and **check the commit rather than the working tree** before believing anything it reports.

- [ ] **Step 2: Act on the review**

Use `superpowers:receiving-code-review`. Verify each point technically before implementing it; a reviewer's in-flight mutation has once looked exactly like a shipped defect here.

- [ ] **Step 3: Re-verify after any change**

```bash
npm run build
npx tsc --noEmit          # exactly 1 error, unchanged
npx vitest run
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Fast-forward main**

Main is checked out in the primary repository, so this worktree cannot merge locally. **Ask the owner before pushing.** Then:

```bash
git push origin HEAD:main
```

and tell the owner to `git pull` in the main checkout, then run there:

```bash
php artisan wayfinder:generate --with-form
```

- [ ] **Step 5: Close the ClickUp task**

Set `14ykddrwhxx` ("Workflow architecture", workspace `9016960004`, list `1300290000000048`) to complete with a comment covering what shipped, the fake-workflow decision, and the final test counts.

**Do not touch `14ykddrwhrk` (gym). It stays on hold. STOP HERE — do not start gym.**

---

## Self-review

**Spec coverage**

| Spec requirement | Task |
| --- | --- |
| `workflow` nullable string on `intentions`, persisted | 1 |
| Registry-backed, chosen not typed | 2, 3 |
| Registry says what each workflow attaches at both extension sites | 2 |
| Both attachment sites optional | 2 (`test_a_workflow_may_attach_nothing_at_either_site`) |
| Null is a plain loop | 1, 4, 5, 6 |
| Known name routes to that workflow's recording UI | 4, 5 |
| Unknown name falls back to the plain UI, never blanks | 2, 4, 5 |
| `Object.hasOwn` / the `'constructor'` trap | 4 |
| One occasion, exactly one `ActionLog` | 6 |
| Recording moves `logCount` by zero | 6 |
| `workflow` survives a strategy revision | 6 |
| Blob's counts move identically for a workflow log and a plain one | 6 |
| A plain loop behaves exactly as today | 6 |
| Deleting a workflow's config leaves its records intact | **not covered — see below** |
| Source files on `CompanionVocabularyTest::sourceFiles()` | 7 |
| `Strategy`/`Action`/`Occurrence`/`ActionLog` gain no columns | held by construction; the diff is the evidence |

**Deliberately deferred, with reason:**

- **"Deleting a workflow's config leaves its records intact"** and **"an imported record with no matching occurrence creates one"** — both are properties of a *module's own* tables and their foreign keys. The fake fixture has no cascade to assert, so a test here would assert the fixture's own schema rather than the architecture's. These belong to gym and to running respectively, and each should carry its own version.
- **A recording UI that actually submits.** Nothing is plugged in, so the seam is proven to render and to fall back; proving a real form's round trip needs a real workflow. Gym.
- **A workflow picker on the loop screen.** No UI sets `workflow` in this batch. The write path is validated and tested through the existing `PATCH /loops/{intention}`; the picker arrives with the first workflow that makes it meaningful.

**Placeholder scan:** one deliberate hole, flagged in place — `PlainLoopIsUnchangedTest::experimentPayload()`, which must be copied from `StoreExperimentRequest` and the existing experiment test rather than invented. Task 6 Step 1 says so explicitly.

**Type consistency:** `workflowFor(name, registry?)` and `WorkflowRecord`'s `registry?` prop share `WorkflowRegistry` from Task 4. `WorkflowRegistry::for()` (PHP) and `workflowFor()` (TS) both return null for null and for unknown. `WorkflowDefinition->config`/`->record` are class-strings; `WorkflowSpec.record` is a component — different sides, different things, deliberately not named alike beyond `record`.

**Running totals to check against:** PHP 852 → 854 (T1) → 862 (T2) → 867 (T3) → 869 (T5) → **875** (T6). JS 341 → 352 (T4) → **359** (T5). TypeScript stays at exactly 1 error throughout.
