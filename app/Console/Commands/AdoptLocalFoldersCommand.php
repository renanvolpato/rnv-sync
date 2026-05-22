<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Picks up folders the user created LOCALLY inside the sync root that
 * aren't tracked yet, and starts syncing them up to the cloud.
 *
 * The folder-selection screen only mirrors CLOUD folders downward, so
 * a brand-new local folder (e.g. dropped into ~/RnvSync/OneDrive)
 * would otherwise never reach OneDrive — matching the universal
 * "anything in the sync folder uploads" expectation.
 *
 * Conservative: only adopts a top-level directory that actually holds
 * real files (size > 0), so empty/placeholder shells are ignored.
 */
class AdoptLocalFoldersCommand extends Command
{
    protected $signature = 'rnvsync:adopt-local-folders';

    protected $description = 'Start syncing locally-created folders in the sync root up to the cloud';

    // A brand-new folder at or below this many files is pushed up INLINE
    // (right here, in the scheduler) so it reaches the cloud within this
    // 5-min tick instead of waiting behind a huge folder in the queue.
    // Larger drops are queued so they never block the scheduler loop.
    private const INLINE_LIMIT = 2000;

    public function handle(SettingsRepository $settings, LocalFiles $files, RcloneRunner $rclone, RcloneConfigGenerator $config): int
    {
        $adopted = 0;

        foreach (Account::all() as $account) {
            $root = rtrim($settings->mountBase(), '/').'/'.$account->name;
            if (! is_dir($root)) {
                continue;
            }

            $active = SyncFolder::where('account_id', $account->id)
                ->where('is_active', true)->pluck('remote_path')->all();

            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                $path = $root.'/'.$entry;
                if (! is_dir($path) || in_array($entry, $active, true)) {
                    continue;
                }
                // Only adopt folders that hold real (downloaded/created)
                // files — never placeholder-only shells.
                if (! $files->hasAnyRealFile($path)) {
                    continue;
                }

                $folder = SyncFolder::updateOrCreate(
                    ['account_id' => $account->id, 'remote_path' => $entry],
                    ['local_path' => $path, 'sync_mode' => 'on_demand', 'is_active' => true],
                );

                // Push it up NOW if small enough to do inline without
                // holding up the scheduler; otherwise queue the bulk
                // upload. Either way it no longer waits behind a huge
                // folder monopolising the single worker.
                if ($this->isSmall($path)) {
                    (new SyncChangesJob($folder->id))->handle($rclone, $config, $files);
                } else {
                    SyncChangesJob::dispatch($folder->id);
                }

                $this->info("Adopted local folder: {$entry}");
                $adopted++;
            }
        }

        $this->info("Done. {$adopted} folder(s) adopted.");

        return self::SUCCESS;
    }

    /**
     * Cheap, bounded "is this folder small?" probe: walks lazily and
     * early-exits the moment it passes INLINE_LIMIT files, so a 70k-file
     * drop costs ~2000 iterations, not a full scan.
     */
    private function isSmall(string $path): bool
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $n = 0;
        foreach ($it as $f) {
            if ($f->isFile() && ++$n > self::INLINE_LIMIT) {
                return false;
            }
        }

        return true;
    }
}
