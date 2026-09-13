# The MCP surface

The MCP server is Claude's only way to author or read a loop. Twenty tools and two prompts, mounted at
`/mcp`, behind Passport.

This is the current-state reference **for a developer**: what each tool does, which Action class it
routes through, and which table that writes. The server's own `#[Instructions]` block documents the
same surface **for the coach** — how to hold a check-in, what to ask before logging a failure — which
is a different document with a different audience. Both have to stay true; neither is a substitute for
the other.

The frozen design records are `docs/superpowers/specs/2026-07-06-mcp-server-design.md`,
`2026-08-24-mcp-create-loop-design.md` and `2026-08-27-mcp-prompts-design.md`. **Where this file and a
spec disagree, this file is right.**

Read `docs/NOTEBOOK.md` for the chain these tools write to.

**This file is not on `CompanionVocabularyTest::sourceFiles()` and must not be added.** It names what
`loop-progress` returns and what the copy rules forbid, which cannot be written without quoting the
banned words. `docs/BLOB.md` carries the same exemption for the same reason.

---

## 1. The one sentence

**The coach reasons; the server records.** Every tool either reads the record or persists something the
coach and the user agreed on in words. No tool computes advice, and none calls a model.

## 2. The mount

```php
// routes/ai.php
Route::middleware('throttle:30,1')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp', PatYourSelfServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'throttle:120,1']);
```

| Piece | Why |
| --- | --- |
| `Mcp::oauthRoutes()` | OAuth 2.1 discovery + **dynamic client registration**. This is what lets claude.ai register itself and walk the user through authorization — there is no client id to create by hand |
| `throttle:30,1` on the OAuth group | This group is the app's only **unauthenticated surface that writes**: `POST oauth/register` mints an OAuth client row for anyone who asks. 30/min clears a full reconnect dance (which a stale connector needs) and stops a script |
| `auth:api` + `mcp:use` scope | Passport. A token without the scope gets a 401 |
| `throttle:120,1` on `/mcp` | Keyed per **user**, because `throttle` keys on the authenticated user when there is one, so one noisy client cannot spend another's budget. Two per second sustained: a conversation calls several tools in a burst then sits idle, so the limit bounds a loop, not a session. It exists because ten of these tools write; the original design listed rate limiting as out of scope when the server was five read-only tools |

**Passport keys are required and gitignored.** A fresh deploy without `php artisan passport:keys` fails
every MCP request with `Invalid key supplied`. Do not regenerate them — new keys invalidate every issued
token and disconnect the connector.

## 3. Pagination — the trap that hid a tool

```php
public int $defaultPaginationLength = 50;
```

Laravel MCP defaults `tools/list` to **15 per page**, and this server passed 15 when `write-reflection`
was added — so the sixteenth tool landed on page two and was invisible to any client that does not
follow `nextCursor`. **The failure is silent**: the tool exists, the server is healthy, and the coach
simply never learns it can call it.

50 is the framework's `maxPaginationLength`, so this is the widest single page available. Passing it
means splitting the server or paginating deliberately, not discovering it through a missing tool.

## 4. The twenty tools

Reading tools write nothing. Writing tools route through exactly one class in `app/Actions/`.

### Reading

| Tool | Routes through | Reads |
| --- | --- | --- |
| `list-loops` | — | `intentions` |
| `get-loop` | — | `intentions`, `strategies`, `actions`, `action_exercises`, `action_logs`, `notes` |
| `today-actions` | `Scheduling\TodaysOccasions` | `occurrences` (+ materialises lazily) |
| `pending-outcomes` | `Scheduling\MaterialiseOccurrences` | unlogged `occurrences` |
| `loop-outcomes` | — | `action_logs` |
| `loop-progress` | `Progress\LoopProgress` | `action_logs`, `strategies` |
| `search-exercises` | — | `exercises`, scoped by `availableTo()` |

`get-loop` is the id source for everything else: it reads back every action with its id **and its
routine**, which is where the ids for both an action and a routine row come from.

### Writing

