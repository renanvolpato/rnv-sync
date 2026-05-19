# Installation

RNV Sync runs locally on your Linux machine and is accessed in a browser
at <http://localhost:8080>.

## Option A — Docker Compose (recommended)

Requirements: Docker 24+ and the Compose v2 plugin. FUSE mounting
requires elevated container capabilities (documented below).

```bash
mkdir ~/rnv-sync && cd ~/rnv-sync
curl -fsSL https://raw.githubusercontent.com/renanvolpato/rnv-sync/main/docker-compose.yml -o docker-compose.yml
docker compose up -d
```

Open <http://localhost:8080> within ~60 seconds and complete the setup
wizard (panel password, language, mount location).

### What the compose file does

- Binds the panel to `127.0.0.1:8080` only (see [security.md](security.md)).
- Mounts `./data` for the SQLite database, logs and rclone state.
- Maps `~/RnvSync` into the container so synced files are reachable.
- Grants `/dev/fuse`, `SYS_ADMIN` and `apparmor:unconfined` so
  `rclone mount` can expose Files-on-Demand. These are required for FUSE;
  a rootless alternative is planned post-1.0.

### Updating

```bash
docker compose pull && docker compose up -d
```

## Option B — Native install

Requirements: PHP 8.3 with `pdo_sqlite`, Composer, Node 20, and FUSE.

```bash
curl -fsSL https://raw.githubusercontent.com/renanvolpato/rnv-sync/main/install/install.sh | bash
```

The script detects your distro, installs missing dependencies, clones the
app to `~/.local/share/rnv-sync/`, downloads the pinned rclone binary,
generates `.env`, runs migrations and installs systemd **user** services
(`rnv-sync-web`, `rnv-sync-queue`, `rnv-sync-reverb`).

For a clone you already have, `bash install/bootstrap.sh` (or
`composer setup`) does the same prerequisite setup idempotently —
including installing the PHP SQLite extension for your distro. The web
**requirements screen** and `php artisan rnvsync:doctor` point to this
command whenever something is missing.

Uninstall with `install/uninstall.sh`.

## Backup & restore

Everything important lives in:

- `storage/database.sqlite` — app state
- `storage/rclone/rclone.conf` — generated rclone config (regenerable)
- `~/RnvSync/` — your synced files (regenerable from the cloud)

Use **Settings → Backup & restore** to export a JSON snapshot of your
settings and account list (without tokens). Tokens stay machine-local;
re-authenticate accounts after importing on a new machine.
