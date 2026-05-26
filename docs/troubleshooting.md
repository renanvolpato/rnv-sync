# Troubleshooting

> **First stop, always:** open **Settings → "Integração com o desktop"** in the
> app. It self-diagnoses every desktop-integration item (file manager, emblems,
> right-click menu, watched folders, tray) and tells you what's wrong and how to
> fix it (incl. a one-click **Reaplicar integração** button). Most issues below
> are explained there first.

## The panel isn't reachable at localhost:8770

- `docker compose ps` — is the container healthy?
- `docker compose logs rnv-sync` — look for migration or boot errors.
- Confirm nothing else uses port 8770.

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

## The disk is filling up after "Keep local"

Marking a folder **Keep local** queues a recursive `rclone copy` that downloads
its whole tree. On a small SSD that can fill the disk before you notice.

**Stop it immediately, in the app (no terminal):**

1. Click **Pausar** in the header. Pause now actually stops syncing: it kills
   any rclone process this app started **and** every sync path (scheduled sync,
   watcher, hourly refresh, queued downloads) skips while paused.
2. Mark the folders you want freed as **Manter Online** in the web file browser
   or via the file-manager right-click menu. Free uploads anything new first
   and then turns the files into 0-byte placeholders — your space comes back.
3. Click **Retomar** when you're ready for automatic sync again.

**Safety net** (always on): the **disk guard**
(`RNVSYNC_DOWNLOAD_MAX_DISK_PERCENT`, default `95`) makes new downloads skip and
flag an error when the target filesystem is at or above the threshold, so a
"Keep local" can't fill the disk to 100%. Set `0`/`100` to disable.

## "Pausar" shows "sincronizando" — paused but not stopped?

That's a one-shot action you triggered (Manter Online / Manter Local / opening
a placeholder) finishing. Those run even when paused because **you** asked for
them (and they're usually freeing space). The automatic sync stays paused.

In the latest version the header indicator says **Pausado** even while one of
those actions wraps up, and the tray menu reads
`Pausado — concluindo (X)…` with the live items, so you can see the action
progressing without thinking sync resumed.

## The tray icon doesn't appear

The panel above tells you which case you're in. The most common ones:

- **GNOME (Ubuntu, Pop GNOME, Fedora, Debian):** GNOME Shell hides tray icons
  by default — they need the **AppIndicator** shell extension. The installer
  now installs and enables it automatically; if it still doesn't appear,
  **log out and back in once** (GNOME loads shell extensions at login).
- **You clicked "Sair" in the tray menu:** older builds quit the tray process
  (autostart brings it back at next login). In recent builds that item is now
  labeled **"Ocultar ícone"** to make it clear it doesn't stop syncing — use
  **Pausar** in the header instead to actually stop the sync.
- **The update can't relaunch the tray:** when the panel is updated via the
  one-click button, the detached updater may lack the graphical session env
  needed to relaunch the tray. It pulls `DISPLAY`/Wayland from `systemd --user`,
  but on a few setups that still misses. Logging out and back in always works.

## The file-manager emblems / right-click menu don't appear

Open the panel — it lists the exact reason. The most common, in order:

1. **"Nenhuma pasta configurada"** — the extension has no folder to watch.
   Click **Reaplicar integração** in the panel. It re-runs the config and
   reinstalls the extension; the menu and emblems light up.
2. **"Extensão NÃO foi carregada pelo Nautilus"** — the extension file is on
   disk but the file manager didn't instantiate it. Click **Reaplicar
   integração**; if it still doesn't load, log out and back in once.
3. **"Nautilus em sandbox (snap/flatpak)"** — a snap/flatpak Nautilus runs
   sandboxed and cannot load host Python extensions, so emblems and the menu
   will **never** appear with this build. Install the system Nautilus
   (`sudo apt install nautilus`) and use that.
4. **"O tema de ícones ainda não reconhece os emblemas"** — your active icon
   theme doesn't resolve our custom emblem icons yet. The installer writes the
   hicolor `index.theme` to declare the `scalable/emblems` context and copies
   to both `~/.local/share/icons/hicolor` and the legacy `~/.icons/hicolor`.
   Log out and back in once to refresh the icon cache.
5. **You're browsing the wrong folder.** Emblems and the right-click menu only
   appear **inside** your RNV Sync folder (the one shown under "Pastas
   monitoradas" in the panel) — by design. They don't show in your generic Home
   or Documents.
6. **Your desktop is COSMIC (recent Pop!_OS) or KDE** — COSMIC Files and
   Dolphin don't support the Nautilus extension API, so emblems/menu won't
   appear there. Use GNOME Files (Nautilus), Nemo (Cinnamon) or Caja (MATE).

## Logs

- `storage/logs/rnvsync-app.log` — application (secrets redacted)
- `storage/logs/rnvsync-rclone.log` — parsed rclone output
- `storage/logs/rnvsync-mount-{id}.log` — per-mount rclone log
- `storage/logs/update.log` — output of the one-click updater
- `~/.cache/rnv-sync/extension-loaded.json` — written when the file-manager
  extension is instantiated; surfaced as "Extensão carregada pelo Nautilus
  (há X)" in the Settings panel.
