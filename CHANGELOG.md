# Changelog

All notable changes to RNV Sync are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **Online by default.** The moment a OneDrive account is connected, the
  **entire** drive is mirrored as cloud (☁) placeholders automatically — both
  in the file manager and in the web file browser — with **no manual "select
  folders" step**. Nothing is downloaded until you choose **Keep local**. New
  top-level folders created on the OneDrive website keep appearing on their own.
  - New `App\Services\Sync\RemoteFolderMirror` holds the shared discovery logic
    (registers an `on_demand` folder, materialises ☁ placeholders, skips the
    Personal Vault/Trash, never resurrects a folder you removed).
  - New `App\Jobs\MirrorRemoteFoldersJob` runs it on connect (from both the
    zero-config and advanced OAuth paths) and refreshes the file-manager
    extension config so a brand-new account gets emblems immediately.
  - The dashboard account card and the activity page now lead with **Open
    files** instead of "Select folders".

### Performance

- The heavy recursive remote listing that surfaces new cloud files as
  placeholders is now **throttled by configuration**
  (`RNVSYNC_PLACEHOLDER_REFRESH_MINUTES`, default 120) instead of a fixed
  30 minutes, so large drives are re-indexed far less often. **Sync now** still
  forces an immediate refresh, and local edits still upload in real time.
- A **fully-online folder** (placeholders only, no real files) no longer makes
  rclone enumerate tens of thousands of placeholders on every sync — the
  push/pull steps are skipped when there is nothing real to move. In testing, a
  10-folder batch on a ~170k-file drive drained in ~9s instead of 15+ minutes.
- Fixed the queue visibility timeout: `retry_after` 90 → 3900s (override with
  `DB_QUEUE_RETRY_AFTER`). It must exceed the longest job timeout
  (downloads/keep-online run up to 3600s); at 90s a long sync or download was
  re-reserved while still running — wasted re-runs and a corruption risk.
- **Moved the heavy placeholder refresh off the queue worker.** The recursive
  remote listing now runs as its own hourly background command
  (`rnvsync:refresh-placeholders`, scheduled with `runInBackground`), so the
  per-folder change sync (`SyncChangesJob`) is push/pull only. A sync cycle that
  used to pin the tray icon on "Syncing N items" for hours (a 20-min listing
  blocking the single worker while new jobs piled up) now drains in seconds.

### Removed

- The manual **folder-selection screen** (`/accounts/{id}/folders`) and its
  Livewire component, view, route, tests and now-orphaned translation keys. It
  is obsolete under the online-by-default model. To stop syncing a folder, use
  the per-folder **Stop syncing** action on the account activity page.

### Docs

- Added `ONBOARDING.md` — a contributor-oriented map of the codebase (stack,
  service layer, jobs, end-to-end flows, risks).
- Updated `docs/architecture.md` (sync model + jobs), `docs/configuration.md`
  (new env vars + full scheduled-task list), `docs/usage.md`, the READMEs and
  `.env.example` to reflect online-by-default and the new tunables.
