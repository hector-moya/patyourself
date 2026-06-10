# PatYourSelf AI side on the Laravel AI SDK — design

**Date:** 2026-06-10
**Status:** Approved (brainstormed with Hector; decisions recorded below)
**Scope:** Replace the hand-rolled coach stack with `laravel/ai` agents in an
orchestrator + specialists architecture, migrating one capability at a time.

## Context

The app's AI layer is currently hand-rolled: a provider-agnostic `CoachService`
contract with an `AnthropicCoachService` HTTP driver, a `GuardedCoachService`
cost-guard decorator, and four domain services (`ChatCoach`, `IntentionAuthor`,
`StrategyReviser`, `RollingSummaryService`) that each prompt the coach with a
JSON schema and validate the response. It works and is well-tested (28 coach
tests), but every new capability re-implements plumbing the Laravel AI SDK
(`laravel/ai`, first-party, Feb 2026) now provides: agents, tools, sub-agents,
structured output, conversations, middleware, and per-agent test fakes.

The app is on Laravel 13.12 / PHP 8.4 — the SDK installs directly.

## Decisions (made during brainstorming)

1. **Migration strategy: per-capability strangler.** Convert one capability at
   a time — Summarizer, then Strategist, then the Coach orchestrator — each as
   its own commit with tests, deleting the hand-rolled stack only when the last
   capability has moved. The app stays green throughout.
2. **Chat memory: SDK conversations.** Adopt the SDK's `agent_conversations`
   persistence. Each user gets one durable coach conversation; the frontend
   stops shipping a 50-turn history with every request, and the thread hydrates
   from the server so chat survives reloads.
3. **v1 tool surface: author + read-only.** The orchestrator can author loops
   (via the IntentionAuthor sub-agent) and read the user's data (loops, loop
   detail, latest summary). Write tools (log an outcome from natural language,
   revise a strategy mid-chat) are explicitly deferred to a later release.

## Target architecture

```
app/Ai/
├── Agents/
│   ├── Coach.php            Orchestrator. Agent, Conversational, HasTools, HasMiddleware.
│   │                        #[Provider(Lab::Anthropic)] #[Model('claude-sonnet-4-6')].
│   │                        Handles every chat turn against the user's conversation.
│   ├── IntentionAuthor.php  Specialist. CanActAsTool + HasStructuredOutput.
│   │                        Authors a loop (cue/craving/response/reward + strategy)
│   │                        as structured JSON. Never touches the database.
│   ├── Strategist.php       Specialist. HasStructuredOutput. Authors strategy
│   │                        revisions (restrategize on failure / stack on success).
│   │                        Serves the ReviseStrategy action; not a chat tool in v1.
│   └── Summarizer.php       Specialist. HasStructuredOutput. Folds action-log events
│                            into rolling pattern summaries. Serves UpdateRollingSummary;
│                            not a chat tool in v1.
├── Tools/
│   ├── CreateLoop.php       Orchestrator tool. Prompts IntentionAuthor, persists the
│   │                        result through the AuthorIntention action, registers the
│   │                        new loop with the turn collector, returns a confirmation.
│   ├── ListLoops.php        Read-only: the user's loops at a glance.
│   ├── GetLoopDetail.php    Read-only: anatomy, active strategy, recent logs for one loop.
│   └── GetLatestSummary.php Read-only: the latest rolling summary for a loop.
├── Middleware/
│   └── GuardCoachUsage.php  Port of the cost guard: ensureWithinBudget(user) before the
│                            call, record $response->usage into coach_usages after.
└── TurnCollector.php        Request-scoped collector. Tools that create renderable
                             data (loops) register ids here; ChatController reads it
                             after the turn to build the cards payload.
```

### Principles preserved

- **"AI authors data, UI renders it."** Specialists return structured data;
  the existing Actions (`AuthorIntention`, `ReviseStrategy`,
  `UpdateRollingSummary`) remain the only database writers. The `CreateLoop`
  tool calls `AuthorIntention` — it does not write directly.
- **All LLM calls server-side.** Unchanged; agents run inside Laravel.
- **Strategies versioned, never rewritten.** Unchanged; the Strategist feeds
  the same append-only Action.
- **Pattern detection via rolling summaries.** Unchanged; the Summarizer feeds
  the same Summary model.
