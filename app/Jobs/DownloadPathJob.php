<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Services\Files\DiskGuard;
use App\Services\Files\LocalFiles;
use App\Services\Files\PathErrors;
use App\Services\Files\PendingOps;
use App\Services\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Physical mode: download a remote file/folder to disk in the
 * background so the UI never blocks (a folder can be huge).
 */
class DownloadPathJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public int $accountId,
        public string $path,
    ) {}

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'dl-'.$this->accountId.':'.$this->path;
    }

    public function handle(LocalFiles $files): void
    {
        $account = Account::find($this->accountId);

        if (! $account) {
            return;
        }

        $local = $files->localPathFor($account, $this->path);

        // Pause guard: a paused user wants downloads to STOP, not keep filling
        // the disk. Surface a "paused" error so the user sees why and can
        // re-trigger the download after resuming.
        if (app(SyncService::class)->isPaused()) {
            PendingOps::clear($local);
            PathErrors::mark($local, __('errors.sync_paused_skip'));

            return;
        }

        // Disk guard: never let "keep local" fill the disk. If the target
        // filesystem is past the fill threshold, skip and surface an error
        // instead of downloading — the user frees space or raises the limit.
        if (! DiskGuard::hasRoom($local)) {
            PendingOps::clear($local);
            PathErrors::mark($local, __('errors.disk_full_skip'));
            Log::warning("download skipped — disk past fill threshold: {$this->path}");

            return;
        }

        // On exception we DON'T clear pending here: the ⟳ state must
        // persist across retries. Only success clears it.
        $files->download($account, $this->path);

        PendingOps::clear($local);
        PathErrors::clear($local);
    }

    public function failed(?\Throwable $e): void
    {
        $account = Account::find($this->accountId);
        if (! $account) {
            return;
        }
        $local = app(LocalFiles::class)->localPathFor($account, $this->path);
        PendingOps::clear($local);
        PathErrors::mark($local, $e?->getMessage() ?? 'Download failed.');
    }
}
