# MCP Prompts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the PatYourSelf MCP server two named entry points — `daily-check-in` and `review-experiment` — so the common workflows start in one click instead of a paragraph of re-established context.

**Architecture:** Two `Laravel\Mcp\Server\Prompt` subclasses in `app/Mcp/Prompts/`, registered in the server's `$prompts` array. Each returns two messages — an assistant message carrying the workflow and the rules that bind it, then a user message that opens the conversation. Neither takes arguments and neither touches the database; the coach calls the existing sixteen tools for the record.

**Tech Stack:** PHP 8.4, Laravel 13, `laravel/mcp` v0, PHPUnit 12, Laravel Pint.

## Global Constraints

Copied verbatim from the spec and CLAUDE.md. Every task's requirements implicitly include this section.

- Reasons, notes and reflections are **verbatim**. Never trim, squish or sentence-case, in any path.
- Failure language is about **the strategy, never the user**. No discipline, willpower or motivation framing.
- **No quantities on eating loops.** No calories, portions, weights or numeric targets, anywhere.
- **The notebook never nags.** No overdue counts, no red backlog states, nothing presented as debt.
- `skipped` = the occasion never happened. "Didn't think about it" is a `failed` with a reason.
- **No gamification.** A streak is a statistic.
- Every change is programmatically tested. Failing test first, watched failing for the right reason, then implement.
- **Every load-bearing assertion is mutation-verified**: change the implementation so the assertion should fail, run that single test, confirm it fails for the right reason, restore. A test that passes against both a correct and a broken implementation is this codebase's recurring failure mode.
- `vendor/bin/pint --dirty --format agent` before each commit.
- PHP: curly braces always, constructor property promotion, explicit return types and parameter type hints, PHPDoc blocks over inline comments, array shapes in PHPDoc.
- No new base directories. No dependency changes.
- No migrations in this plan. Nothing here touches the database, so the MySQL cross-check the repo requires for migrations does not apply.

### Verified framework API

These were read out of `vendor/laravel/mcp/src` and are correct for the installed version. Do not re-derive them.

| Fact | Value |
| --- | --- |
| Base class | `Laravel\Mcp\Server\Prompt` (abstract, extends `Primitive`) |
| Handler | `handle(Request $request): array` returning `Laravel\Mcp\Response[]` |
| Default `arguments()` | Returns `[]`. Do **not** override it — omitting it is how a prompt takes no arguments. |
| Assistant role | `Response::text($s)->asAssistant()`; plain `Response::text($s)` is `Role::User` |
| Registration | `protected array $prompts = [...]` on the server |
| Test helper | `PatYourSelfServer::actingAs($user)->prompt(PromptClass::class)` (via `Server::__callStatic`) |
| `TestResponse` asserts | `assertOk`, `assertName`, `assertTitle`, `assertDescription`, `assertSee`, `assertDontSee`, `assertHasNoErrors` |
| `TestResponse::content()` | **protected** — reach it with `ReflectionMethod`, as `LogNoteToolTest::payload()` already does |
| `prompts/list` result | `result.prompts` (array of `{name, title, description, arguments}`), `result.nextCursor` |
| `prompts/get` params | `{"name": "<prompt-name>"}`; result is `result.description` and `result.messages[]` with `role` and `content` |
| `Role` enum values | `'assistant'`, `'user'` |

---

### Task 1: DailyCheckInPrompt

The check-in entry point. Ships registered and tested on its own — `prompts/list` returns one prompt after this task and that is a working state.

