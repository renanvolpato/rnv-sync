<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\StartSyncJob;
use App\Jobs\SyncChangesJob;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
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
        SyncHistory::sweepStale();

        if ($sync->isPaused()) {
            $this->info('Sync is paused — skipping.');

            return self::SUCCESS;
        }

        $folders = SyncFolder::query()->where('is_active', true)->get();
        $queued = 0;

        foreach ($folders as $folder) {
            if ($folder->sync_mode === 'bisync') {
                StartSyncJob::dispatch($folder->id);
            } else {
                // on-demand: ALWAYS queue. The local watcher catches
                // local edits instantly, but new files/subfolders created
                // on the OneDrive *website* are only discoverable by
                // re-listing the remote — which SyncChangesJob now does
                // every run (materialising them as ☁ placeholders). An
                // earlier "skip placeholder-only folders" optimisation
                // meant those remote additions stayed invisible for hours;
                // SyncChangesJob is ShouldBeUnique, so queueing them all
                // never piles up. (Discovery is one lsjson per folder —
                // ~2-3s — so this stays cheap.)
                SyncChangesJob::dispatch($folder->id);
            }
            $queued++;
        }

        $this->info("Queued {$queued} folder sync(s).");

        return self::SUCCESS;
    }
}
