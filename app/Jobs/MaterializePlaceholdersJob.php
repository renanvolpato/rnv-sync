<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Files\LocalFiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * On-demand model: mirror a tracked folder as cloud placeholders
 * (0-byte stubs) so everything shows in the file manager with a cloud
 * emblem, downloading nothing. The user then keeps items offline
 * individually. Never runs bisync (that would upload empty
 * placeholders).
 */
class MaterializePlaceholdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public int $syncFolderId) {}

    public function handle(LocalFiles $files): void
    {
        $folder = SyncFolder::with('account')->find($this->syncFolderId);

        if (! $folder || ! $folder->is_active || ! $folder->account) {
            return;
        }

        $history = SyncHistory::create([
            'account_id' => $folder->account_id,
            'sync_folder_id' => $folder->id,
            'started_at' => Carbon::now(),
            'status' => 'running',
        ]);

        try {
            $created = $files->materializeCloudPlaceholders($folder->account, $folder->remote_path);
        } catch (\Throwable $e) {
            $history->update(['status' => 'error', 'completed_at' => Carbon::now(), 'errors_count' => 1]);
            $folder->update(['last_sync_status' => 'error']);

            throw $e;
        }

        $history->update([
            'status' => 'success',
            'completed_at' => Carbon::now(),
            'files_transferred' => $created,
        ]);
        $folder->update(['last_synced_at' => Carbon::now(), 'last_sync_status' => 'success']);
        Account::whereKey($folder->account_id)->update(['last_synced_at' => Carbon::now()]);
    }
}
