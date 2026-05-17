# RNV Sync

> A beautiful, self-hosted OneDrive client for Linux — powered by [rclone](https://rclone.org/).

RNV Sync gives Linux users a clean, native-feeling web interface to
manage their OneDrive accounts. It is a Laravel application that bundles
and orchestrates rclone — the relationship mirrors GitHub Desktop's
relationship to git: rclone is the trusted engine, RNV Sync is the
polished UX and lifecycle layer.

> Formerly codenamed **Cirrus**. `SPEC.md` still uses the old name in
> places; the project, table prefix (`rnvsync_`), config
> (`config/rnvsync.php`) and default mount path (`~/RnvSync`) use the new
> one.

![Dashboard](docs/images/dashboard.png)

## Why RNV Sync

- **No CLI required** — add accounts and manage sync from a browser.
- **Your data stays local** — OAuth tokens encrypted at rest; zero telemetry.
- **Built on rclone** — the most capable cloud sync engine available.

## Built on rclone

RNV Sync ships the official, **unmodified** rclone binary (version
pinned in `config/rnvsync.php`). See `LICENSES/rclone.txt`.

## Quick install (Docker)

```bash
mkdir ~/rnv-sync && cd ~/rnv-sync
curl -fsSL https://raw.githubusercontent.com/<owner>/rnv-sync/main/docker-compose.yml -o docker-compose.yml
docker compose up -d
```

Open <http://localhost:8080> and complete the setup wizard. Native
install: see [docs/installation.md](docs/installation.md).

## Features

- One-command Docker install; native installer with systemd user units
- First-run setup wizard; single-user panel auth with login throttling
- OneDrive **Personal, Business and SharePoint** via in-app Microsoft
  OAuth (encrypted tokens, automatic refresh)
- Bidirectional sync (`rclone bisync`) with history, manual sync,
  scheduled sync, global pause/resume
- Real-time progress over WebSocket (Reverb)
- Files-on-Demand: mount, per-file cache status, pin "always offline",
  free up space, LRU eviction protecting pinned files
- Conflict detection & visual resolution (per-file and bulk)
- Bandwidth limit + scheduler; per-folder advanced rclone overrides
- Cross-account search; storage usage trends
- Config export/import; onboarding tour; PT-BR & EN; dark mode; a11y

## Screenshots

| Dashboard | Files-on-Demand | Conflicts |
|---|---|---|
| ![](docs/images/dashboard.png) | ![](docs/images/files.png) | ![](docs/images/conflicts.png) |

## Comparison

| | RNV Sync | raw rclone | abraunegg/onedrive | onedriver |
|---|---|---|---|---|
| Web GUI | ✅ | ❌ | ❌ | ❌ |
| Files-on-Demand | ✅ | ⚠ manual | ❌ | ✅ |
| Multi-account | ✅ | ⚠ manual | ⚠ | ⚠ |
| Business/SharePoint | ✅ | ✅ | ✅ | ⚠ |
| Visual conflict resolution | ✅ | ❌ | ❌ | ❌ |
| Bilingual UI (PT-BR/EN) | ✅ | ❌ | ❌ | ❌ |
| Open source | ✅ (MIT) | ✅ | ✅ | ✅ |

## Documentation

[Installation](docs/installation.md) ·
[Configuration](docs/configuration.md) ·
[Usage](docs/usage.md) ·
[Troubleshooting](docs/troubleshooting.md) ·
[FAQ](docs/faq.md) ·
[Security](docs/security.md) ·
[Architecture](docs/architecture.md)

## Development

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve --port=8080
php artisan test
```

Requires PHP 8.3 with the `pdo_sqlite` extension. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).

## Acknowledgments

[rclone](https://rclone.org/), [Laravel](https://laravel.com),
[Livewire](https://livewire.laravel.com),
[Flux UI](https://fluxui.dev), [Tailwind CSS](https://tailwindcss.com).
