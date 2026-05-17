<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\SyncStatusChanged;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Conflicts\ConflictsService;
use App\Services\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Background folder sync (SPEC F2.2/F2.4/F2.5).
 *
 * EARS: IF the network connection fails (or rclone hits 429), retry up
 * to 3 times with exponential backoff before marking the sync failed
 * (SPEC §9 v0.2.0 / §17 — backoff 5s, 30s, 5min).
 */
class StartSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 1 initial attempt + 3 retries. */
    public int $tries = 4;

    public function __construct(public int $syncFolderId) {}

    /**
     * Exponential backoff between attempts (seconds).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('rnvsync.sync.backoff_seconds');
    }

    public function handle(SyncService $sync): void
    {
        if ($sync->isPaused()) {
            return;
        }

        $folder = SyncFolder::with('account')->find($this->syncFolderId);

        if (! $folder || ! $folder->is_active) {
            return;
        }

        // SPEC F4.4 EARS: an account with >10 conflicts is auto-paused.
        if ($folder->account
            && app(ConflictsService::class)->isAccountPaused($folder->account)) {
            return;
        }

        // Throws RcloneException on a retryable failure → Laravel re-queues
        // with the configured backoff until $tries is exhausted.
        $sync->runSync($folder);
    }

    public function failed(?Throwable $e): void
    {
        $folder = SyncFolder::with('account')->find($this->syncFolderId);

        if (! $folder) {
            return;
        }

        $folder->update(['last_sync_status' => 'error']);
        $folder->account?->update(['status' => Account::STATUS_ERROR]);

        event(new SyncStatusChanged(
            $folder->account_id,
            'failed',
            $e?->getMessage() ?? 'Sync failed after retries.',
        ));
    }
}
