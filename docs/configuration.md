# Configuration

Most configuration is done in the UI (**Settings**). Advanced operators
can use environment variables and `config/rnvsync.php`.

## Settings (UI)

| Section | Options |
|---|---|
| General | Language (EN / PT-BR), mount base path |
| Network | Global bandwidth limit (KB/s), bandwidth schedule (time window) |
| Cache | Maximum cache size (GB) — empty = automatic |
| Panel password | Change the login password |
| Backup & restore | Export / import config JSON |
| About | App and rclone versions |

Per-folder advanced overrides (transfers, checkers, chunk size) are set
from an account's sync activity screen.

## Environment variables (`.env`)

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost:8770` | Public URL; OAuth redirect base |
| `DB_DATABASE` | `storage/database.sqlite` | SQLite path |
| `RCLONE_BINARY_PATH` | `base_path('rclone/rclone')` | Bundled rclone |
| `RCLONE_CONFIG_PATH` | `storage/rclone/rclone.conf` | Generated config |
| `RCLONE_CACHE_DIR` | `storage/rclone/cache` | VFS cache |
| `RCLONE_MOUNT_BASE` | `~/RnvSync` | Where files appear |
| `ONEDRIVE_CLIENT_ID` | rclone public id | Azure app id (override with your own) |
| `REVERB_*` | see `.env.example` | WebSocket server |

## Defaults (`config/rnvsync.php`)

Sync flags, mount flags, cache fractions, retry/backoff and the OAuth
endpoints/scopes are defined here. Values map directly to SPEC §8 and
§17 and are pinned; change them only when you understand the impact.

## Scheduled tasks

The container/systemd runs `php artisan schedule:run` every minute,
which drives:

- `rnvsync:scheduled-sync` — every 15 min (configurable)
- `rnvsync:mount-supervisor` — every minute (mount health/restart)
- cache LRU eviction — every 5 min
- `rnvsync:capture-usage` — daily (storage trends)