**Files:**
- Create: `app/Mcp/Prompts/DailyCheckInPrompt.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (add `use` import; add `$prompts` property)
- Test: `tests/Feature/Mcp/DailyCheckInPromptTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\Mcp\Prompts\DailyCheckInPrompt`, MCP name `daily-check-in`, no arguments, `handle(Request $request): array` returning exactly two `Response` objects — `[0]` assistant, `[1]` user. Task 3's endpoint guards iterate whatever `$prompts` contains, so they need no direct reference. The `protected array $prompts` property created here is extended by Task 2.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/DailyCheckInPromptTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\DailyCheckInPrompt;
use App\Mcp\Servers\PatYourSelfServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The entry point for a check-in. It carries the sequence and the rules that
 * bind it — not the record, which the tools still supply.
 */
class DailyCheckInPromptTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(): TestResponse
    {
        return PatYourSelfServer::actingAs(User::factory()->create())
            ->prompt(DailyCheckInPrompt::class);
    }

    public function test_it_advertises_itself_under_its_documented_name(): void
    {
        $this->prompt()
            ->assertOk()
            ->assertHasNoErrors()
            ->assertName('daily-check-in');
    }

    public function test_it_carries_a_description(): void
    {
        $this->prompt()->assertDescription(
            'Start a check-in: work through the occasions that have passed without an outcome, in the user\'s own words, then read back what the reasons show. Carries the sequence, not the record — the tools still supply the data.'
        );
    }

    /**
     * The sequence is the point of the prompt. Each of these is a real tool,
     * which Task 3 asserts separately against the registered list.
     */
    public function test_it_names_the_tools_the_check_in_walks_through(): void
    {
        $this->prompt()->assertSee([
            'pending-outcomes',
            'log-outcome',
            'log-note',
            'loop-outcomes',
            'loop-progress',
            'get-loop',
        ]);
    }

    /**
     * A reason that gets paraphrased is not evidence. This is the rule the next
     * experiment gets written from, so it has to survive in the prompt text.
     */
    public function test_it_requires_a_failed_outcome_to_carry_the_users_own_words(): void
    {
        $this->prompt()->assertSee([
            'must carry their stated reason in their own words',
            'passed through unchanged',
        ]);
    }

    /**
     * skipped and failed are not interchangeable: skipped leaves the occasion
     * out of the completion-rate denominator entirely.
     */
    public function test_it_distinguishes_a_skipped_occasion_from_a_failed_one(): void
    {
        $this->prompt()->assertSee([
            'skipped means the occasion never happened',
            'not thinking about it, that is failed',
        ]);
    }

    /**
     * The notebook never nags. A backlog is a list to work through, not debt.
     */
    public function test_it_forbids_presenting_the_backlog_as_debt(): void
    {
        $this->prompt()->assertSee([
            'Nothing here is overdue',
            'Never count the backlog back at them',
            'never propose a numeric target',
            'about the strategy rather than about the user',
        ]);
    }

    /**
     * A review is its own workflow with its own prompt. The check-in hands off
     * rather than swallowing it.
     */
    public function test_it_offers_the_review_rather_than_running_it(): void
    {
        $this->prompt()->assertSee('offer the review — do not run');
    }

    /**
     * In Claude Desktop an argument renders as a form field the user fills
     * before the prompt fires. This one fires on a click.
     */
    public function test_it_takes_no_arguments(): void
    {
        $this->assertSame([], (new DailyCheckInPrompt)->arguments());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/DailyCheckInPromptTest.php`

Expected: FAIL — `Class "App\Mcp\Prompts\DailyCheckInPrompt" not found`. Every test in the file errors. That is the right reason.

- [ ] **Step 3: Create the prompt class**

Run `php artisan make:mcp-prompt DailyCheckInPrompt --no-interaction`, then replace the generated file with:

```php
<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('daily-check-in')]
#[Description(<<<'TEXT'
Start a check-in: work through the occasions that have passed without an
outcome, in the user's own words, then read back what the reasons show.
Carries the sequence, not the record — the tools still supply the data.
TEXT)]
class DailyCheckInPrompt extends Prompt
{
    /**
     * Seed a check-in.
     *
     * No arguments and no database access: this returns the workflow and the
     * rules that bind it, and the coach calls the tools for the record. A
     * payload embedded here would be a second place that has to stay honest
     * about "not overdue", and it would be stale the moment it was fetched.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text(<<<'TEXT'
                Open the user's check-in. The app is the record; you are the coach.

                1. pending-outcomes — the occasions that have passed with no outcome yet.
                   Present them as a short list, not an audit. Nothing here is overdue and
                   nothing expires. If there are none, say so plainly and read the record
                   instead — a check-in with nothing pending is a good check-in.
                2. Ask how each went, one loop at a time, and log-outcome as they answer. A
                   failed outcome must carry their stated reason in their own words, passed
                   through unchanged. skipped means the occasion never happened — no meal,
                   travelling, ill. If it happened and the strategy did not hold, including
                   not thinking about it, that is failed.
                3. log-note anything they mention that is not an outcome.
                4. loop-outcomes where the answers were interesting, to read the reasons back
                   and see where the chain is actually breaking.
                5. loop-progress and get-loop to see how a running experiment is holding up.

                If a version is past its review_at, say so and offer the review — do not run
                it here.

                Never count the backlog back at them, never propose a numeric target, and keep
                every failure about the strategy rather than about the user.
                TEXT)->asAssistant(),
            Response::text("Let's do my check-in."),
        ];
    }
}
```

