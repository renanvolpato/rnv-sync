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

## Limitations (honest)

- No hydrate-on-open: opening a placeholder won't auto-download; use the
  right-click action or the in-app file browser.
- Placeholder mirroring is best-effort; the source of truth is OneDrive
  (re-run a sync/refresh if the tree drifts).
