# Troubleshooting

## The panel isn't reachable at localhost:8080

- `docker compose ps` — is the container healthy?
- `docker compose logs rnv-sync` — look for migration or boot errors.
- Confirm nothing else uses port 8080.

## "rclone is not available"

The bundled binary is missing or not executable. In Docker it is built
in; for native installs ensure `~/.local/share/rnv-sync/rclone/rclone`
exists and is `chmod +x`. The version is pinned in `config/rnvsync.php`.

## Mounts keep failing / "Reconnect"

FUSE needs `/dev/fuse`, `SYS_ADMIN` and `apparmor:unconfined` (already
in the provided compose file). The mount supervisor retries up to 3
times, then marks the account `error`. Check
`storage/logs/rnvsync-mount-{id}.log`.

## "Microsoft is rate-limiting requests"

A 429 from Microsoft. RNV Sync respects `Retry-After` and retries with
exponential backoff (5s/30s/5min). It resolves itself; no action needed.

## "redirect_uri is not valid" on the Microsoft page

You're using the default (rclone) client id, which doesn't allow RNV
Sync's redirect. Register your own Microsoft Entra app and set
`ONEDRIVE_CLIENT_ID`/`ONEDRIVE_CLIENT_SECRET` — full steps in
[oauth.md](oauth.md). The redirect URI must equal
`${APP_URL}/oauth/callback` exactly (scheme, host, port, path).

## Sign-in fails

- "Session expired" → start again (CSRF state expired).
- "You declined the authorization" → retry and approve the scopes.
- Persistent failure with a custom `ONEDRIVE_CLIENT_ID` → verify the
  Azure app redirect URI matches `${APP_URL}/oauth/callback`.

## Account shows "Disconnected"

Token refresh failed. Open the account and reconnect via OAuth.

## Database is locked

SQLite under heavy concurrency. RNV Sync retries automatically; if it
persists, ensure only one app instance uses `storage/database.sqlite`.

## Logs

- `storage/logs/rnvsync-app.log` — application (secrets redacted)
- `storage/logs/rnvsync-rclone.log` — parsed rclone output
- `storage/logs/rnvsync-mount-{id}.log` — per-mount rclone log
