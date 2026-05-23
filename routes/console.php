<?php

use App\Services\Cache\CacheService;
use Illuminate\Support\Facades\Schedule;

// SPEC F2.5: background scheduled sync, default every 15 minutes.
Schedule::command('rnvsync:scheduled-sync')
    ->cron('*/'.config('rnvsync.defaults.sync_interval_minutes').' * * * *')
    ->withoutOverlapping();

// SPEC F3.2: detect dead mounts within 60 seconds and restart.
Schedule::command('rnvsync:mount-supervisor')
    ->everyMinute()
    ->withoutOverlapping();

// Self-heal: clear stuck sync state (orphaned "running" history, stale
// pending markers, dead live-stats pointer) so the tray icon never sticks
// on "syncing". Cheap (a few SQLite queries) and idempotent.
Schedule::command('rnvsync:heal')
    ->everyThreeMinutes()
    ->withoutOverlapping();

// SPEC F3.9: enforce the cache size limit by LRU eviction (pinned safe).
Schedule::call(fn () => app(CacheService::class)->evictToLimit())
    ->everyFiveMinutes()
    ->name('rnvsync-cache-evict')
    ->withoutOverlapping();

// SPEC F5.5: daily storage usage snapshot for trends.
Schedule::command('rnvsync:capture-usage')->dailyAt('23:55');

// Background update check (twice a day) → powers the "update
// available" badge without manual checks or network on page loads.
Schedule::command('rnvsync:check-updates')
    ->twiceDaily(8, 20)
    ->withoutOverlapping();

// Detect folders the user renamed/deleted on the cloud and deactivate
// them locally — keeps the panel and disk in sync with the remote
// without the user having to babysit.
Schedule::command('rnvsync:prune-orphan-folders')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Pick up folders the user created LOCALLY in the sync root and start
// uploading them — "anything in the folder goes to the cloud".
Schedule::command('rnvsync:adopt-local-folders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Whole-drive mirror: new top-level folders created on the OneDrive
// website appear locally as ☁ placeholders automatically (folders the
// user removed stay removed). The local→cloud counterpart of adopt.
Schedule::command('rnvsync:discover-remote-folders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Surface NEW cloud-side files (created on the OneDrive website) inside
// existing folders as ☁ placeholders. This recursive remote listing is the
// heavy part of on-demand sync (minutes on big folders), so it runs in its
// OWN background process — OFF the single queue worker, so the change-sync
// and user downloads stay snappy and the tray icon settles. Throttled per
// folder by sync.placeholder_refresh_minutes.
Schedule::command('rnvsync:refresh-placeholders')
    ->hourly()
    ->runInBackground()
    ->withoutOverlapping();