| Tool | Routes through | Writes |
| --- | --- | --- |
| `create-loop` | `PersistAuthoredIntention` ← `Authoring\AuthoredIntention` | `intentions`, `strategies`, `actions` |
| `update-loop` | `UpdateIntention` | `intentions` |
| `log-outcome` | `LogAction` | `action_logs` |
| `start-experiment` | `StartExperiment` | `strategies` (supersede + create), `actions` |
| `conclude-experiment` | `ConcludeExperiment` | `strategies.verdict`, `verdict_note` |
| `add-action` | `CreateAction` + `MaterialiseOccurrences` | `actions`, `occurrences` |
| `update-action` | `RescheduleAction` + `MaterialiseOccurrences` | `actions`, `occurrences` |
| `remove-action` | `ArchiveAction` | `actions.status` — **archives, never deletes** |
| `log-note` | `LogNote` | `notes` |
| `write-reflection` | `WriteReflection` | `summaries` |
| `write-blob-remark` | `WriteBlobRemark` | `companion_remarks` |
| `add-routine-exercise` | `Training\AddRoutineExercise` | `action_exercises` |
| `remove-routine-exercise` | `Training\RemoveRoutineExercise` | `action_exercises` |

That is thirteen writing tools against the ten the rate-limit docblock names; the gym three arrived
after it.

`log-outcome` and `conclude-experiment` also read `Companion\CompanionAnnouncement`, so the coach can
relay what Blob just gained. They do not write to the companion — there is no companion table.

### The contracts a caller must honour

- **`log-outcome` with `failed` must carry the user's reason**, and it is stored verbatim — never
  trimmed, squished or sentence-cased. Those reasons are what the next experiment is written from.
- **`skipped` means the occasion never happened.** If it happened and the strategy did not hold —
  including not thinking about it — that is `failed`.
- **`conclude-experiment` does not supersede.** A version concluded as `worked` stays active and keeps
  running. Only `start-experiment` supersedes.
- **`start-experiment`'s `revisedAction` is pass-vs-omit, not optional-vs-required.** Omitting it is
  not "no action" — an action is always created; omitting inherits the prior action's schedule verbatim
  and only retitles.
- **`remove-action` archives** and keeps every occasion and outcome already recorded.
- **`write-reflection` supplies the words only.** The window it covers and the number of occasions
  inside it are derived server-side, because a narrative that could name its own window could claim to
  have read evidence it never looked at.
- **`write-blob-remark` describes Blob or the work, never how well the user is doing.** A remark on a
  loop that later pauses stops being shown.

## 5. The two prompts

| Prompt | Sequence |
| --- | --- |
| `daily-check-in` | `pending-outcomes` → `log-outcome` per occasion → `loop-outcomes` → `loop-progress` / `get-loop` |
| `review-experiment` | find what has reached its review date → `conclude-experiment` → `write-reflection`, leaving the next experiment to the owner |

**Both carry the sequence, not the record.** No database access, no embedded payload — a payload would
be a second place that has to stay honest about "not overdue", and stale the moment it was fetched.

**Neither overrides `arguments()`,** deliberately. In a client an argument renders as a form field the
user must fill before the prompt fires. `daily-check-in` fires on a click; `review-experiment` would
have to name a loop before the coach has told you anything, which forecloses the "what is ready?"
opening that is the point of the prompt.

**`review-experiment`'s step order is load-bearing**: the verdict precedes the reflection, because the
reflection synthesises what the record *now* shows and the record does not carry the verdict until
`conclude-experiment` has run.

## 6. `create-loop` cannot set a workflow

`update-loop` can; `create-loop` cannot. The reason is structural, not an oversight of validation:
`create-loop` routes through `Authoring\AuthoredIntention`, and that DTO has no `workflow` property.
Adding one touches the DTO, `fromStructured()`, `PersistAuthoredIntention` and the tool's schema.

So a training loop is authored in two steps: `create-loop`, then `update-loop` with
`workflow: "gym"`. `update-loop` builds its enum from `config('workflows.registry')` at call time, so a
newly registered workflow becomes settable without touching the tool.

One detail in `update-loop` worth knowing: `""` **clears** the workflow and absent **leaves it alone**.
Null is not accepted for exactly that reason — the two cases have to be distinguishable.

## 7. The connector goes stale, and it is silent

