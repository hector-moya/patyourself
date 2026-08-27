# MCP prompts — daily-check-in and review-experiment

Date: 2026-08-27
Status: designed

## Problem

The MCP server exposes sixteen tools and a paragraph of instructions, and no
entry points. Every conversation with the coach starts from nothing: the user
types a sentence that re-establishes what the app is, which loops exist, and
what a check-in is supposed to do. The framing that makes the record honest —
reasons are verbatim, nothing is overdue, a failure is about the strategy — is
carried only by the server instructions and the tool descriptions, both of which
a client is free to compress or ignore.

Two workflows happen often enough to deserve a name and a shape: the daily
check-in, and the review of an experiment that has reached its `review_at`.

This is the last item of Phase 4 in the lab notebook reframe. The
2026-07-06 MCP server spec deferred prompts explicitly ("tools only for now").

## What a prompt is here

An MCP prompt is a named, discoverable template that seeds a conversation. The
client renders it as something to click. The server returns a list of messages;
the model then works.

These prompts carry **no data**. They return instructions and an opener, and the
coach calls the existing tools to get the record. That is a deliberate choice:

- The query and shaping logic for pending occasions, progress and outcomes lives
  in the tools. Embedding a copy in a prompt would create a second place that has
  to stay honest about "not overdue, not debt".
- A prompt's payload is fixed at the moment it is fetched. Tool results are not.
- The tools already carry the framing in their descriptions.

If a prompt later proves to need orienting context, adding a payload is a small
change. Starting with one is not recoverable in the same way.

## Design

### Files

| Path | What |
| --- | --- |
| `app/Mcp/Prompts/DailyCheckInPrompt.php` | `#[Name('daily-check-in')]`, no arguments |
| `app/Mcp/Prompts/ReviewExperimentPrompt.php` | `#[Name('review-experiment')]`, no arguments |
| `app/Mcp/Servers/PatYourSelfServer.php` | register both in `$prompts`; add a paragraph to `#[Instructions]` |
| `tests/Feature/Mcp/DailyCheckInPromptTest.php` | new |
| `tests/Feature/Mcp/ReviewExperimentPromptTest.php` | new |
| `tests/Feature/Mcp/McpEndpointTest.php` | two drift guards added |

No migrations. No model, service or Action changes. Nothing in these prompts
touches the database.

### Shape of each prompt

Each class extends `Laravel\Mcp\Server\Prompt`, carries `#[Name]` and
`#[Description]` attributes in the same style as the tools, and implements a
single method:

```php
public function handle(Request $request): array
{
    return [
        Response::text($guidance)->asAssistant(),
        Response::text($opener),
    ];
}
```

No `arguments()` method, no dependency injection, no validation. The assistant
message carries the workflow; the user message is the opener that makes the
conversation start as though the user had spoken.

**Why no arguments.** In Claude Desktop a prompt argument renders as a form field
the user fills before the prompt fires. Requiring a loop means knowing which loop
you meant before the coach has told you anything, and it forecloses the "what is
due?" opening that is the point of a check-in. `review-experiment` instead
instructs the coach to find the loops past their `review_at` and ask which, when
more than one is ready.

### daily-check-in

Description: the entry point for a daily check-in — logs the occasions that have
passed and reads back what the reasons show.

Assistant message:

```
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
```

Opener: `Let's do my check-in.`

### review-experiment

Description: the entry point for reviewing an experiment that has reached its
review date — reaches a verdict, updates the narrative, and leaves the next
experiment to the owner.

Assistant message:

```
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
```

Opener: `Let's review my experiment.`

The ordering is load-bearing. The verdict comes before the reflection, because
the reflection describes a record that now contains the verdict. The offer of a
next experiment comes last and is an offer, because concluding is not
superseding: a version concluded as `worked` stays active.

### Server instructions

One paragraph, placed after the check-in walkthrough that already exists:

> Two prompts start the common workflows: `daily-check-in` opens a check-in and
> works through the occasions that went unlogged, and `review-experiment` takes
> an experiment that has reached its review date to a verdict and a reflection.
> They carry the sequence, not the record — you still call the tools.

### Duplication and drift

The three invariant rules already appear in the server `#[Instructions]` and in
the relevant tool descriptions. The prompts repeat only the rules binding on
their own workflow. This is deliberate duplication: a prompt is the moment of
highest leverage, and a client that compressed the server instructions still
gets the rules when the prompt fires.

A shared trait holding the rules was considered and rejected. Two prompts do not
justify the indirection, and the subset each one needs differs. The drift risk is
handled with a test rather than an abstraction — see below.

## Testing

Per prompt, in `tests/Feature/Mcp/`:

- `PatYourSelfServer::actingAs($user)->prompt(PromptClass::class)` returns
  `assertOk`, with `assertName` and `assertDescription` matching the documented
  name.
- The assistant message names the tools the workflow depends on, in order.
- The load-bearing rules are present: for the check-in, that a failed outcome
  carries the user's own words and that skipped means the occasion never
  happened; for the review, that `inconclusive` is a real answer, that a failed
  verdict needs a note about the strategy, and that the next experiment is
  offered rather than assumed.
- Message roles: assistant first, user second.
- The prompt advertises no arguments.

In `McpEndpointTest`, two additions in the style of the existing guards:

- Every registered prompt arrives on the first page of `prompts/list`. The
  server's `defaultPaginationLength = 50` is server-wide, so this holds today;
  the test makes it hold after the next one is added.
- Every tool name mentioned in either prompt's text is a tool the server
  actually registers. This is the drift guard: renaming a tool without updating
  the prompt that calls for it fails here.

**Every load-bearing assertion is mutation-verified.** Change the implementation
so the assertion should fail, run that single test, confirm it fails for the
right reason, restore. A test that passes against both a correct and a broken
implementation is the recurring failure mode in this codebase and is not
acceptable here.

## Out of scope

- Any third prompt. Two workflows are frequent enough to name; a
  `start-experiment` prompt is not, because starting one follows a review.
- MCP resources and apps. Still deferred, as in the 2026-07-06 spec.
- Prompts that carry data. Revisit only if the instructions-only version proves
  to leave the coach genuinely unoriented.
- The dashboard notebook reframe (Phase 3), which remains the largest open UI
  piece and is independent of this.

## Assumptions

- Claude Desktop surfaces server prompts to the user as something to invoke. If
  it does not, these still cost nothing and are reachable by any client that
  does.
- The connector must be reconnected after this ships for the prompts to appear,
  the same as for the tool-list change already waiting.
