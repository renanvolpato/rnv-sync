<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\StartSyncJob;
use App\Models\SyncFolder;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;

/**
 * Dispatches a sync for every active folder (SPEC F2.5 — background
 * scheduled sync, default every 15 minutes when active). Skips when
 * sync is globally paused (F2.6).
 */
class ScheduledSyncCommand extends Command
{
    protected $signature = 'rnvsync:scheduled-sync';

    protected $description = 'Queue a sync for every active folder';

    public function handle(SyncService $sync): int
    {
        if ($sync->isPaused()) {
            $this->info('Sync is paused — skipping.');

            return self::SUCCESS;
        }

        $folders = SyncFolder::query()->where('is_active', true)->get();

        foreach ($folders as $folder) {
            StartSyncJob::dispatch($folder->id);
        }

        $this->info("Queued {$folders->count()} folder sync(s).");

        return self::SUCCESS;
    }
}