Note the description heredoc is hard-wrapped across three lines but the test asserts it as one line. `#[Description]` collapses nothing — so the test string in Step 1 must match the attribute exactly. If Step 4 fails on `assertDescription`, make the attribute a single unwrapped line rather than editing the expectation.

- [ ] **Step 4: Register the prompt on the server**

In `app/Mcp/Servers/PatYourSelfServer.php`, add the import alongside the existing `use App\Mcp\Tools\...` block:

```php
use App\Mcp\Prompts\DailyCheckInPrompt;
```

and add this property immediately after the closing `];` of `$tools`:

```php
    /**
     * The prompts registered with this MCP server.
     *
     * Named entry points for the workflows that happen often enough to deserve
     * one. They carry the sequence, not the record.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        DailyCheckInPrompt::class,
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/DailyCheckInPromptTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 6: Mutation-verify the load-bearing assertions**

Do these one at a time. For each: make the edit, run the single named test, confirm it fails, restore the file, confirm it passes again.

Run each with `php artisan test --compact --filter=<test_name> tests/Feature/Mcp/DailyCheckInPromptTest.php`.

1. Delete `in their own words, passed\n   through unchanged` from the prompt text → `test_it_requires_a_failed_outcome_to_carry_the_users_own_words` must fail.
2. Replace `skipped means the occasion never happened` with `skipped means it did not go well` → `test_it_distinguishes_a_skipped_occasion_from_a_failed_one` must fail.
3. Delete the whole final paragraph (`Never count the backlog back at them...`) → `test_it_forbids_presenting_the_backlog_as_debt` must fail.
4. Change `log-note` to `note-log` in the prompt text → `test_it_names_the_tools_the_check_in_walks_through` must fail.
5. Add an `arguments()` method returning one `Argument` → `test_it_takes_no_arguments` must fail.

If any of these five passes unchanged, the assertion is not discriminating. Fix the test, not the mutation.

- [ ] **Step 7: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Mcp/Prompts/DailyCheckInPrompt.php app/Mcp/Servers/PatYourSelfServer.php tests/Feature/Mcp/DailyCheckInPromptTest.php
git commit -m "feat(mcp): daily-check-in gives the check-in a front door

The workflow existed only as prose in the server instructions, which a
client is free to compress. A prompt puts the sequence and the rules that
bind it at the moment of highest leverage.

Carries no record: the query shaping for pending occasions stays in the
tool rather than gaining a second home that has to stay honest about
\"not overdue\"."
```

---

### Task 2: ReviewExperimentPrompt

The review entry point. Ends in a verdict and a reflection, and offers the next experiment rather than assuming it.

