# PatYourSelf MCP Server — Design

**Date:** 2026-07-06
**Status:** Approved
**Goal:** Let claude.ai (web/desktop/mobile chat) connect to the PatYourSelf app on the
Laravel Forge VPS as a custom connector, so a user can review their habit loops and log
action outcomes by talking to Claude.

## Decisions made during brainstorming

- **Client:** claude.ai custom connector (not Claude Code). Connectors support only
  OAuth 2.1 — no custom headers — so Sanctum bearer auth alone cannot satisfy this.
- **Tool scope:** read + log. Claude can read loops, strategies, schedules, and progress,
  and can log action outcomes. No create/edit/delete of intentions from chat.
- **Approach:** build in-app with `laravel/mcp` + `laravel/passport` (the documented
  Laravel path), rather than Sanctum-first or an external proxy MCP.

## Architecture

### New dependencies (approved)

- `laravel/mcp` — currently only a transitive dev dependency via `laravel/boost`; must be
  required directly for production use.
- `laravel/passport` — OAuth 2.1 authorization server. Coexists with Sanctum: Passport
  takes (or defines) the `api` guard; the existing `auth:sanctum` mobile API routes in
  `routes/api.php` are untouched, as is Fortify web auth.

### Registration

```php
// routes/ai.php (published via vendor:publish --tag=ai-routes)
use App\Mcp\Servers\PatYourSelfServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', PatYourSelfServer::class)->middleware('auth:api');
```

- `Mcp::oauthRoutes()` publishes OAuth 2.1 discovery metadata and dynamic client
  registration (RFC 7591) — claude.ai registers itself; no manual client IDs.
- Single `mcp:use` scope (the laravel/mcp default). No custom scopes.
- `PatYourSelfServer` lives in `app/Mcp/Servers/`, carries `#[Name]`, `#[Version]`, and
  `#[Instructions]` describing the habit-loop domain (cue → craving → response → reward,
  versioned strategies) so Claude uses the tools correctly.

### Auth flow

1. User adds `https://<domain>/mcp` as a custom connector in claude.ai.
2. claude.ai discovers the OAuth metadata, dynamically registers a client, and redirects
   the user to the app's Passport authorization screen (published `mcp-views` Blade view,
   registered via `Passport::authorizationView()` in `AppServiceProvider::boot()`).
3. User authenticates with their normal session (Fortify) and approves.
4. Every MCP request then runs as that user via the `auth:api` Passport guard.

Auth is per-user; nothing is hardcoded to a single account.

## Tools

All tools resolve models through the authenticated user's own relations
(`$request->user()->intentions()...`), making cross-user access structurally impossible;
existing policies apply on top where defined. Tools are thin: validate args, authorize,
call an existing Action/service, return structured JSON via `Response`.

| Tool | Args | Wraps | Returns |
|---|---|---|---|
| `list-loops` | `status?` (default: active) | Eloquent query (mirrors `Api\IntentionController@index`) | id, title, type, status, active strategy version per loop |
| `get-loop` | `intention_id` | Intention detail + strategy timeline (mirrors `Api\StrategyController@index`) | full cue→craving→response→reward chain, versioned strategy history with failure reasons |
| `today-actions` | — | `Services\Scheduling\Schedule` read path | open actions due today or overdue (in the user's timezone): id, loop title, description, due time, recurrence |
| `log-action-outcome` | `action_id`, `outcome` (`completed`\|`failed`\|`skipped`), `reason?` | `App\Actions\LogAction` | created log + the action's new status |
| `loop-progress` | `intention_id` | `Services\Progress\LoopProgress::forLoop()` | streak, completion rate, totals, recent outcome strip |

Constraints:

- `log-action-outcome` is the **only write**, and it goes through `LogAction`, so all
  invariants hold: immutable log, recurring-action roll-forward, cue-answered
  notification marking, `ActionLogged` event dispatch.
- `reason` is required when `outcome` is `failed` (feeds versioned-strategy revision).
- **No LLM calls inside any tool.** Claude is the intelligence on the client side; the
  server only serves data. `RespondToChat` / coach chat is deliberately excluded.

## Error handling

- Bad arguments → tool schema validation errors (claude.ai surfaces them; Claude
  self-corrects or asks the user).
- `failed` without `reason` → validation error instructing Claude to ask the user why.
- Unknown or other-user `intention_id`/`action_id` → uniform `Response::error('Not
  found.')`; no existence leak.
- Expired/invalid tokens → Passport 401 + `WWW-Authenticate`; claude.ai re-runs OAuth.

## Testing (PHPUnit)

- Per-tool feature tests via `PatYourSelfServer::actingAs($user)->tool(ToolClass::class,
  [...])`: happy path, validation failures, cross-user denial for every tool, and
  recurring-action roll-forward through the MCP path.
- OAuth smoke test: discovery endpoints return 200 with metadata.
- Manual verification: `php artisan mcp:inspector mcp` locally; real claude.ai connector
  against the deployed URL (claude.ai cannot reach `patyourself.test`, so the end-to-end
  OAuth flow is verifiable only after deploy — ties into ClickUp task 25).

## Forge deployment notes

- Run Passport migrations; generate keys (`passport:keys` or env vars).
- Publish the `mcp-views` authorization view; register it in `AppServiceProvider`.
- Configure the `api` auth guard for Passport in `config/auth.php`.
- Route caching remains safe (all routes named).
- HTTPS already provided by Forge; claude.ai requires it.

## Out of scope (YAGNI)

- OAuth scopes beyond the default `mcp:use`.
- Coach-chat tool (LLM-inside-LLM; metered cost).
- MCP resources/prompts/apps primitives — tools only for now.
- Phase 2 (mobile) concerns.
- Rate limiting beyond Passport defaults.
