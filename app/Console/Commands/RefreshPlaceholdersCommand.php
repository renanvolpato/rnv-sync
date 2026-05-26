<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Surfaces NEW cloud-side files (created on the OneDrive website) inside
 * already-synced folders as ☁ placeholders, for every active on-demand
 * folder.
 *
 * This is the EXPENSIVE step — a recursive remote listing that can take many
 * minutes on a big folder. It deliberately lives HERE (a scheduled command
 * run with ->runInBackground()) instead of inside SyncChangesJob, so it never
 * monopolises the single queue worker: the fast push/pull change-sync and
 * user-triggered downloads keep flowing, and the tray icon is not pinned on
 * "syncing" while a huge folder is re-listed.
 *
 * Throttled per folder to sync.placeholder_refresh_minutes so a folder is not
 * re-listed on every run. Local edits still upload in real time (the file
 * watcher); "Sync now" forces an immediate refresh; brand-new top-level cloud
 * folders are still picked up quickly by rnvsync:discover-remote-folders.
 */
class RefreshPlaceholdersCommand extends Command
{
    protected $signature = 'rnvsync:refresh-placeholders {--force : ignore the per-folder throttle}';

    protected $description = 'Mirror new cloud files as ☁ placeholders for active on-demand folders';

    public function handle(LocalFiles $files): int
    {
        if (app(SyncService::class)->isPaused()) {
            $this->info('Sync is paused — skipping placeholder refresh.');

            return self::SUCCESS;
        }

        $ttl = max(60, (int) config('rnvsync.sync.placeholder_refresh_minutes', 120) * 60);
        $force = (bool) $this->option('force');
        $refreshed = 0;

        $folders = SyncFolder::with('account')
            ->where('is_active', true)
            ->where('sync_mode', 'on_demand')
            ->get();

        foreach ($folders as $folder) {
            if (! $folder->account) {
                continue;
            }

            $key = 'rnv-materialized-'.$folder->id;

            // Throttle: skip a folder re-listed within the window. --force
            // (and "Sync now", which uses its own job) bypasses it.
            if (! $force && Cache::has($key)) {
                continue;
            }

            try {
                $files->materializeCloudPlaceholders($folder->account, $folder->remote_path);
                Cache::put($key, 1, $ttl);          // throttle only after success
                $refreshed++;
            } catch (\Throwable $e) {
                // One folder failing (e.g. a giant listing) must never abort the
                // whole run or spam ERROR. Skip it, back off ~30 min, keep going.
                Cache::put($key, 1, min($ttl, 1800));
                Log::warning("refresh-placeholders: skipped #{$folder->id} {$folder->remote_path}: ".$e->getMessage());
            }
        }

        $this->info("Refreshed placeholders for {$refreshed} folder(s).");

        return self::SUCCESS;
    }
}