**Files:**
- Create: `app/Mcp/Prompts/ReviewExperimentPrompt.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (add `use` import; add to `$prompts`)
- Test: `tests/Feature/Mcp/ReviewExperimentPromptTest.php`

**Interfaces:**
- Consumes: the `protected array $prompts` property created in Task 1 Step 4.
- Produces: `App\Mcp\Prompts\ReviewExperimentPrompt`, MCP name `review-experiment`, no arguments, same two-message assistant-then-user shape as Task 1.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mcp/ReviewExperimentPromptTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\ReviewExperimentPrompt;
use App\Mcp\Servers\PatYourSelfServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The entry point for reviewing an experiment that has reached its review date.
 * The ordering is load-bearing: the verdict comes before the reflection,
 * because the reflection describes a record that now contains the verdict.
 */
class ReviewExperimentPromptTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(): TestResponse
    {
        return PatYourSelfServer::actingAs(User::factory()->create())
            ->prompt(ReviewExperimentPrompt::class);
    }

    public function test_it_advertises_itself_under_its_documented_name(): void
    {
        $this->prompt()
            ->assertOk()
            ->assertHasNoErrors()
            ->assertName('review-experiment');
    }

    public function test_it_carries_a_description(): void
    {
        $this->prompt()->assertDescription(
            'Review an experiment that has reached its review date: reach a verdict on the evidence, update the loop\'s rolling narrative, and leave the next experiment to the owner.'
        );
    }

    public function test_it_names_the_tools_the_review_walks_through(): void
    {
        $this->prompt()->assertSee([
            'list-loops',
            'loop-progress',
            'loop-outcomes',
            'get-loop',
            'conclude-experiment',
            'write-reflection',
            'start-experiment',
        ]);
    }

    /**
     * Finding the loop is the prompt's job, not the user's — which is why it
     * takes no arguments.
     */
    public function test_it_finds_the_loop_itself_and_stops_when_none_is_ready(): void
    {
        $this->prompt()->assertSee([
            'past its review_at',
            'If none is ready, say so and stop',
            'If more than one',
        ]);
    }

    /**
     * A version is judged on its own run, not on the loop's lifetime record —
     * otherwise a loop with a bad history can never show a working strategy.
     */
    public function test_it_judges_the_version_on_its_own_evidence(): void
    {
        $this->prompt()->assertSee('on its own evidence, not the loop');
    }

    /**
     * Thin evidence is a finding. Forcing a verdict the record does not support
     * is how the notebook starts lying to its owner.
     */
    public function test_it_treats_inconclusive_as_a_real_answer(): void
    {
        $this->prompt()->assertSee([
            'inconclusive is a real answer',
            'Thin evidence is a finding',
        ]);
    }

    public function test_it_requires_a_failed_verdict_to_carry_its_reason(): void
    {
        $this->prompt()->assertSee([
            'A failed verdict must carry a note saying what the evidence showed',
            'about the strategy rather than the user',
        ]);
    }

    /**
     * Concluding is not superseding. A version concluded as worked stays active
     * and keeps running, so the next experiment is the owner's decision.
     */
    public function test_it_offers_the_next_experiment_rather_than_assuming_it(): void
    {
        $this->prompt()->assertSee([
            'concluded as\nworked stays active and keeps running',
            'a separate\ndecision, and it is theirs',
        ]);
    }

    public function test_it_takes_no_arguments(): void
    {
        $this->assertSame([], (new ReviewExperimentPrompt)->arguments());
    }
}
```

