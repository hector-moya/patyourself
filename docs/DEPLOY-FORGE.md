# Deploying PatYourSelf (Phase 1) to Laravel Forge

This is the runbook for the web MVP — a Laravel 12 + Inertia (React) app with a
database-backed queue. Forge handles provisioning (server, nginx, PHP-FPM,
MySQL, SSL, daemons); this doc covers the app-specific configuration and the
post-deploy verification.

## 0. Prerequisites

- A Forge account connected to a server provider (Hetzner / DigitalOcean / etc.)
  and to the GitHub repo for this project.
- A domain you control, with DNS able to point an A record at the server.
- The Anthropic API key for the coach.

## 1. Provision the server (Forge)

1. **Create Server** → pick the provider and the smallest "App Server" that fits
   (1–2 GB RAM is enough for the MVP). Choose the current **PHP 8.4** and
   **MySQL 8**.
2. Forge installs nginx, PHP-FPM, MySQL, Redis (unused here), and a `forge` user.
3. Note the server IP and point your domain's **A record** at it.

## 2. Create the site

1. **Sites → New Site**: root domain, project type **General PHP / Laravel**,
   web directory **`/public`**.
2. **Git Repository**: connect this repo, branch `main` (or your release branch).
   Do **not** enable "install composer dependencies" yet — the deploy script
   below does it with production flags.
3. Create the site's **database** (Forge → Database) and note the name/user/pass.

## 3. Environment

Open the site's **Environment** tab and paste the contents of
[`.env.production.example`](../.env.production.example), then fill in:

- `APP_URL` — your https domain.
- `APP_KEY` — Forge generates one on first deploy; if blank, run
  `php artisan key:generate` from the site's **Commands** tab.
- `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` — the database from step 2.
- `ANTHROPIC_API_KEY` — the Anthropic API key for all LLM agents.
- `COACH_DAILY_TOKEN_BUDGET` — rolling 24h per-user token cap (default 200000; 0 disables).
- `COACH_RATE_PER_MINUTE` — chat requests per user per minute (default 20; 0 disables).
- `MAIL_*` — your transactional mail provider (verification emails).

Note: the model is configured per-agent via `#[Model]` attributes in code; no
`ANTHROPIC_MODEL` env var is needed. Provider credentials are read by
`config/ai.php` (the `laravel/ai` package).

`SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` are all `database`; the
required tables ship in the default migrations, so there is nothing else to
stand up.

## 4. Deploy script

Replace the site's **Deploy Script** with:

```bash
cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Build the front-end (Inertia/React via Vite). Wayfinder route helpers and the
# Vite manifest are generated here — the app 500s without the manifest.
npm ci
npm run build

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link

# Cache config, routes, views and events for production. route:cache is why the
# API route names are prefixed `api.` — a name collision fails this command.
$FORGE_PHP artisan optimize

# Pick up new code in the running queue worker.
$FORGE_PHP artisan queue:restart
```

Enable **Quick Deploy** so pushes to the branch deploy automatically.

## 5. Queue worker

The app uses the database queue. In the site's **Queue** tab, add a worker:

- **Connection**: `database`
- **Queue**: `default`
- **Processes**: 1 (raise later if needed)
- **Timeout**: 60, **Sleep**: 3, **Tries**: 3
- Equivalent command: `php artisan queue:work --tries=3 --timeout=60`

Forge supervises it and restarts it on each deploy (via `queue:restart`).

## 6. Scheduler

In the server's **Scheduler** tab, add the Laravel scheduler for this site:

- Command: `php artisan schedule:run`
- Frequency: every minute.

(No scheduled jobs ship in Phase 1; this readies the cron for future rolling
summaries / digests.)

## 7. SSL

Site → **SSL → Let's Encrypt** → obtain a certificate for the domain. Forge
renews it automatically.

## 8. First deploy

Click **Deploy Now**. On a fresh server the first run also needs (Commands tab):

```bash
php artisan key:generate   # only if APP_KEY was left blank
php artisan migrate --force
```

## 9. Post-deploy verification (web MVP end to end)

Smoke-test against the live domain:

- [ ] `https://your-domain.com` loads the landing page over HTTPS.
- [ ] Register a user → email verification flow works.
- [ ] Log in → lands on the chat home (`/dashboard`).
- [ ] Send a chat message → the coach replies (confirms `ANTHROPIC_API_KEY` +
      outbound HTTPS). An authored loop renders as an action card.
- [ ] Quick-log an action (Done / Missed-with-reason / Skip) → no error; the
      status advances.
- [ ] Loops list (`/intentions`) and loop detail render anatomy + strategy
      timeline.
- [ ] API: `POST /api/auth/token` issues a token; `GET /api/intentions` with it
      returns the user's loops.
- [ ] Cost guard: usage rows land in `coach_usages`; hammering `/chat` past
      `COACH_RATE_PER_MINUTE` returns HTTP 429.
- [ ] `storage/logs/laravel.log` is clean of errors after the run.

## Notes

- **`route:cache`** is verified in CI by `tests/Feature/DeploymentReadinessTest`
  so a future duplicate route name fails the test suite, not the deploy.
- **Secrets** live only in Forge's Environment tab. Never commit a filled `.env`.
- **Scaling**: move sessions/cache/queue to Redis and add a second queue worker
  when traffic warrants; nothing in the app code needs to change.
