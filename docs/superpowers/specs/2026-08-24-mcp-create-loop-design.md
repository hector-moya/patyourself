# MCP `create-loop` — Design

**Date:** 2026-08-24
**Status:** Approved
**Goal:** Let Claude Desktop author a habit loop from a real conversation and persist it into
PatYourSelf as a **paused** loop, which the user reviews and activates in the app.

## Decisions made during brainstorming

- **Claude Desktop authors the structure.** It holds the conversation context that makes a loop
  meaningful; the server does not re-derive it. This is the whole point of the feature.
- **No LLM call inside the tool.** Consistent with the MCP server's founding rule
  (`2026-07-06-mcp-server-design.md`): the client is the intelligence, the server serves data.
  This also means the tool costs nothing per call and cannot trip the coach usage budget.
- **New loops land `paused`, not `active`.** The user commits to the loop, not the model. A loop
  the model invented unreviewed should not start firing notifications.
- **Reuse `AuthorIntention`.** It is already the sole writer for authored loops, already
  transactional, and already builds Intention + Strategy + first Action together.
- **Only `create-loop` is added.** No update/pause/archive/delete tools — those stay in the app.

## Architecture

### The tool

`app/Mcp/Tools/CreateLoopTool.php`, registered last in `PatYourSelfServer::$tools`.

```php
#[Name('create-loop')]
#[Description('Create a new habit loop from a cue -> craving -> response -> reward chain the
user has agreed to. The loop is created PAUSED for the user to review and activate in the app.
Ask the user about their real cue, craving, response and reward before calling this — do not
invent them.')]
```

Input schema mirrors the existing `AuthoredIntention` contract so no new mapping is invented:

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | yes | |
| `description` | string | no | |
| `type` | enum `build` \| `break` | yes | `Intention::TYPE_*` |
| `cue` / `craving` / `response` / `reward` | string | yes | the four-part chain |
| `tags` | string[] | no | |
| `strategy.intervention_point` | enum `cue`\|`craving`\|`response`\|`reward` | yes | `Strategy::POINT_*` |
| `strategy.approach` | string | yes | |
| `strategy.rationale` | string | no | |
| `action.title` | string | no | whole `action` block optional |
| `action.kind` | enum `clock` \| `anchored` | with action | |
| `action.time` | string `HH:MM` | when `clock` | |
| `action.recurrence` | enum `once`\|`daily`\|`weekdays`\|`weekly` | when `clock` | |
| `action.anchor` | string | when `anchored` | |

Handler:

1. Resolve the user from `$request->user()` — never from an argument, so cross-user creation is
   structurally impossible, matching every other tool.
2. Validate via `$request->validate([...])` so bad input returns a tool schema error Claude can
   self-correct from.
3. Build `AuthoredIntention::fromStructured($validated, model: 'mcp-client', promptVersion: 'mcp@1')`.
   Recording the provenance as `mcp-client` distinguishes Desktop-authored loops from
   `IntentionAuthor`-authored ones in `metadata`.
4. `AuthorIntention::handle($user, $title, [], $authored, Intention::STATUS_PAUSED)`.
   The `$goal` argument is only consumed by `userPrompt()` on the LLM branch
   (`AuthorIntention.php:38`, inside `if ($authored === null)`) and is never persisted, so
   passing the title is inert and avoids adding a redundant input field.
5. Return the new loop's id, title and status, plus an explicit instruction that the user must
   activate it in the app.

### `AuthorIntention` change

`persist()` currently hardcodes `'status' => Intention::STATUS_ACTIVE`. Add an optional status
parameter, defaulting to `STATUS_ACTIVE`, threaded from `handle()` into `persist()`. Every
existing caller (`CreateLoop` tool, direct authoring path) is unchanged by construction.

```php
public function handle(
    User $user,
    string $goal,
    array $context = [],
    ?AuthoredIntention $authored = null,
    string $status = Intention::STATUS_ACTIVE,
): Intention
```

### Activation in the app

`PATCH intentions/{intention}` already exists and `UpdateIntention` already accepts `status`;
only the affordance is missing — `resources/js/pages/intentions/show.tsx` renders status as a
read-only `<Badge>`.

Add an **Activate** button on the loop detail page, shown only when
`intention.status === 'paused'`, submitting `status: 'active'` to the existing route via
Wayfinder. No new route, controller, or Action.

### Re-anchoring on activation

`persistAction()` schedules the first action from *creation* time via
`Schedule::firstOccurrence(now(), ...)`. A loop created Monday and activated Thursday would have
an already-overdue action that fires immediately on activation.

When an intention transitions `paused` -> `active`, re-anchor its pending actions to their next
occurrence from now, reusing `Services\Scheduling\Schedule`. This lives in `UpdateIntention`
beside the status write, so it applies however the transition is made.

Anchored (cue-based) actions have no clock time and are left untouched.

## Safety

- `TriggerEngine` already filters `whereHas('intention', status = active)`, so a paused loop
  fires no notifications and its actions never surface in `today-actions`. Verified, no change
  needed.
- The tool is a write, so it runs under the same `mcp:use` scope and Passport guard as
  `log-action-outcome`. No new auth surface.

## Server instructions

Extend `PatYourSelfServer`'s `#[Instructions]` with: `create-loop` exists; it creates loops
**paused** for the user to activate; and Claude must ask the user for their real cue, craving,
response and reward rather than inventing them. This is the guardrail that keeps the framework's
premise — the user commits to the structure — intact when the model is holding the pen.

## Error handling

- Missing or invalid fields -> validation error; Claude self-corrects or asks the user.
- Invalid `type`, `intervention_point`, `kind` or `recurrence` -> validation error naming the
  allowed values.
- `action.kind = clock` without `time`, or `anchored` without `anchor` -> validation error.
- `AuthoredIntention::fromStructured` throwing (structurally invalid) -> `Response::error()`;
  the transaction means nothing partial is written.

## Testing (PHPUnit + vitest)

- Tool: creates a loop with status `paused`; persists the strategy at version 1; persists the
  optional action; omits the action when absent; returns the new id.
- Tool: validation failures for each enum and for the `clock`/`anchored` field pairing.
- Tool: the loop belongs to the authenticated user, and a second user cannot see it via
  `list-loops`.
- Tool: no LLM call occurs — assert with `Summarizer`/`IntentionAuthor` fakes that no agent was
  prompted.
- `AuthorIntention`: honours an explicit status; still defaults to `active` for existing callers.
- Activation: `paused` -> `active` re-anchors a stale pending clock action to the future and
  leaves anchored actions alone.
- `McpEndpointTest`: update the exact-name assertion from five tools to six, keeping
  `create-loop` last, and the instructions test will cover the new name automatically.
- vitest: the Activate button renders only for paused loops and posts the expected payload.

## Out of scope (YAGNI)

- Update, pause, archive or delete tools over MCP.
- A dedicated `draft` status — `paused` already carries the meaning and needs no migration.
- Bulk creation.
- Letting Desktop schedule notifications directly; scheduling stays a property of the action.
- Re-anchoring on any transition other than `paused` -> `active`.