The two expectations in `test_it_offers_the_next_experiment_rather_than_assuming_it` contain `\n` because the prompt text wraps mid-phrase. In a double-quoted PHP string `\n` is a real newline, which is what the heredoc contains. If the wrap points move, update these two strings to match the new wrapping rather than weakening them to single words.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/ReviewExperimentPromptTest.php`

Expected: FAIL — `Class "App\Mcp\Prompts\ReviewExperimentPrompt" not found`.

- [ ] **Step 3: Create the prompt class**

Run `php artisan make:mcp-prompt ReviewExperimentPrompt --no-interaction`, then replace the generated file with:

```php
<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('review-experiment')]
#[Description(<<<'TEXT'
Review an experiment that has reached its review date: reach a verdict on the
evidence, update the loop's rolling narrative, and leave the next experiment to
the owner.
TEXT)]
class ReviewExperimentPrompt extends Prompt
{
    /**
     * Seed a review.
     *
     * Takes no loop argument on purpose. In a client an argument renders as a
     * form field filled before the prompt fires, which would mean naming the
     * loop before the coach has told you anything — and it forecloses the
     * "what is ready?" opening that is the point.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text(<<<'TEXT'
                Review the experiment that is ready for a verdict.

                1. list-loops. Find active loops whose current version is past its review_at.
                   If none is ready, say so and stop — nothing is overdue. If more than one
                   is, ask which.
                2. loop-progress. Judge the version on its own evidence, not the loop's
                   lifetime record.
                3. loop-outcomes to read the user's reasons verbatim; get-loop for the chain
                   and any notes.
                4. Say what the evidence shows and what it does not. Thin evidence is a
                   finding, not a gap to paper over.
                5. conclude-experiment — worked, failed or inconclusive. inconclusive is a
                   real answer when the experiment did not run long or cleanly enough to
                   judge. A failed verdict must carry a note saying what the evidence showed,
                   about the strategy rather than the user.
                6. write-reflection to update the loop's rolling narrative.
                7. Only then ask whether they want a new experiment. A version concluded as
                   worked stays active and keeps running — starting the next is a separate
                   decision, and it is theirs. If they do, start-experiment on the point in
                   the chain their reasons actually implicate.
                TEXT)->asAssistant(),
            Response::text("Let's review my experiment."),
        ];
    }
}
```

- [ ] **Step 4: Register the prompt on the server**

In `app/Mcp/Servers/PatYourSelfServer.php`, add the import:

```php
use App\Mcp\Prompts\ReviewExperimentPrompt;
```

and extend the array:

```php
    protected array $prompts = [
        DailyCheckInPrompt::class,
        ReviewExperimentPrompt::class,
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/ReviewExperimentPromptTest.php`

Expected: PASS, 9 tests.

- [ ] **Step 6: Mutation-verify the load-bearing assertions**

One at a time; edit, run the single test, confirm failure, restore, confirm pass.

Run each with `php artisan test --compact --filter=<test_name> tests/Feature/Mcp/ReviewExperimentPromptTest.php`.

1. Change `inconclusive is a\n   real answer` to `inconclusive is a\n   last resort` → `test_it_treats_inconclusive_as_a_real_answer` must fail.
2. Change step 7's `Only then ask whether they want a new experiment` to `Then start the next experiment` **and** delete the `worked stays active and keeps running` clause → `test_it_offers_the_next_experiment_rather_than_assuming_it` must fail.
3. Delete `If none is ready, say so and stop — nothing is overdue.` → `test_it_finds_the_loop_itself_and_stops_when_none_is_ready` must fail.
4. Change `on its own evidence, not the loop's` to `against the loop's lifetime record` → `test_it_judges_the_version_on_its_own_evidence` must fail.
5. Delete `A failed verdict must carry a note saying what the evidence showed,` → `test_it_requires_a_failed_verdict_to_carry_its_reason` must fail.
6. Swap steps 5 and 6 so `write-reflection` precedes `conclude-experiment`. Note which tests still pass — the ordering is load-bearing but no current test enforces it. **If none fails, add a test that asserts `conclude-experiment` appears at a lower string offset than `write-reflection` in the rendered text**, then restore.

- [ ] **Step 7: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Mcp/Prompts/ReviewExperimentPrompt.php app/Mcp/Servers/PatYourSelfServer.php tests/Feature/Mcp/ReviewExperimentPromptTest.php
git commit -m "feat(mcp): review-experiment takes an experiment to a verdict

Verdict, then reflection, then an offer. The order matters: the reflection
describes a record that now contains the verdict, and concluding is not
superseding — a version concluded as worked stays active, so the next
experiment stays the owner's decision.

No loop argument. Finding what is ready is the prompt's job."
```

---

### Task 3: Server instructions and endpoint drift guards

Tells the coach the prompts exist, and adds the two self-maintaining guards that keep them honest as the server grows — in the style of the two `McpEndpointTest` already carries.

**Files:**
- Modify: `app/Mcp/Servers/PatYourSelfServer.php` (`#[Instructions]` attribute)
- Modify: `tests/Feature/Mcp/McpEndpointTest.php`

**Interfaces:**
- Consumes: `$prompts` containing both classes from Tasks 1 and 2.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Mcp/McpEndpointTest.php`, add this constant directly above the first test method:

```php
    /**
     * Hyphenated words in the prompt prose that are English, not tool names.
     * Adding a hyphenated word to a prompt means adding it here — the guard
     * below deliberately errs toward failing loudly.
     *
     * @var array<int, string>
     */
    private const PROSE_COMPOUNDS = ['check-in'];
```

Then add these three tests after `test_every_advertised_tool_name_appears_in_the_server_instructions`:

```php
    /**
     * Same trap as tools/list: Laravel MCP paginates at 15 by default and this
     * server raises it to 50. Asserted against the registered count rather than
     * a literal so it keeps holding as prompts are added.
     */
    public function test_every_registered_prompt_arrives_on_the_first_page(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->promptsList();

        $response->assertOk();

        $registered = (new \ReflectionClass(PatYourSelfServer::class))
            ->getDefaultProperties()['prompts'];

        $this->assertCount(count($registered), $response->json('result.prompts'));
        $this->assertNull($response->json('result.nextCursor'));
    }

