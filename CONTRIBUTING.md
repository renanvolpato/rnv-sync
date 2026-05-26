# Contributing to RNV Sync

Thanks for your interest! RNV Sync is MIT-licensed and community-driven.

## Getting started

```bash
git clone https://github.com/renanvolpato/rnv-sync.git && cd rnv-sync
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve --port=8770
```

Requires PHP 8.3 with the `pdo_sqlite` extension.

## Before you open a PR

- `php artisan test` — all Pest tests pass.
- `./vendor/bin/pint` — code style applied (PSR-12 + Laravel).
- New feature ⇒ at least one feature test (mock `RcloneRunner`; never
  hit the network or a real remote in tests).
- One logical change per commit; Conventional Commits
  (`type(scope): description`).
- Don't introduce new infra (no Redis/MySQL/SPA frameworks) — see
  `SPEC.md`.

## Local testing gotchas

- The bundled rclone binary (`rclone/rclone`) is **gitignored** — fresh clones
  and CI don't ship it; `install/bootstrap.sh` downloads it. Tests that exercise
  code guarded by `RcloneBinary::isAvailable()` (which checks the real file on
  disk) therefore need a workaround. The pattern used in the suite: point the
  config at a known-executable file in the test's `beforeEach`:
  `config(['rnvsync.rclone.binary_path' => '/bin/bash']);`
- To reproduce CI locally: `cp .env.example .env && php artisan key:generate
  && php artisan test`. Passes here ⇒ should pass on CI (Ubuntu + PHP 8.3
  with `pdo_sqlite, sqlite3, mbstring, intl, bcmath`, SQLite `:memory:`).
- `php artisan rnvsync:doctor` reports any missing system dep (sqlite,
  inotify-tools, ayatanaappindicator, file-manager python bindings).

## Scope discipline

Implement against `SPEC.md`. Honor its "what we don't do" lists. Open an
issue to discuss before building anything not in the spec.

## Project layout & conventions

See [docs/architecture.md](docs/architecture.md).

## Translations

See [docs/contributing-translations.md](docs/contributing-translations.md).

## Code of Conduct

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
