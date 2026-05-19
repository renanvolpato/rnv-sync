<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\StartSyncJob;
use App\Jobs\SyncChangesJob;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Files\LocalFiles;
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

    /** On-demand folder still re-checked on the schedule even if no real files. */
    private const PLACEHOLDER_ONLY_SAFETY_HOURS = 4;

    public function handle(SyncService $sync, LocalFiles $local): int
    {
        SyncHistory::sweepStale();

        if ($sync->isPaused()) {
            $this->info('Sync is paused — skipping.');

            return self::SUCCESS;
        }

        $folders = SyncFolder::query()->where('is_active', true)->get();
        $queued = 0;
        $skipped = 0;

        foreach ($folders as $folder) {
            if ($folder->sync_mode === 'bisync') {
                StartSyncJob::dispatch($folder->id);
                $queued++;

                continue;
            }

            // on-demand. The real-time watcher (rnvsync:watch) already
            // catches every local change instantly, and the PULL phase
            // is a no-op when there are no kept-offline files. So if a
            // folder only holds placeholders we'd just burn rclone API
            // calls listing the remote for nothing — skip it. Still
            // run a full safety check every few hours in case the
            // watcher missed something (service was down, etc.).
            $stale = ! $folder->last_synced_at
                || $folder->last_synced_at->lt(
                    now()->subHours(self::PLACEHOLDER_ONLY_SAFETY_HOURS)
                );
            $hasReal = $local->hasAnyRealFile($folder->local_path);

            if ($hasReal || $stale) {
                SyncChangesJob::dispatch($folder->id);
                $queued++;
            } else {
                $skipped++;
            }
        }

        $this->info("Queued {$queued} folder sync(s); skipped {$skipped} placeholder-only folder(s).");

        return self::SUCCESS;
    }
}
