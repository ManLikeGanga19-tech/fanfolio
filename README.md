# FanFolio

A web-only PWA where African creators sell subscriptions, pay-per-view posts, tips and paid messages to verified fans.

Start with `CLAUDE.md` (the build brief) and `docs/` (sources of truth: backend spec, screens, design system, security blueprint, product map, decisions, milestone plans).

## Run it

Requirements: Docker.

```sh
docker compose up -d
```

The first start installs dependencies, creates `.env`, generates the app key and runs migrations. The `assets` service rebuilds CSS and JS into `public/build` whenever a view or stylesheet changes.

| Service | URL |
|---|---|
| App (Octane on Swoole) | http://localhost:8088 |
| Mailpit | http://localhost:8025 |
| MinIO console (R2 stand-in) | http://localhost:9001 |
| Postgres 17 | localhost:15432 |
| Redis 7 | localhost:16379 |

Override a host port with `FORWARD_APP_PORT`, `FORWARD_DB_PORT` and the like when one is taken.

### On Windows: keep the code inside WSL

Docker reads a Windows folder (and a OneDrive one even more) slowly. Keep the repo in the WSL file system and run everything from there:

```sh
# in an Ubuntu (WSL 2) terminal, with Docker Desktop's WSL integration on
git clone https://github.com/ManLikeGanga19-tech/fanfolio.git ~/projects/fanfolio
cd ~/projects/fanfolio
npm install                 # once: chokidar for Octane --watch, Playwright for the browser checks
docker compose up -d --build
```

Octane runs with `--watch`, so PHP, config, route and `.env` changes reload by themselves; the `assets` service rebuilds CSS and JS. Restart Horizon after changing a job: `docker compose restart horizon`. Open the folder from Windows as `\\wsl.localhost\Ubuntu\home\<you>\projects\fanfolio`, or with VS Code's WSL extension (`code .` in the WSL terminal).

Run commands in the container, for example:

```sh
docker compose exec app php artisan fanfolio:dev-staff you@example.test
docker compose exec app vendor/bin/pest --parallel
docker compose exec app vendor/bin/phpstan analyse
```

### Fallback: PHP natively on Windows

When Docker is not an option, run PHP natively with only Postgres in Docker:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\dev.ps1   # app on http://localhost:8088
npm run watch                                            # rebuild CSS and JS on change (second terminal)
```

This needs PHP 8.4 with `extension=pdo_pgsql` on in `php.ini`. Sessions, cache and the queue use files, and mail goes to `storage/logs/laravel.log`. Use the full Docker stack for Horizon, Redis, MinIO and Mailpit.

To run the tests the same way (Postgres in Docker, PHP on Windows):

```powershell
$env:DB_HOST="127.0.0.1"; $env:DB_READ_HOSTS="127.0.0.1"; $env:DB_PORT="15432"
$env:OPENSSL_CONF="<php folder>xtras\ssl\openssl.cnf"   # the passkey tests make real P-256 keys
vendor/bin/pest
```

## Development mode

`.env.example` and `scripts/dev.ps1` turn on `FANFOLIO_DEV_MODE` (decision D-14; honoured only with `APP_ENV=local`):

- any 6-digit email code works at sign-up;
- the ID and selfie check opens a stand-in capture page that accepts anything (pick the result to test: pass, fail, review, underage, fraud);
- on a computer the ID check shows a QR code for your phone, plus "Continue on this computer (development only)";
- `php artisan fanfolio:dev-staff you@example.test` makes an account an admin (add `--role=moderator` for a moderator). Then set up an authenticator app and open `/admin`.

To try the phone flow on a real phone, open the app by your computer's network address. The live camera needs https; over plain http the page falls back to the phone's camera app.

## Checks

```sh
docker compose exec app vendor/bin/pest --parallel      # full suite (needs Postgres)
docker compose exec app composer ci                     # Pint, Rector, Larastan, tests
vendor/bin/pest --testsuite=Architecture,Unit           # fast, no database
npx playwright install chromium && npm run test:a11y   # axe, keyboard and focus checks against the running app
```

The accessibility checks (`tests/Browser`) open every fan page in Chromium, phone and desktop sizes. They fail on any WCAG 2.2 A/AA axe violation, on a control without a visible focus ring, or on a broken keyboard path. The signed-in pages are reached by signing up a fresh fake account through the real screens. The walk-through also makes a second account staff with `fanfolio:dev-staff`, approves the creator in `/admin` and opens `/studio`, so it needs development mode on and the same database settings as the app. Point them at another address with `A11Y_BASE_URL`.

CI (`.github/workflows/ci.yml`) also runs:
- migrate → rollback → migrate
- gitleaks
- composer and npm audit
- Semgrep
- Terraform validate
- the production image build
- the accessibility checks, against an app started with the Fake drivers

## Design tokens

`resources/css/tokens.css` is generated from `docs/design-system/tokens.json` and exposes each token as a Tailwind utility (`bg-surface`, `text-ink-muted`, `bg-brand`, `rounded-lg`, `text-h1` and so on). After the design system changes, run `npm run tokens`. CI fails if the CSS is out of date, and an architecture test fails if a view hard-codes a colour.

## Layout

- `app/Modules/<Name>/`: the 11 modules. Each owns its tables, declared in its provider's `TABLES`. Modules use each other only through `Contracts` and `Events`; architecture tests enforce this.
- `app/Support/`: the shared kernel (Money, webhook signatures, module and driver plumbing).
- `config/fanfolio.php`: business rules and gateway driver selection. Every outside provider has a Fake driver until it is chosen.
- `infra/terraform/`: infrastructure as code (the region is a required variable).
