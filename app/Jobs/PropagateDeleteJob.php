<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Propagate a LOCAL deletion to OneDrive — to its recycle bin (recoverable),
 * never a hard delete — so a folder/file the user removed does not reappear
 * from the cloud on the next placeholder refresh.
 *
 * The watcher already confirmed the path stayed gone after a debounce and is
 * not one of our own in-flight operations. This job adds a final guard: if the
 * path has reappeared locally by the time it runs, it does NOT touch the cloud.
 * `$path` is relative to the account's base directory.
 */
class PropagateDeleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $accountId,
        public string $path,
        public bool $isDir,
    ) {}

    public function handle(RcloneRunner $rclone, RcloneConfigGenerator $config, LocalFiles $files): void
    {
        $account = Account::find($this->accountId);
        if (! $account || $this->path === '') {
            return;
        }

        // Final safety: if it came back locally (any churn recreated it), keep
        // the cloud copy. Better to leave a stray placeholder than to delete.
        if (file_exists($files->localPathFor($account, $this->path))) {
            return;
        }

        $config->regenerate();
        $remote = $account->remote_name.':'.ltrim($this->path, '/');

        // OneDrive's delete/purge is a SOFT delete (recycle bin) unless
        // --onedrive-hard-delete is passed, which we deliberately do not.
        $verb = $this->isDir ? 'purge' : 'deletefile';
        $result = $rclone->run([$verb, $remote], ['timeout' => 600]);

        Log::channel('rnvsync-app')->info('Propagated local deletion to OneDrive recycle bin', [
            'path' => $this->path,
            'is_dir' => $this->isDir,
            'ok' => $result->successful(),
        ]);

        // A deleted top-level synced folder must stop being tracked, or the
        // discovery/refresh would mirror it back as ☁ placeholders.
        if (! str_contains($this->path, '/')) {
            SyncFolder::where('account_id', $this->accountId)
                ->where('remote_path', $this->path)
                ->update(['is_active' => false]);
        }
    }
}