**claude.ai caches the tool list at connection time.** Add or rename a tool and an already-connected
client keeps serving the old surface until the connector is removed and re-added.

How to tell: a connector advertising fewer tools than `PatYourSelfServer::$tools` registers is stale,
not broken. Old **names** are the stronger signal — a connector still offering `log-action-outcome`
predates the rename to `log-outcome`.

This compounds with deploy lag, and the two look identical from the client: a tool merged to `main` but
not deployed is also simply absent. Check `docs/DEPLOY-FORGE.md` §14 for what is actually on production
before concluding the connector needs reconnecting.

## 8. Rulings that must not be reopened

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **No model provider in this codebase** | The coach is the client. `tests/Feature/NoLlmTest.php` keeps it out | The zero-LLM premise, and the whole "app is the record" claim |
| **`defaultPaginationLength = 50`** | The framework's default of 15 silently hid the 16th tool | A registered tool the coach never learns exists |
| **The OAuth group is throttled separately, per IP** | `POST oauth/register` is unauthenticated and writes | Anonymous client-row minting |
| **`/mcp` is throttled per user, not per IP** | One noisy client must not spend another's budget | Shared exhaustion |
| **A failure carries the user's words verbatim** | Those reasons are the next experiment's raw material | Paraphrase replacing evidence |
| **Prompts hold no record** | A payload is a second place that has to stay honest, stale on fetch | Two sources of "what is due", disagreeing |
| **Prompts take no arguments** | An argument is a form field filled before the coach has said anything | The opening the prompt exists to create |
| **`remove-action` archives** | Occasions and outcomes already recorded must survive | Deleted history |
| **`write-reflection` derives its own window** | A narrative that names its own window can claim evidence it never read | An unfalsifiable summary |
| **Route names are prefixed `api.`** | `route:cache` fails on a name collision, which would fail the deploy | A deploy that dies in `optimize`; guarded by `DeploymentReadinessTest` |

## 9. Copy rules the coach inherits

The `#[Instructions]` block is the coach's brief, and it carries the app's copy rules because the coach
writes into the record:

- A failure is about the **strategy**, never the user. Not discipline, willpower or motivation, and
  never a numeric target.
- Sentence case, no exclamation marks, never congratulating, no second person keeping score.
- `write-blob-remark`: not after every check-in. A line every time is wallpaper within a week.

Note the asymmetry: `loop-progress` legitimately **returns** a streak and a completion rate — the
notebook keeps statistics — while the companion's surfaces may never name one. `docs/NOTEBOOK.md` §9
has the boundary and why the vocabulary test is a file list rather than a global grep.

## 10. Where everything lives

```
routes/ai.php                             the mount, the two throttles, OAuth routes
app/Mcp/Servers/PatYourSelfServer.php     #[Instructions] (the coach's brief), $tools, $prompts,
                                          $defaultPaginationLength
app/Mcp/Tools/                            20 tools, one per file, #[Name('kebab-case')]
app/Mcp/Prompts/                          DailyCheckInPrompt, ReviewExperimentPrompt

tests/Feature/Mcp/                        one test per tool, plus McpEndpointTest (auth, scope,
                                          throttle) and McpConfigTest (registration, pagination)
tests/Feature/NoLlmTest.php               keeps the model provider out
```

## 11. What is not done

- **The gym tools are merged and pushed but not yet deployed** — `search-exercises`,
  `add-routine-exercise`, `remove-routine-exercise`. See `docs/DEPLOY-FORGE.md` §14.
- **`create-loop` cannot set a workflow.** §6.
- **No tool reorders a routine.** `ReorderRoutine` is screen-only.
- **No tool reads the export.** `RecordExport` is a web route; the coach cannot fetch the whole record.
- **Nothing surfaces `summaries` back to the coach except through `get-loop`'s loop payload**, so a
  reflection cannot be read back on its own before being replaced.
- **No tool writes a user-scoped summary,** though `summaries.scope` supports one.
- **No tool amends a routine row.** The connector can add and remove routine
  rows but not change one's targets; the app can, through
  `actions.exercises.update`. The mirror of the gap `create-loop` has with
  `workflow`, and open for the same reason — nobody has needed it yet.
