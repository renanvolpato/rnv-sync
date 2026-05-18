<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\Account;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\File;

/**
 * Physical storage model (no FUSE): real files live on disk under
 * {mount_base}/{account}. Items not downloaded are represented by
 * 0-byte placeholder files so they still appear in the file manager
 * with a cloud emblem (set by the Nautilus extension). Downloading
 * replaces the placeholder with the real file.
 */
class LocalFiles
{
    public function __construct(
        private readonly RcloneRunner $rclone,
        private readonly RcloneConfigGenerator $configGenerator,
        private readonly SettingsRepository $settings,
    ) {}

    public function baseDir(Account $account): string
    {
        return rtrim($this->settings->mountBase(), '/').'/'.$account->name;
    }

    public function localPathFor(Account $account, string $path): string
    {
        return $this->baseDir($account).'/'.ltrim($path, '/');
    }

    /** syncing (op in flight) → downloaded (real file) → cloud-only. */
    public function status(Account $account, string $path): string
    {
        $local = $this->localPathFor($account, $path);

        if (PendingOps::has($local)) {
            return 'syncing';
        }

        if (is_dir($local)) {
            // A folder is "on this device" only if it actually holds
            // real files; a tree of 0-byte placeholders is cloud-only.
            foreach (File::allFiles($local) as $f) {
                if ($f->getSize() > 0) {
                    return 'downloaded';
                }
            }

            return 'cloud';
        }

        if (is_file($local) && filesize($local) > 0) {
            return 'downloaded';
        }

        return 'cloud';
    }

    /**
     * Download a remote file/folder to its real on-disk location,
     * replacing any placeholder.
     */
    public function download(Account $account, string $path): void
    {
        $this->configGenerator->regenerate();

        $remote = $account->remote_name.':'.ltrim($path, '/');
        $local = $this->localPathFor($account, $path);

        File::ensureDirectoryExists(dirname($local));

        // copyto handles a single file; copy handles a directory tree.
        $isDir = $this->remoteIsDir($account, $path);
        $verb = $isDir ? 'copy' : 'copyto';

        $this->rclone->run([$verb, $remote, $local], ['timeout' => 3600]);
    }

    /**
     * "Free up space": delete the local copy but leave a 0-byte
     * placeholder so the item still shows (as cloud) in the file
     * manager. The file stays in OneDrive.
     */
    public function free(Account $account, string $path): void
    {
        $local = $this->localPathFor($account, $path);

        if (is_dir($local)) {
            File::deleteDirectory($local);
            File::ensureDirectoryExists($local);

            return;
        }

        if (is_file($local)) {
            File::delete($local);
            File::ensureDirectoryExists(dirname($local));
            File::put($local, ''); // cloud placeholder (0 bytes)
        }
    }

    /**
     * Mirror a remote folder as a local tree of placeholders so cloud
     * items are visible in the file manager (☁). Existing real files
     * are kept. Returns the number of placeholders created.
     */
    public function materializeCloudPlaceholders(Account $account, string $path = ''): int
    {
        $this->configGenerator->regenerate();

        $remote = $account->remote_name.':'.ltrim($path, '/');
        // Big OneDrives can take minutes to enumerate recursively; this
        // runs in the queue (job timeout 1800s), so don't cap at 120s.
        $result = $this->rclone->run(['lsjson', '-R', '--files-only=false', $remote], ['timeout' => 1700]);

        if (! $result->successful()) {
            return 0;
        }

        $created = 0;
        foreach ($result->json() ?? [] as $entry) {
            $rel = trim(($path ? $path.'/' : '').($entry['Path'] ?? ''), '/');
            $local = $this->localPathFor($account, $rel);

            if (($entry['IsDir'] ?? false) === true) {
                File::ensureDirectoryExists($local);

                continue;
            }

            if (! file_exists($local)) {
                File::ensureDirectoryExists(dirname($local));
                File::put($local, ''); // 0-byte → cloud emblem
                $created++;
            }
        }

        return $created;
    }

    private function remoteIsDir(Account $account, string $path): bool
    {
        if ($path === '' || $path === '/') {
            return true;
        }

        $parent = trim(dirname($path), '/.');
        $remote = $account->remote_name.':'.$parent;
        $result = $this->rclone->run(['lsjson', $remote], ['timeout' => 60]);

        foreach ($result->json() ?? [] as $entry) {
            if (($entry['Name'] ?? null) === basename($path)) {
                return (bool) ($entry['IsDir'] ?? false);
            }
        }

        return false;
    }
}
