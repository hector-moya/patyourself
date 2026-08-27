# write-reflection — the rolling narrative gets a writer

Date: 2026-08-27
Status: approved (design)

## Context

`summaries` has a table, a model, a factory, an `Intention::latestSummary()` relation, two reads in
`ProgressController` (the index card's excerpt and the show page's narrative) and a render in
`progress/show.tsx`. It has had **no writer at all** since `UpdateRollingSummary` was deleted.

So both progress surfaces render a block that can never populate. The show page even carries the
empty state *"Your coach hasn't summarized this loop yet."* — copy for a coach that no longer exists
inside the app.

The table is a chat-era artifact. Its docblock describes "action-log events periodically folded into
a running text summary", and it carries `window_start`, `window_end` and `events_count` — the shape
of a machine fold, which is exactly what the deleted writer did.

The app is now zero-LLM and the coach is Claude over MCP. That does not make the concept wrong; it
moves who does the folding. Every other write on this server already works this way — the coach
authors, the app records.

## Decisions

### A reflection is the coach's narrative, written over MCP

`write-reflection` lets the coach fold what it has read — outcomes, reasons, the experiment's
shape — into one rolling narrative and write it into the record. This fits the existing schema
rather than fighting it, and makes the "Coach summary" heading true again.

Rejected: making it the *user's* words relayed verbatim. That is what `log-note` already is, and it
would leave the window and count columns as dead weight. Rejected: retiring `summaries` altogether —
it is the only surface holding a synthesised view rather than a list, and the strategy timeline and
the notes list both remain lists.

### Each write is a new row; the newest supersedes at read time

`Intention::latestSummary()` already does `latestOfMany()`. So a reflection is appended, never
overwritten, and the reader takes the most recent — which satisfies the project's append-only rule
without any special handling. Earlier reflections stay in the table as the record of what was
believed at the time.

### The app derives the provenance, not the coach

The tool accepts only the narrative. The app fills in:

| Field | Derived as |
| --- | --- |
| `window_start` | the previous reflection's `window_end`; failing that the active strategy version's `created_at`; failing that the loop's `created_at` |
| `window_end` | now |
| `events_count` | outcomes whose **occasion** falls inside that window |

A reflection therefore cannot overstate its own evidence base. A coach that skimmed cannot record a
window it did not read, because it does not get to say what the window was.

`events_count` counts by occasion, dated `occurrence.scheduled_for ?? logged_at` — the same rule
`LoopOutcomesTool` uses for "when did this happen". Counting by `logged_at` would count the moment
of typing, so a catch-up session would inflate a window it does not belong to.

The window is **half-open**: `window_start < occasion <= window_end`. Exclusive at the start so that
consecutive reflections cannot both count an occasion sitting exactly on the boundary — the previous
reflection's `window_end` becomes the next one's `window_start`, and an occasion at that instant
belongs to the earlier window, which already reported it.

### Scope is always `intention`

`Summary::SCOPE_USER` has no reader — `latestSummary()` filters to intention scope — so no
account-level summaries are written. The constant stays; nothing uses it.

## Architecture

**`App\Actions\WriteReflection`** — the only place a reflection is written, following the house
pattern (`LogAction`, `ConcludeExperiment`).

```
WriteReflection::handle(Intention $loop, string $content): Summary
```

Derives the window and the count as above, then creates one `summaries` row with
`scope = intention`. Content is stored **verbatim** — never trimmed, squished or sentence-cased,
like every other authored text in this app.

**`App\Mcp\Tools\WriteReflectionTool`** — `intention_id` (required), `content` (required, max 5000).
Longer than a note or a reason on purpose: this is a synthesis, not a line. Ownership is scoped
through `$request->user()->intentions()`, as every other loop-scoped tool does.

Response: `loop_id`, `content`, `window_start`, `window_end`, `events_count`, `written_at`.

The tool's `#[Description]` carries what binds the narrative's *content*: about the strategy never
the user, no gamification, no numeric targets, and honesty about thin evidence — say what the
record does not show rather than inventing a trend. It also states what separates a reflection from
a note: notes are discrete observations, this is the one rolling narrative, and writing a new one
supersedes the last.

Registered as the 16th tool. `McpEndpointTest` asserts both the exact tool-name list and that every
advertised name appears in the server instructions, so the instructions gain a paragraph too.

## Error handling

- Loop not found, or not owned by the caller — one generic "Not found." No cross-user existence leak,
  consistent with `start-experiment` and `conclude-experiment`.
- A loop with no active strategy version is **not** an error: the window falls back to the loop's
  `created_at`. A loop can legitimately be reflected on before its first experiment is written.
- Blank or whitespace-only content is rejected by validation.

## Constraints this work is bound by

- **Reasons, notes and reflections are verbatim.** Never trimmed, squished or sentence-cased.
- **Failure language is about the strategy, never the user.**
- **No gamification.** A streak is a statistic. The narrative must not congratulate.
- **No quantities on eating loops.** No calories, portions, weights, numeric targets.
- **The notebook never nags.** The empty state stays neutral.
- **Append-only.** A reflection supersedes by being newer, never by overwriting.

## Out of scope

No schema change. No frontend change — the render and its empty state already exist and are
adequate. No account-level (`user` scope) summaries. No automatic or scheduled summarising: writing
a reflection is a deliberate act the coach takes, not a cron. The `/progress` index card's excerpt
logic is untouched.

## Testing

- `WriteReflection`: window derived from the previous reflection when one exists; from the active
  version's `created_at` when it does not; from the loop's `created_at` when there is no active
  version. `events_count` counts by occasion rather than by `logged_at` — pinned with a catch-up log
  whose occasion is outside the window and whose typing moment is inside it, which is the case that
  distinguishes the two rules. Content stored verbatim from a padded, mixed-case fixture. A second
  write appends rather than overwrites, and `latestSummary()` returns the newer.
- `WriteReflectionTool`: happy path, ownership scoping, blank content rejected, response shape.
- `ProgressShowTest`: a loop with a written reflection renders it where the empty state was.