- **Provider swappability** now comes from the SDK's provider abstraction
  (14 providers) instead of the bespoke `CoachManager`.

### Chat turn flow (after Phase 3)

1. `POST /chat {message}` → `ChatController` (auth, `throttle:coach`).
2. Controller resolves the user's durable coach conversation (firstOrCreate)
   and prompts the `Coach` agent within it.
3. The Coach may call tools: read tools for context, `CreateLoop` to author a
   loop. `CreateLoop` prompts the `IntentionAuthor` sub-agent (structured),
   persists via `AuthorIntention`, registers the loop id with `TurnCollector`.
4. Controller returns `{message, cards}` — cards built from `TurnCollector`
   ids rendered through `IntentionResource` (same shape the UI already renders).
5. `GET /dashboard` props include the recent conversation messages so the
   thread hydrates on load — chat survives reloads and devices.

### Cost guard & limits

- `GuardCoachUsage` middleware attaches to every agent (shared `middleware()`).
  Budget exceeded → `CoachQuotaException` → existing 429 rendering. Each call
  records a `coach_usages` row (existing table, unchanged schema) with the
  agent name as `purpose`.
- The `throttle:coach` route limiter is unchanged.
- An orchestrator turn with tool calls makes 2+ LLM calls; the meter counts
  each. `COACH_DAILY_TOKEN_BUDGET` may need raising once real usage is seen.

## Migration phases (each = one commit, suite green)

| Phase | Work | Deletes |
|---|---|---|
| P0 | Install `laravel/ai`; publish config + conversation migrations; build `GuardCoachUsage` + `TurnCollector`; spike that verifies fakes × middleware × tools interplay and the `usage` payload shape. | — |
| P1 | `Summarizer` agent; `UpdateRollingSummary` consumes it; tests move to `Summarizer::fake()`. | `RollingSummaryService`, `PatternSummarySchema` |
| P2 | `Strategist` agent; `ReviseStrategy` consumes it. | `StrategyReviser`, `StrategyRevisionSchema` |
| P3 | `Coach` orchestrator + `IntentionAuthor` sub-agent + `CreateLoop` + read tools + conversations. `RespondToChat`/`ChatController` rewritten; `ChatRequest` drops `history`; frontend `coach-client` sends `{message}` only and the thread hydrates from dashboard props. | `ChatCoach`, `ChatReplySchema`, `IntentionAuthor` service, `IntentionSchema` |
| P4 | Teardown + port `coach:ping` to an SDK smoke command; docs. | `CoachService` contract, `CoachManager`, `AnthropicCoachService`, `GuardedCoachService`, `FakeCoachService`, `CoachRequest/Response/Message/Role`, `CoachPrompts` |

Prompt content from `CoachPrompts` moves into each agent's `instructions()`,
keeping a `PROMPT_VERSION` class constant recorded in authored metadata (the
prompt-versioning behaviour the suite already locks).

## Testing

- Per-agent SDK fakes (`Coach::fake()`, `Summarizer::fake(…)`) replace
  `FakeCoachService`; existing feature tests keep their assertions and swap
  their fakes.
- Tools are unit-tested directly (e.g. `CreateLoop` persists through
  `AuthorIntention` and registers with the collector).
- If SDK fakes bypass the middleware pipeline (verified in the P0 spike),
  `GuardCoachUsage` gets isolated tests invoking `handle()` with a stub `$next`.
- TDD red→green per phase; Pint/ESLint/Prettier/tsc as established.

## Risks

- **SDK maturity** (released Feb 2026): the P0 spike de-risks fakes,
  middleware ordering, and sub-agent-as-tool semantics before any capability
  migrates.
- **`$response->usage` shape** may differ per provider; the middleware adapts
  behind one small mapper.
- **Conversations migration** must run in production (Forge deploy script
  already runs `migrate --force`).
- **Frontend contract change** (P3) touches `coach-client.ts` and thread
  seeding; covered by the existing Vitest suite plus new hydration tests.

## Out of scope (deferred)

- Write tools in chat (log outcomes, revise strategies conversationally).
- Embeddings / similarity search.
- Streaming responses to the UI.
- Exposing Strategist/Summarizer as chat tools.
