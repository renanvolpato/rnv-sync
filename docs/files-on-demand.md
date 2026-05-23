# Storage models & file-manager integration

## Why two models

Windows' OneDrive "Files-on-Demand" (placeholder + hydrate-on-open +
overlay icons) relies on the Windows Cloud Files API. **Linux has no
equivalent** the file manager understands. So RNV Sync offers two
models (Settings → General → *Storage model*):

### Physical (default, recommended)

- Real files on disk under `{sync folder}/{account}`. **No FUSE, no
  virtual drive.**
- Cloud-only items show as 0-byte placeholders so they still appear in
  the file manager.
- The Nautilus extension adds emblems: ✓ green = downloaded, ☁ = cloud
  only, plus a right-click **Baixar / Liberar espaço**.
- Downloading is explicit (per file/folder) — there is no transparent
  hydrate-on-open on Linux without FUSE.

### On-demand (FUSE)

- `rclone mount`: low disk use, files download on access, but the
  account appears as a mounted virtual drive and has no native
  file-manager emblems.

## Install the Nautilus extension (physical mode)

Needs the GNOME Files Python binding:

```bash
# Ubuntu/Debian/Pop:
sudo apt-get install -y python3-nautilus
# Fedora: sudo dnf install -y nautilus-python
# Arch:   sudo pacman -S --noconfirm python-nautilus

bash install/nautilus/install.sh
```

Re-run `php artisan rnvsync:nautilus-config` after adding accounts (the
native installer / Docker entrypoint also refresh it).

## How it maps

- `php artisan rnvsync:nautilus-config` writes
  `~/.config/rnv-sync/extension.json` (CLI path + account base dirs).
- The extension calls `php artisan rnvsync:fs download|free <abs path>`,
  which resolves the account from the base dir and queues the work.
- Emblem rule: a real file (size > 0) or a directory = downloaded (✓);
  a 0-byte placeholder = cloud (☁). Genuinely empty cloud files are an
  accepted edge case.

## Hydrate on open (best-effort)

Opening a single ☁ placeholder auto-downloads it in the background (with a
desktop notification). Because Linux has no Cloud Files API to block the
`open()` and fetch content first (that needs FUSE), the **first** open still
shows the file empty — it becomes real moments later, so reopen it. Only a
**lone** open triggers it: a folder/thumbnailer scan that opens many
placeholders at once is ignored, so browsing never mass-downloads your drive.
Disable with `RNVSYNC_HYDRATE_ON_OPEN=false`. For fully transparent
hydrate-on-open, use the FUSE **mount** storage mode.

## Limitations (honest)

- First open of a not-yet-downloaded file shows it empty (no FUSE = no blocking
  fetch); the right-click **Baixar** action or the in-app file browser download
  it explicitly.
- Placeholder mirroring is best-effort; the source of truth is OneDrive
  (re-run a sync/refresh if the tree drifts).
