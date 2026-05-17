<?php

use Illuminate\Support\Facades\Schedule;

// SPEC F2.5: background scheduled sync, default every 15 minutes.
Schedule::command('rnvsync:scheduled-sync')
    ->cron('*/'.config('rnvsync.defaults.sync_interval_minutes').' * * * *')
    ->withoutOverlapping();
