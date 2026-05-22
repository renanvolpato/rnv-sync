<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\Account;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
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

    /** syncing → error → downloaded (real file) → cloud-only. */
    public function status(Account $account, string $path): string
    {
        $local = $this->localPathFor($account, $path);

        if (PendingOps::has($local)) {
            return 'syncing';
        }

        if (PathErrors::has($local)) {
            return 'error';
        }

        if (is_dir($local)) {
            // A folder is "on this device" only if it actually holds
            // real files; a tree of 0-byte placeholders is cloud-only.
            // The walk is cached briefly so the ~5s wire:poll doesn't
            // re-scan large placeholder trees on every tick. Anything in
            // flight already returned 'syncing'/'error' above, so a few
            // seconds of cloud↔downloaded staleness is invisible.
            return Cache::remember(
                'rnvsync.dirstatus.'.md5($local),
                10,
                fn (): string => $this->treeHasRealFile($local) ? 'downloaded' : 'cloud',
            );
        }

        if (is_file($local) && filesize($local) > 0) {
            return 'downloaded';
        }

        return 'cloud';
    }

    /** Last failure message for a path (for the UI tooltip), if any. */
    public function errorFor(Account $account, string $path): ?string
    {
        return PathErrors::get($this->localPathFor($account, $path));
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

        // For a FOLDER, parallelise its files. (Benchmarked: OneDrive
        // Personal gains nothing from --multi-thread-streams on single
        // files — sometimes slower — so we don't use it. Single-file
        // download speed is bounded by Microsoft's consumer throttle.)
        $perf = $isDir ? ['--transfers=4', '--checkers=8', '--tpslimit=12'] : [];

        $result = $this->rclone->run([$verb, $remote, $local, ...$perf], ['timeout' => 3600]);

        // Must throw on failure: the job relies on the exception to keep
        // the ⟳ state, retry, and finally surface an explicit Erro
        // instead of silently reverting to ☁ with the file missing.
        if (! $result->successful()) {
            throw new \RuntimeException(
                'rclone download failed: '.trim($result->stderr) ?: 'unknown error'
            );
        }
    }

    /**
     * Cheap "is there anything actually downloaded here?" probe.
     * Walks the tree and early-exits on the first file with size > 0.
     * Used to skip wasted scheduled syncs of placeholder-only folders.
     */
    public function hasAnyRealFile(string $absPath): bool
    {
        return $this->treeHasRealFile($absPath);
    }

    /**
     * True if the tree under $absPath holds at least one real (size > 0)
     * file. Walks lazily and early-exits on the first hit.
     *
     * Deliberately NOT File::allFiles() (Symfony Finder): Finder eagerly
     * collects every SplFileInfo in the whole tree AND sorts them before
     * yielding anything, so on a big placeholder-only folder it spends
     * 30s in SortableIterator and the request 500s — and the intended
     * early-exit never happens. RecursiveDirectoryIterator descends
     * depth-first and stops the instant we find a real file.
     */
    private function treeHasRealFile(string $absPath): bool
    {
        if (! is_dir($absPath)) {
            return false;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $absPath,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && $f->getSize() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the on-disk shell of a folder the user just unchecked, but
     * ONLY when it holds nothing the user might miss — i.e. no file
     * with size > 0. Cloud placeholders (0-byte) and empty subdirs go;
     * any real file aborts and keeps the whole tree intact.
     */
    public function tryRemoveEmptyShell(string $absPath): bool
    {
        if (! is_dir($absPath)) {
            return false;
        }
        if ($this->treeHasRealFile($absPath)) {
            return false; // keep the tree — user's real data lives here
        }
        File::deleteDirectory($absPath);

        return true;
    }

    /**
     * "Keep online": make sure the content is safely in OneDrive, then
     * drop the local copy (leaving a 0-byte placeholder).
     *
     * Data-safety: a real local file/folder that the user created or
     * edited is UPLOADED first. A 0-byte file is treated as our
     * placeholder and never uploaded (that would overwrite the real
     * cloud file with nothing).
     */
    public function free(Account $account, string $path): void
    {
        $this->configGenerator->regenerate();

        $local = $this->localPathFor($account, $path);
        $remote = $account->remote_name.':'.ltrim($path, '/');

        if (is_dir($local)) {
            // Upload any real files in the tree, then placeholder it.
            if ($this->treeHasRealFile($local)) {
                $result = $this->rclone->run(['copy', $local, $remote, '--ignore-size', '--checksum'], ['timeout' => 3600]);
                // Data-safety: never drop the local tree if the upload
                // failed — that would lose the user's files. Throw so
                // the job retries / surfaces an Erro instead.
                if (! $result->successful()) {
                    throw new \RuntimeException(
                        'rclone upload failed (folder kept locally): '.(trim($result->stderr) ?: 'unknown error')
                    );
                }
            }
            File::deleteDirectory($local);
            File::ensureDirectoryExists($local);

            return;
        }

        if (is_file($local)) {
            // size 0 = our placeholder/empty → don't overwrite cloud.
            if (filesize($local) > 0) {
                $result = $this->rclone->run(['copyto', $local, $remote], ['timeout' => 3600]);
                // Data-safety: keep the local file if the upload failed.
                if (! $result->successful()) {
                    throw new \RuntimeException(
                        'rclone upload failed (file kept locally): '.(trim($result->stderr) ?: 'unknown error')
                    );
                }
            }
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
        // Skip the Personal Vault/Trash: rclone can't traverse the
        // Vault and would abort the whole listing (→ 0 placeholders).
        $result = $this->rclone->run([
            'lsjson', '-R', '--fast-list', '--files-only=false', $remote,
            '--ignore-errors',
            '--exclude', 'Cofre Pessoal/**', '--exclude', 'Personal Vault/**',
            '--exclude', '.Trash-1000/**',
        ], ['timeout' => 1700]);

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

    /**
     * Lightweight: ensure placeholders for entries the caller already
     * listed (reuses the browse listing — no extra rclone call). Fills
     * the tree cheaply as the user navigates, instead of one heavy
     * recursive scan.
     *
     * @param  list<array{path:string,is_dir:bool}>  $entries
     */
    public function ensurePlaceholders(Account $account, array $entries): void
    {
        foreach ($entries as $e) {
            $local = $this->localPathFor($account, $e['path']);

            // Don't drop a placeholder over a file that's being
            // downloaded right now (would race the rclone copy).
            if (PendingOps::has($local)) {
                continue;
            }

            if ($e['is_dir']) {
                File::ensureDirectoryExists($local);
            } elseif (! file_exists($local)) {
                File::ensureDirectoryExists(dirname($local));
                File::put($local, ''); // cloud placeholder
            }
        }
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
