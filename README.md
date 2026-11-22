# FanFolio

A web-only PWA where African creators sell subscriptions, pay-per-view posts, tips and paid messages to verified fans.

Start with `CLAUDE.md` (the build brief) and `docs/` (sources of truth: backend spec, screens, design system, security blueprint, product map, decisions, milestone plans).

## Run it

Requirements: Docker.

```sh
docker compose up -d
```

The first start installs dependencies, creates `.env`, generates the app key and runs migrations.

| Service | URL |
|---|---|
| App (Octane on Swoole) | http://localhost:8088 |
| Mailpit | http://localhost:8025 |
| MinIO console (R2 stand-in) | http://localhost:9001 |
| Postgres 17 | localhost:15432 |
| Redis 7 | localhost:16379 |

Override a host port with `FORWARD_APP_PORT`, `FORWARD_DB_PORT` and the like when one is taken.

## Checks

```sh
docker compose exec app vendor/bin/pest --parallel      # full suite (needs Postgres)
docker compose exec app composer ci                     # Pint, Rector, Larastan, tests
vendor/bin/pest --testsuite=Architecture,Unit           # fast, no database
```

CI (`.github/workflows/ci.yml`) also runs:
- migrate → rollback → migrate
- gitleaks
- composer and npm audit
- Semgrep
- Terraform validate
- the production image build

## Layout

- `app/Modules/<Name>/`: the 11 modules. Each owns its tables, declared in its provider's `TABLES`. Modules use each other only through `Contracts` and `Events`; architecture tests enforce this.
- `app/Support/`: the shared kernel (Money, webhook signatures, module and driver plumbing).
- `config/fanfolio.php`: business rules and gateway driver selection. Every outside provider has a Fake driver until it is chosen.
- `infra/terraform/`: infrastructure as code (the region is a required variable).
