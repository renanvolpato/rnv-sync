# Contributing to RNV Sync

Thanks for your interest! RNV Sync is MIT-licensed and community-driven.

## Getting started

```bash
git clone <repo> && cd rnv-sync
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve --port=8080
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
  `CLAUDE.md` and `SPEC.md`.

## Scope discipline

Implement against `SPEC.md`. Honor its "what we don't do" lists. Open an
issue to discuss before building anything not in the spec.

## Project layout & conventions

See [docs/architecture.md](docs/architecture.md).

## Translations

See [docs/contributing-translations.md](docs/contributing-translations.md).

## Code of Conduct

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
