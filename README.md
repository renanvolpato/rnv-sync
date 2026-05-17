# RNV Sync

> A beautiful, self-hosted OneDrive client for Linux — powered by [rclone](https://rclone.org/).

RNV Sync gives Linux users a clean, native-feeling web interface to manage
their OneDrive accounts. It is a Laravel application that bundles and
orchestrates rclone — the relationship mirrors GitHub Desktop's relationship
to git: rclone is the trusted engine, RNV Sync is the polished UX layer.

> Formerly codenamed **Cirrus**. The specification (`SPEC.md`) still uses the
> old name in places; the project, table prefix (`rnvsync_`), config
> (`config/rnvsync.php`) and default mount path (`~/RnvSync`) use the new one.

## Why RNV Sync

- **No CLI required** — add accounts and browse files from a browser.
- **Your data stays local** — OAuth tokens are encrypted at rest; no telemetry.
- **Built on rclone** — the most capable cloud sync engine available.

## Built on rclone

RNV Sync ships the official, unmodified rclone binary (version pinned in
`config/rnvsync.php`). See `LICENSES/rclone.txt`.

## Quick install (Docker)

```bash
mkdir ~/rnv-sync && cd ~/rnv-sync
curl -fsSL https://raw.githubusercontent.com/<owner>/rnv-sync/main/docker-compose.yml -o docker-compose.yml
docker compose up -d
```

Open <http://localhost:8080> and complete the setup wizard.

## Features (v0.1.0 — Foundation)

- Docker Compose installation
- First-run setup wizard (panel password, language, mount location)
- Local panel authentication with login throttling
- Add a OneDrive Personal account via Microsoft OAuth (in-app)
- Read-only remote file tree (via `rclone lsjson`)
- Account dashboard with storage quota
- PT-BR and EN translations, automatic locale detection
- Settings: change password, language and mount base path

Sync, Files-on-Demand mount and conflict resolution arrive in later
milestones (see `SPEC.md` §9).

## Development

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve --port=8080
php artisan test
```

Requires PHP 8.3 with the `sqlite3`/`pdo_sqlite` extension.

## License

MIT — see `LICENSE`. Acknowledgments: rclone, Laravel, Livewire, Flux UI.