    /**
     * A prompt seeds a conversation: the guidance is the assistant's, and the
     * opener has to be the user's, or the coach reads its own instructions back
     * as though the user had asked for them.
     */
    public function test_every_prompt_opens_with_guidance_and_ends_with_the_user_speaking(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        foreach (array_column($this->promptsList()->json('result.prompts'), 'name') as $name) {
            $this->assertSame(
                ['assistant', 'user'],
                array_column($this->promptsGet($name)->json('result.messages'), 'role'),
                "Prompt [{$name}] does not open with guidance and end with the user speaking.",
            );
        }
    }

    /**
     * A prompt that names a tool the server does not register sends Claude after
     * something that 404s — the mirror image of the instructions guard above.
     * Scanned out of the rendered messages rather than checked against a literal
     * list, so a typo in the prose fails here rather than shipping.
     */
    public function test_every_tool_a_prompt_names_is_a_registered_tool(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $registered = array_column($this->toolsList()->json('result.tools'), 'name');

        foreach (array_column($this->promptsList()->json('result.prompts'), 'name') as $name) {
            $rendered = (string) json_encode($this->promptsGet($name)->json('result.messages'));

            preg_match_all('/\b[a-z]+(?:-[a-z]+)+\b/', $rendered, $matches);

            $named = array_values(array_diff(array_unique($matches[0]), self::PROSE_COMPOUNDS));

            $this->assertNotEmpty($named, "Prompt [{$name}] names no tools at all.");

            foreach ($named as $tool) {
                $this->assertContains(
                    $tool,
                    $registered,
                    "Prompt [{$name}] names [{$tool}], which is not a registered tool.",
                );
            }
        }
    }
```

And add these two helpers next to the existing private `toolsList()`:

```php
    private function promptsList(): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'prompts/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    private function promptsGet(string $name): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'prompts/get',
            'params' => ['name' => $name],
        ], ['Accept' => 'application/json, text/event-stream']);
    }
```

- [ ] **Step 2: Run the tests to see where they stand**

Run: `php artisan test --compact tests/Feature/Mcp/McpEndpointTest.php`

Expected: the three new tests **pass** — Tasks 1 and 2 already satisfy them. That is fine and expected; these are regression guards, not drivers. What matters is Step 5, which proves they can fail. If any fails now, fix the cause before continuing:

- A failure naming an unregistered tool means a typo in a prompt's prose. Fix the prompt.
- A failure on `PROSE_COMPOUNDS` means a prompt gained a hyphenated English word. Add it to the constant.
- A role failure means a `Response::text(...)` lost its `->asAssistant()`.

- [ ] **Step 3: Add the prompts paragraph to the server instructions**

In `app/Mcp/Servers/PatYourSelfServer.php`, inside the `#[Instructions]` heredoc, insert this paragraph immediately after the paragraph that begins "A check-in usually goes:" and before the "conclude-experiment ends the current experiment" paragraph:

```
Two prompts start the common workflows: daily-check-in opens a check-in and
works through the occasions that went unlogged, and review-experiment takes an
experiment that has reached its review date to a verdict and a reflection. They
carry the sequence, not the record — you still call the tools.
```

- [ ] **Step 4: Run the full MCP test directory to verify nothing regressed**

Run: `php artisan test --compact tests/Feature/Mcp`

Expected: PASS. Note that `test_every_advertised_tool_name_appears_in_the_server_instructions` still passes — the new paragraph adds prose without removing any tool name.

- [ ] **Step 5: Mutation-verify the guards**

These three tests pass on arrival, so proving they discriminate is the whole point of this step. Do not skip it.

Run each with `php artisan test --compact --filter=<test_name> tests/Feature/Mcp/McpEndpointTest.php`.

1. In `DailyCheckInPrompt`, change `loop-progress` to `loop-progres` → `test_every_tool_a_prompt_names_is_a_registered_tool` must fail naming `loop-progres`. Restore.
2. In `ReviewExperimentPrompt`, add the phrase `well-being` to the text → the same test must fail naming `well-being`, proving the `PROSE_COMPOUNDS` escape hatch is load-bearing rather than decorative. Restore.
3. In `DailyCheckInPrompt`, drop `->asAssistant()` → `test_every_prompt_opens_with_guidance_and_ends_with_the_user_speaking` must fail with `['user', 'user']`. Restore.
4. Set `public int $defaultPaginationLength = 1;` on the server → `test_every_registered_prompt_arrives_on_the_first_page` must fail, and so must the existing tools equivalent. Restore.
5. Remove the new paragraph from `#[Instructions]` → confirm nothing fails. This is expected: no test asserts the paragraph exists, because its content is prose, not contract. Restore it.

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Mcp/Servers/PatYourSelfServer.php tests/Feature/Mcp/McpEndpointTest.php
git commit -m "test(mcp): guard the prompts against the drift that hides silently

