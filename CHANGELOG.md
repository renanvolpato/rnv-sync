# Changelog

All notable changes to RNV Sync are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Delete propagation — groundwork, OFF by default** (`RNVSYNC_PROPAGATE_DELETES`,
  experimental). Mirrors local deletions of files/subfolders to the OneDrive
  recycle bin (recoverable) so they don't reappear, heavily guarded
  (`PendingOps` incl. ancestors, still-gone debounce, a mass-disappearance cap).
  Kept disabled until validated end-to-end: it is risky in this architecture
  (internal churn could cause cloud deletions) and deleting a whole top-level
  folder is not yet covered. Includes `App\Jobs\PropagateDeleteJob` and the
  watcher detection, all inert while the flag is off.
- **Hydrate on open** (physical model, best-effort). Opening a single cloud (☁)
  file in the file manager now downloads it automatically in the background,
  with a desktop notification. Linux can't block the open to fetch content
  first (no Cloud Files API without FUSE), so the very first open still shows
  the file empty — it becomes real moments later (reopen to see it). Crucially,
  only a **lone** open triggers it: a folder/thumbnailer scan that opens many
  placeholders at once is ignored, so browsing never mass-downloads the drive.
  Toggle with `RNVSYNC_HYDRATE_ON_OPEN`. (For fully transparent hydrate-on-open,
  use the FUSE mount storage mode.)
- **Self-heal** (`rnvsync:heal`, scheduled every 3 minutes). A cheap, idempotent
  safety net that clears the leftover state which could otherwise pin the tray
  icon on "Syncing…": sync runs left `running` by a killed job, stale pending
  download/keep-online markers (missing file or no backing job), and a dead
  live-stats pointer. It never touches user files and never restarts the worker
  (systemd already does that) — it only tidies bookkeeping. Runs via the
  existing scheduler service, so it adds no new process and negligible load.

### Changed

- **"Keep online" on a folder now leaves ☁ placeholders** (it uploads the real
  files, then truncates them in place to 0 bytes) instead of emptying the folder.
  The folder stays visible with its files as cloud items — and the change can
  never be mistaken for a deletion by the watcher.
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

### Fixed

- **Placeholder refresh no longer times out (and surfaces nothing) on huge
  folders.** The hourly "new cloud files → ☁ placeholders" step did one recursive
  remote listing per folder; on an 80k+ file OneDrive folder that single listing
  blew past the timeout (~28 min) and **created zero placeholders**, so files
  added on the OneDrive website never appeared locally in the background (they
  only showed when you browsed into the subfolder). The listing now **shards
  adaptively**: it tries the whole subtree, and if that is too slow it lists only
  the immediate children and recurses into each subdir (up to 3 levels), so only
  a genuinely huge branch is split and the rest still completes. A folder learned
  to be oversized is sharded from the start next time (cached 7 days). The hourly
  command is also resilient now — one folder failing no longer aborts the whole
  run or spams the error log; it backs off and the other folders still refresh.
- **File browser no longer shows a false, sticky "rclone is not available".**
  Any single listing error (a transient timeout, an expired session, browsing a
  folder that was just deleted) used to flip the catch-all `rcloneUnavailable`
  flag, and since the view polls every 5s and the flag never reset, the
  misleading "the bundled rclone engine could not be reached / install it" banner
  latched until a full page reload — even with rclone perfectly installed. The
  banner now appears **only when the bundled binary is genuinely missing**; a
  per-listing error shows a soft, accurate "couldn't load this folder, retrying"
  state (logged), and the flag is recomputed every render so it self-clears.
- **Tray icon could stay stuck on the spinner after a sync finished** (the menu
  read "Tudo sincronizado" but the icon still showed the circular arrow). The
  static cloud icon was set from the 2s poll while the 120ms spinner animation
  ran separately, and the race left some panels on a stale frame. The animation
  loop now solely owns the icon and makes one clean spinner→cloud switch on idle.
- **Orphan-prune no longer deletes local data.** `rnvsync:prune-orphan-folders`
  used to delete a folder's local placeholder shell when its remote listing
  returned "not found" — so a single transient/eventual-consistency miss could
  wipe the local files of a folder that still exists on the cloud. It now only
  **deactivates** (stops tracking, never deletes local files) and only after a
  **second confirming check** (a one-off "not found" is ignored).

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
