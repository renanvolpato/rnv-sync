<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DownloadPathJob;
use App\Jobs\FreeOnlineJob;
use App\Models\Account;
use App\Services\Files\LocalFiles;
use App\Services\Files\PendingOps;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Bridge for the Nautilus extension: maps an absolute local path back
 * to its account + relative path and downloads or frees it.
 *
 *   php artisan rnvsync:fs download /home/user/RnvSync/OneDrive/Docs
 *   php artisan rnvsync:fs free     /home/user/RnvSync/OneDrive/Docs/a.pdf
 */
class FsActionCommand extends Command
{
    protected $signature = 'rnvsync:fs {action : download|free} {path : absolute local path}';

    protected $description = 'Download or free a path (used by the file-manager extension)';

    public function handle(SettingsRepository $settings, LocalFiles $files): int
    {
        $action = $this->argument('action');
        $abs = rtrim($this->argument('path'), '/');

        foreach (Account::all() as $account) {
            $base = rtrim($settings->mountBase(), '/').'/'.$account->name;

            if ($abs === $base || str_starts_with($abs, $base.'/')) {
                $rel = ltrim(substr($abs, strlen($base)), '/');

                if ($action === 'download') {
                    PendingOps::mark($abs); // show "syncing"
                    DownloadPathJob::dispatch($account->id, $rel);
                } elseif ($action === 'free') {
                    PendingOps::mark($abs); // upload-if-needed then drop
                    FreeOnlineJob::dispatch($account->id, $rel);
                } else {
                    $this->error("Unknown action: {$action}");

                    return self::FAILURE;
                }

                $this->info(ucfirst($action)." queued for: {$rel}");

                return self::SUCCESS;
            }
        }

        $this->error('Path is not inside any RNV Sync account folder.');

        return self::FAILURE;
    }
}