Three guards in the shape of the two already here. A prompt naming a tool
that does not exist sends Claude after a 404; a prompt whose opener is not
the user's makes the coach read its own instructions back as a request;
and prompts/list paginates on the same default that hid the sixteenth tool.

The tool names are scanned out of the rendered messages rather than checked
against a literal list, so a typo in the prose fails here."
```

---

### Task 4: Full verification and merge

**Files:**
- Modify: none. This task only runs things and merges.

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: `main` carrying the work.

- [ ] **Step 1: Run the full PHP suite**

Run: `php artisan test --compact`

Expected: PASS. The baseline is 560 tests; this plan adds 19 (7 + 9 + 3), so expect 579. A count materially below that means a test file was not picked up.

- [ ] **Step 2: Run the frontend checks**

Run: `npx vitest run && npm run build && npm run lint`

Expected: all pass. Nothing in this plan touches the frontend, so any failure here is pre-existing or an artifact of worktree setup — investigate before merging rather than assuming it is unrelated.

- [ ] **Step 3: Confirm the working tree is clean of setup noise**

Run: `git status --short`

Expected: empty. If `package-lock.json` shows as modified, `npm install` rewrote its `name` field to the worktree directory name — run `git checkout -- package-lock.json`. Never commit it.

- [ ] **Step 4: Verify the shipped surface by hand**

The server is a web server registered at `/mcp` in `routes/ai.php`, so the inspector takes the path without its leading slash:

Run: `php artisan mcp:inspector mcp`

The endpoint is Passport-guarded, so the inspector needs an `Authorization: Bearer <token>` header with the `mcp:use` scope to get past `initialize`.

Confirm both prompts appear, both render, and neither asks for an argument. This is the one check the test suite cannot make on the user's behalf, because it is about what the client shows.

- [ ] **Step 5: Merge into main and push**

No pull request — CI fires on `main` only, and a PR per feature doubles the runs.

```bash
git checkout main
git merge --no-ff worktree-mcp-prompts -m "Merge branch 'worktree-mcp-prompts': the server gets two front doors"
php artisan test --compact
git push origin main
```

Run the suite again after the merge, before the push. If it fails, the merge is still local and revertable.

- [ ] **Step 6: Report what still needs the owner's hands**

These are unchanged by this plan and remain blocking real use:

1. Deploy — now five merges unshipped. Seven migrations across the earlier branches; this plan adds none.
2. Reconnect the Claude Desktop connector. It now needs to pick up two prompts as well as the sixteen tools and the rewritten instructions.
3. Delete `ANTHROPIC_API_KEY` from Forge and from `.env`.

## Self-Review

**Spec coverage.** Every section of `2026-08-27-mcp-prompts-design.md` maps to a task: the two prompt classes and their registration (Tasks 1, 2), the server instructions paragraph (Task 3), both `McpEndpointTest` drift guards (Task 3), and the per-prompt tests (Tasks 1, 2). The spec's "no migrations, no DB access" claim is enforced by the prompts having no constructor and no injected dependencies.

**Placeholders.** None. Every code step carries the code. The one judgement call left open is Task 2 Step 6 mutation 6, which is deliberate: it asks the implementer to check whether ordering is enforced and to add a test if it is not, rather than pretending to know the answer.

**Type consistency.** `handle(Request $request): array` returning `array<int, Response>` in both prompts. `arguments()` is never overridden in either, and both tests assert `[]` against the inherited default. `$prompts` is `protected array` in both tasks that touch it. `promptsList()` and `promptsGet()` both return `Illuminate\Testing\TestResponse`, matching the existing `toolsList()` — note this is a different `TestResponse` from the `Laravel\Mcp\Server\Testing\TestResponse` the per-prompt tests import, which is correct: the endpoint tests go over HTTP and the prompt tests go through the server helper.

**Known fragility, accepted.** Three tests assert on exact prompt wording, including two that span a line wrap. Rewording a prompt breaks them. That is the intended trade: the wording *is* the deliverable here, so a test that survives arbitrary rewording would not be testing anything.
