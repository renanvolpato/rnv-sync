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

        foreach ($folders as $folder) {
            // bisync folders: full two-way sync.
            // on-demand: lightweight change sync (push local edits, pull
            //   updates to kept-offline files) — no heavy recursive
            //   scan; placeholders are filled lazily while browsing.
            $folder->sync_mode === 'bisync'
                ? StartSyncJob::dispatch($folder->id)
                : SyncChangesJob::dispatch($folder->id);
        }

        $this->info("Queued {$folders->count()} folder sync(s).");

        return self::SUCCESS;
    }
}
