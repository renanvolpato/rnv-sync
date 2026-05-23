# Architecture (for contributors)

RNV Sync is a single-user, self-hosted Laravel 12 + Livewire 3 app that
orchestrates a bundled rclone binary.

```
Browser ── HTTP/WebSocket ── Laravel (Livewire pages, Reverb, queue)
                                   │
                            Service layer (app/Services/*)
                              │            │
                         SQLite       rclone subprocess ── OneDrive
```

## Layers

- **Livewire pages** (`app/Livewire/Pages`) — screen logic only.
- **Service layer** (`app/Services`) — the *only* layer that invokes
  rclone or calls Microsoft Graph:
  - `Rclone/` — `RcloneBinary`, `RcloneRunner`, `RcloneResult`,
    `RcloneConfigGenerator`, `JsonLogParser`
  - `Graph/` — `OneDriveOAuth` (in-app OAuth, refresh, drive/tenant)
  - `Accounts/`, `Sync/` (incl. `RemoteFolderMirror`), `Files/` (physical
    model: real files + ☁ placeholders), `Mount/`, `Cache/`, `Conflicts/`,
    `Settings/`
- **Jobs** (`app/Jobs`) — `MirrorRemoteFoldersJob` (mirror the whole drive
  online on connect), `SyncChangesJob` (lightweight two-way change sync),
  `MaterializePlaceholdersJob`, `DownloadPathJob`/`FreeOnlineJob` (keep
  local / online), `StartSyncJob` (bisync, retry/backoff).
- **Console commands** — scheduled sync, remote/local folder discovery,
  mount supervisor, cache evict, usage capture, update check, orphan prune
  (wired in `routes/console.php`).
- **Events** (`app/Events`) — Reverb broadcasts (sync progress/status,
  conflict detected).

## Sync model (on-demand, online by default)

Connecting an account mirrors the **whole** drive as 0-byte ☁ placeholders
automatically (`MirrorRemoteFoldersJob` → `RemoteFolderMirror`) — there is no
manual folder-selection step. Files download only when the user chooses
**Keep local**. Per folder, `SyncChangesJob` then does a gentle two-way sync:
push real local files up, pull updates for kept-offline files, and — throttled
to `sync.placeholder_refresh_minutes` (default 120) — re-list the remote to
surface new cloud files as placeholders. The file watcher (`rnvsync:watch`,
inotify) uploads local edits in real time; the 15-min scheduler is the safety
net. A single queue worker runs everything, so jobs are kept light, are
`ShouldBeUnique` per folder, and a fully-online folder skips push/pull entirely.

## Data model

Tables are prefixed `rnvsync_` (SPEC §7): accounts, sync_folders,
file_policies, sync_history, conflicts, settings, mount_processes,
usage_snapshots, users.

## rclone integration

Every call goes through `RcloneRunner`, always using the bundled binary
and an isolated `--config` path with `--use-json-log`. The config file
is regenerated from the `accounts` table on change. Long-running
processes (mount) are detached and tracked by PID.

## Conventions

- PHP 8.3, `declare(strict_types=1)` in services/value objects.
- PSR-12 via Laravel Pint (`./vendor/bin/pint`).
- Pest tests in `tests/Feature` and `tests/Unit`; mock `RcloneRunner`.
- English in code; UI strings via `__()` (EN + PT-BR in `lang/`).

## Running the suite

```bash
php artisan test          # requires the pdo_sqlite extension
./vendor/bin/pint --test  # code style
npm run build             # assets (Tailwind v4 + Flux + Echo)
```
