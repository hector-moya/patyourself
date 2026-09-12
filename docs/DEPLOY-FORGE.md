# Deploying PatYourSelf to Laravel Forge

The runbook for the live app: **Laravel 13 + Inertia (React)** on PHP 8.4, with a
database-backed queue and an **MCP server** that claude.ai connects to as a
custom connector. Forge handles provisioning (server, nginx, PHP-FPM, MySQL,
SSL, daemons); this doc covers the app-specific configuration and the
post-deploy verification.

**There is no LLM in this app.** It went zero-LLM in the Lab Notebook pivot: no
model credentials, no token budgets, no chat endpoint. The conversation happens
in claude.ai through the MCP server, and this app is the record. If you find an
`ANTHROPIC_API_KEY` or a `COACH_*` variable in a Forge environment, it is a
leftover — delete it.

> **This runbook describes how to stand the app up. It is not a description of
> the live server.** Steps 1 and 2 below say to size a box for the asset build;
> the live box is smaller than that and is shared with four other applications.
> Read [§0.1](#01-the-live-server-as-it-actually-is) before believing anything in
> §1–§2 about capacity, and [§14](#14-what-has-actually-been-run) before
> believing that any one-off step here has been run.

## 0. Prerequisites

- A Forge account connected to a server provider (Hetzner / DigitalOcean / etc.)
  and to the GitHub repo for this project.
- A domain you control, with DNS able to point an A record at the server.
- AWS SES credentials, if reminder emails are to actually deliver (step 3).

## 0.1 The live server, as it actually is

Recorded 2026-09-12. This is one box, not a box per app.

| | |
|---|---|
| Hostname | `bookwise` |
| CPU | **1 vCPU** |
| RAM | **961 MiB** |
| Swap | **1 GiB** |
| Sites | **five** — `patyourself`, `booking`, `dm-forge`, `hector-portfolio`, `nvaplay` |
| Resident daemons | a Reverb websocket server, a Nightwatch agent, and **three** queue workers |

The Reverb server belongs to another site — PatYourSelf has no broadcasting, no
Echo and no Pusher. It matters here only because it is resident in that 961 MiB.

**Deploys fail on this box, in `npm ci`.** This is the normal state, not an
incident.

**Adding swap did not fix it; it changed the shape of the failure.** Before swap,
the OOM killer took `npm ci` out fast and the deploy exited 137. With swap, the
same build thrashes instead and the deploy runs past **ten minutes** and times
out. A slow timeout is harder to read than a fast kill, and both mean the same
thing: 961 MiB shared five ways cannot build this front end.

**Deploys can also fill the disk.** A second, distinct failure surfaces as
`errno=28` (no space left) partway through `composer install`, usually on
`aws-sdk-php`. Unlike the memory failure this one **compounds**: each failed
deploy orphans a partial release directory, so the next attempt starts with less
room than the last. Check free space before retrying, not after.

### The deploy script is off limits

> **Ruled out 2026-09-12 by the owner.** The deploy script in §6 is not to be
> changed, and no change to it is to be proposed. The `npm ci` failure is a
> known, accepted constraint of this server.

That ruling is recorded here rather than argued with. It is why §6 stands as
written and why the Troubleshooting entry on building assets in CI is marked
ruled out rather than deleted — the diagnosis there is still correct; the
constraint is a decision, not a correction.

The practical consequence for anyone deploying: **retry the deploy.** It often
clears, and there is nothing else to reach for.

## 1. Provision the server

1. **Create Server** → pick the provider and an "App Server". Choose the current
   **PHP 8.4** and **MySQL 8**.
2. **Size it for the asset build, not for the traffic.** This app serves one
   person, but `npm ci` + `vite build` is the memory peak and it is the thing
   that fails. A 1 GB box will be killed mid-deploy; 2 GB plus swap (step 2) is
   the working configuration. See [Troubleshooting](#troubleshooting) — a deploy
   ending in `Killed npm ci` is this and nothing else.

   > **The live box does not meet this.** It is 961 MiB, one vCPU, five sites.
   > Its deploys fail in `npm ci`, exactly as this step predicts, and that is
   > accepted rather than fixed — [§0.1](#01-the-live-server-as-it-actually-is).
   > Keep this sizing advice for standing up a *new* server; do not read it as a
   > description of the current one.
3. Forge installs nginx, PHP-FPM, MySQL, Redis (unused here), and a `forge` user.
4. Note the server IP and point your domain's **A record** at it.

## 2. Add swap — do this before the first deploy

The OOM killer taking out `npm ci` is the single most common deploy failure on
this project. Swap converts it from a dead deploy into a slow one, which is the
right trade on a build step.

> **On the live box this trade has already been made and it was not enough.**
> 1 GiB of swap turned a fast exit-137 into a deploy that runs past ten minutes
> and times out. Swap is still the right first move on a new server; it is not a
> fix for a box this size. [§0.1](#01-the-live-server-as-it-actually-is).

SSH in as `forge` and check first:

```bash
free -h && swapon --show      # empty output from swapon means no swap
```

If there is none:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab   # survives reboot
free -h                        # should now show 2G of swap
```

## 3. Create the site

1. **Sites → New Site**: root domain, project type **General PHP / Laravel**,
   web directory **`/public`**.
2. **Git Repository**: connect this repo, branch `main`. Do **not** enable
   "install composer dependencies" — the deploy script below does it with
   production flags.
3. Create the site's **database** (Forge → Database) and note the name/user/pass.

## 4. Environment

Open the site's **Environment** tab and paste the contents of
[`.env.production.example`](../.env.production.example), then fill in:

- `APP_URL` — your https domain.
- `APP_KEY` — Forge generates one on first deploy; if blank, run
  `php artisan key:generate` from the site's **Commands** tab.
- `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` — the database from step 3.
- `MAIL_*` — set `MAIL_MAILER=ses` and fill `AWS_ACCESS_KEY_ID` /
  `AWS_SECRET_ACCESS_KEY` (IAM permission `ses:SendRawEmail`) for verification
  and reminder emails to actually deliver. `MAIL_FROM_ADDRESS` must be an
  SES-verified identity — SES rejects anything else.

With `MAIL_MAILER=log` (the template's default) the app reports success while
every email is written to `storage/logs/laravel.log` and nobody receives it.
That is a safe default for a first deploy and a silent failure if you leave it.

`SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` are all `database`; the
required tables ship in the default migrations, so there is nothing else to
stand up.

## 5. Passport keys — required, and not in the repo

The MCP server authenticates with Laravel Passport, and Passport needs a
signing keypair at `storage/oauth-private.key` / `storage/oauth-public.key`.
Those are **gitignored** (`/storage/*.key`), so a fresh deploy does not have
them and every MCP request fails with `Invalid key supplied`.

Once, from the site's **Commands** tab:

```bash
php artisan passport:keys
```

Two things to know:

- **Do not regenerate them.** New keys invalidate every issued token, which
  disconnects the claude.ai connector and forces the whole authorization dance
  again.
- **They must survive deploys.** Forge deploys in place by default, so
  `storage/` persists. If you ever move to zero-downtime/atomic releases, the
  keys have to be in the shared path or they vanish on the next deploy.

## 6. Deploy script

Replace the site's **Deploy Script** with:

```bash
cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Build the front-end (Inertia/React via Vite). Wayfinder route helpers and the
# Vite manifest are generated here — the app 500s without the manifest.
# --no-audit skips a network round-trip that buys nothing on a deploy.
npm ci --no-audit --no-fund
npm run build

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link

# Cache config, routes, views and events for production. route:cache is why the
# API route names are prefixed `api.` — a name collision fails this command.
$FORGE_PHP artisan optimize

# Pick up new code in the running queue worker.
$FORGE_PHP artisan queue:restart
```

Enable **Quick Deploy** so pushes to `main` deploy automatically.

Note there is deliberately **no seeder** in this script — see step 7.

## 7. Seed the exercise catalogue

The gym module ships a catalogue of 876 public-domain exercises as committed
JSON. It is loaded by a seeder, never fetched at runtime.

Run once from the **Commands** tab, after the first deploy that includes the
gym migrations:

```bash
php artisan db:seed --class=ExerciseCatalogueSeeder
```

It is **idempotent** — keyed on `external_id`, so re-running updates rather than
duplicates, and it leaves your own added exercises alone. Safe to repeat any
time the catalogue file changes.

It is kept out of the deploy script on purpose: `DatabaseSeeder` also creates a
`Test User`, so the generic `db:seed` must never run in production, and adding
work to a deploy that already fights for memory is the wrong direction.

## 8. Queue worker

The app uses the database queue. In the site's **Queue** tab, add a worker:

- **Connection**: `database`
- **Queue**: `default`
- **Processes**: 1
- **Timeout**: 60, **Sleep**: 3, **Tries**: 3
- Equivalent command: `php artisan queue:work --tries=3 --timeout=60`

Forge supervises it and restarts it on each deploy (via `queue:restart`).

Reminder emails are `ShouldQueue`. Without a running worker they pile up in the
`jobs` table and never deliver.

## 9. Scheduler

**Required** — skipping this tab silently disables both action cues and email
reminders. Nothing errors; they simply never fire.

In the server's **Scheduler** tab, add the Laravel scheduler for this site:

- Command: `php artisan schedule:run`
- Frequency: every minute.

Three commands ship on this schedule (`routes/console.php`):

- `FireDueActions` — `everyMinute()`. Fires any action whose scheduled moment
  has arrived (drives the in-app cue and the per-cue email).
- `SendReminderDigests` — `everyMinute()`. Sends the daily digest to any user
  whose local `digest_time` has arrived.
- `AlertFailedJobs` — `hourly()`. Mails the owner when a background job has
  failed since the last check — the alarm that tells you the other two are
  broken. It only means anything with a mailer that actually delivers; with the
  `log` driver it fires into the log file instead of an inbox.

## 10. SSL

Site → **SSL → Let's Encrypt** → obtain a certificate for the domain. Forge
renews it automatically. The MCP connector requires HTTPS.

## 11. First deploy

Click **Deploy Now**. On a fresh server the first run also needs, from the
Commands tab:

```bash
php artisan key:generate    # only if APP_KEY was left blank
php artisan migrate --force
php artisan passport:keys   # step 5
php artisan db:seed --class=ExerciseCatalogueSeeder   # step 7
```

## 12. Connect claude.ai

The MCP server is mounted at **`/mcp`** (`routes/ai.php`), behind `auth:api`
and a `mcp:use` token scope. `Mcp::oauthRoutes()` publishes OAuth 2.1 discovery
and dynamic client registration, which is what lets claude.ai register itself
and walk you through authorization — there is no client ID to create by hand.

In claude.ai → **Settings → Connectors → Add custom connector**, give it
`https://your-domain.com/mcp` and complete the authorization prompt.

**claude.ai caches the tool list at connection time.** If you add or rename a
tool, an already-connected client keeps serving the old surface until you
remove and re-add the connector. A connector showing fewer tools than
`PatYourSelfServer::$tools` registers is stale, not broken — reconnect it.

## 13. Post-deploy verification

Smoke-test against the live domain:

- [ ] `https://your-domain.com` loads the landing page over HTTPS.
- [ ] Register a user → the verification email arrives (proves SES, not just
      that the app thinks it sent something).
- [ ] Log in → the notebook dashboard renders, with Blob.
- [ ] Create a loop, add an action, and quick-log it (Done / Missed-with-reason
      / Skip) → no error and the status advances.
- [ ] Loops list and loop detail render the anatomy and the strategy timeline.
- [ ] `/catch-up` lists any unlogged past occasions.
- [ ] API: `POST /api/auth/token` issues a token; `GET /api/intentions` with it
      returns the user's loops.
- [ ] MCP: the claude.ai connector lists the full tool set and `today-actions`
      returns real data. A `401` means the token scope; `Invalid key supplied`
      means step 5 was skipped.
- [ ] Gym: `SELECT COUNT(*) FROM exercises` returns **876** (step 7).
- [ ] PWA: `public/build/sw.js` exists and the app offers to install on a phone
      (step 6 generates it; `npm run build` failing means no worker at all).
- [ ] `storage/logs/laravel.log` is clean of errors after the run.

## 14. What has actually been run

**This section exists because a correct doc is not evidence.** Step 7 was written
correctly and simply never executed on production, so every exercise search
returned nothing for weeks. Nothing errored, nothing was stale, and no amount of
re-reading the runbook would have caught it — reality had drifted from a document
that was right.

So: one row per claim, the command that settles it, and when it was last actually
checked. **A blank "last verified" is not a pass.** Update the date when you run
the check, not when you read the row.

Recorded 2026-09-12. Verified from the repository and from the live connector's
advertised surface; rows needing shell or database access on the box are marked
unverified rather than assumed.

| Claim | How to check | Last verified | Result |
|---|---|---|---|
| Passport keys exist (§5) | Any MCP call succeeds rather than `Invalid key supplied` | 2026-09-12 | **Pass** — the connector authenticates |
| The exercise catalogue is seeded (§7) | `SELECT COUNT(*) FROM exercises` → 876 | — | **Unverified.** This is the row that cost weeks. It has no runtime symptom other than an empty search |
| Queue worker is running (§8) | Forge → Queue tab; `SELECT COUNT(*) FROM jobs` is not climbing | — | **Unverified** |
| Scheduler is running (§9) | Forge → Scheduler tab; a cue arrives at its due minute | — | **Unverified.** Skipping this silently disables both cues and digests |
| `MAIL_MAILER` is `ses`, not `log` (§4) | Register a throwaway user and confirm the mail arrives | — | **Unverified.** With `log` the app reports success and nobody receives anything |
| SES identity is verified (§4) | As above; SES rejects an unverified `MAIL_FROM_ADDRESS` | — | **Unverified** |
| The deployed MCP surface matches `main` | Compare the connector's tool list against `PatYourSelfServer::$tools` | 2026-09-12 | **Fail — behind `main`.** See below |
| The gym MCP tools are live | `search-exercises` is advertised | 2026-09-12 | **Fail — merged and pushed, not deployed** |
| Phases B, C, D1/D2 verified in production | Mail arriving · one-click links on a phone · the PWA installing · Blob rendering | 2026-09-12 | **Never verified**, except D3 — Blob renders live |

### The deployed MCP surface is behind `main`

Observed 2026-09-12 from the connected claude.ai connector: it advertises **six**
tools where `PatYourSelfServer::$tools` registers **twenty**, and it advertises
them under an **older name** — `log-action-outcome`, which is now `log-outcome`.

That is two failures stacked, and they look identical from the client:

1. **Deploy lag** — tools merged to `main` that are not on the box.
2. **Connector cache** — claude.ai caches the tool list at connection time, so an
   already-connected client keeps serving the old surface regardless (§12).

The old *name* is what distinguishes them. A stale cache explains missing tools;
only an old deploy explains a renamed one still being offered. Fixing it needs
both: deploy, **then** remove and re-add the connector.

Until then, treat the connector's surface as historical. It is not evidence of
what the server registers.

## Troubleshooting

**`Killed npm ci` / `Killed npm install`, deploy exits 137.**
The kernel's OOM killer, not a bad commit — and it says nothing about the code
you just pushed. Confirm by checking whether the push actually touched
`package.json` / `package-lock.json`; usually it did not. Retry the deploy
first, since it often clears. If both `npm ci` and any fallback are killed, the
box is genuinely out of memory: add swap (step 2).

The permanent fix is to stop building on the box at all — build the assets in
CI and deploy `public/build` as an artifact. Worth doing before the front-end
grows much further.

> **Ruled out 2026-09-12 by the owner: the deploy script is off limits.** The
> paragraph above is kept because the diagnosis is still correct — the
> constraint is a decision, not a correction. Do not propose it, and do not edit
> §6. Retry the deploy instead. [§0.1](#01-the-live-server-as-it-actually-is).

**The deploy runs past ten minutes and times out, with no `Killed`.**
The same memory exhaustion, on a box that has swap. Thrashing instead of dying.
It reads as a hang or a network stall and is neither. Retry.

**`errno=28` / "No space left on device", usually in `composer install` on
`aws-sdk-php`.**
The disk, not the memory — a distinct failure that **compounds**, because each
failed deploy orphans a partial release directory. Check free space (`df -h`)
before retrying; a retry on a full disk fails faster and leaves one more orphan.

**`Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest`.**
`npm run build` did not complete — almost always the OOM above. The manifest is
required; the app 500s without it.

**MCP requests fail with `Invalid key supplied`.**
Passport keys are missing. Step 5.

**Reminders never arrive and nothing is in the log.**
The scheduler (step 9) or the queue worker (step 8) is not running. Both are
required, and neither errors when absent.

## Notes

- **`route:cache`** is verified in CI by `tests/Feature/DeploymentReadinessTest`,
  so a future duplicate route name fails the test suite rather than the deploy.
- **Secrets** live only in Forge's Environment tab. Never commit a filled `.env`.
  Passport keys are gitignored and generated on the server (step 5).
- **The PWA ships with the assets.** `npm run build` (step 6) generates
  `public/build/sw.js` and the manifest via `vite-plugin-pwa`;
  `resources/js/app.tsx` registers the worker itself. There is no separate deploy
  step and no daemon. Two consequences: a failed build means no service worker at
  all, and the worker **must never precache a document** — a cached document
  serves a stale CSRF token and the user sees random 419s that look like being
  logged out. `tests/Feature/PwaManifestTest.php` guards that, and guards two
  build-config-only fixes (`injectRegister: null`, `globIgnores`) that have no
  other coverage. That test **skips itself** when `public/sw.js` is absent, so it
  proves nothing on a checkout that has not built.
- **No websockets.** PatYourSelf has no broadcasting, no Echo and no Pusher. The
  Reverb daemon on the box belongs to another site
  ([§0.1](#01-the-live-server-as-it-actually-is)).
- **Scaling**: move sessions/cache/queue to Redis and add a second queue worker
  if traffic ever warrants it; nothing in the app code needs to change. On the
  current box the binding constraint is the asset build, not traffic.
