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
