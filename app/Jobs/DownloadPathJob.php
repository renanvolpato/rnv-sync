<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Services\Files\LocalFiles;
use App\Services\Files\PathErrors;
use App\Services\Files\PendingOps;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Physical mode: download a remote file/folder to disk in the
 * background so the UI never blocks (a folder can be huge).
 */
class DownloadPathJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public int $accountId,
        public string $path,
    ) {}

    public function handle(LocalFiles $files): void
    {
        $account = Account::find($this->accountId);

        if (! $account) {
            return;
        }

        $local = $files->localPathFor($account, $this->path);

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
