<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\Account;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
            // Turn the real files into 0-byte ☁ placeholders IN PLACE — do NOT
            // delete the tree. Deleting it would (a) drop the folder from the
            // file manager and (b) look like a user deletion to the watcher,
            // which (with delete-propagation on) would purge the copies we just
            // uploaded. Truncating keeps every entry present, so the watcher
            // sees them as still there and never propagates a deletion.
            $this->placeholderizeTree($local);

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
     * Truncate every real (size > 0) file under $absPath to 0 bytes, turning
     * each into a ☁ placeholder while keeping the tree intact (no deletes).
     * Used by "keep online" on a folder so its files stay visible as cloud
     * items and the watcher never mistakes the change for a deletion.
     */
    private function placeholderizeTree(string $absPath): void
    {
        if (! is_dir($absPath)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $absPath,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($it as $f) {
            if ($f->isFile() && $f->getSize() > 0) {
                @file_put_contents($f->getPathname(), '');
            }
        }
    }

    /** Per-subtree recursive-listing timeout (s). A subtree that can't be
     *  enumerated within this is SHARDED into its children rather than failing
     *  the whole folder (huge folders used to time out and surface nothing). */
    private const MATERIALIZE_TIMEOUT = 600;

    /** Shallow (one-level) listing timeout — cheap even on a huge folder. */
    private const SHARD_LIST_TIMEOUT = 300;

    /** How deep we keep splitting a too-large subtree before giving up. The
     *  rest still surfaces lazily when the user browses into it. */
    private const MAX_SHARD_DEPTH = 3;

    /**
     * Mirror a remote folder as a local tree of placeholders so cloud
     * items are visible in the file manager (☁). Existing real files
     * are kept. Returns the number of placeholders created.
     */
    public function materializeCloudPlaceholders(Account $account, string $path = ''): int
    {
        $this->configGenerator->regenerate();

        return $this->materializeSubtree($account, $path, 0);
    }

    /**
     * List a remote subtree recursively and create 0-byte placeholders. If the
     * one-shot recursive listing is too slow (a giant folder like an 80k-file
     * OneDrive), it SHARDS: list only the immediate children and recurse into
     * each subdir, so only the heavy branch is split and the rest still
     * completes — instead of the whole folder timing out and surfacing nothing.
     */
    private function materializeSubtree(Account $account, string $path, int $depth): int
    {
        $remote = $account->remote_name.':'.ltrim($path, '/');
        $bigKey = 'rnv-bigfolder-'.md5($remote);

        // A folder we already learned is too big to list in one go: skip the
        // doomed whole-subtree attempt and shard straight away.
        if ($depth === 0 && Cache::has($bigKey)) {
            return $this->shardAndRecurse($account, $path, $depth);
        }

        try {
            // Skip the Personal Vault/Trash: rclone can't traverse the Vault
            // and would abort the whole listing (→ 0 placeholders).
            $result = $this->rclone->run([
                'lsjson', '-R', '--fast-list', '--files-only=false', $remote,
                '--ignore-errors',
                '--exclude', 'Cofre Pessoal/**', '--exclude', 'Personal Vault/**',
                '--exclude', '.Trash-1000/**',
            ], ['timeout' => self::MATERIALIZE_TIMEOUT]);
        } catch (\Throwable $e) {
            // Enumerating this subtree took longer than the timeout. Split it.
            if ($depth >= self::MAX_SHARD_DEPTH) {
                Log::warning("materialize: giving up on oversized subtree {$remote} (depth {$depth})");

                return 0;
            }
            if ($depth === 0) {
                Cache::put($bigKey, 1, now()->addDays(7));
            }

            return $this->shardAndRecurse($account, $path, $depth);
        }

        if (! $result->successful()) {
            return 0;
        }

        return $this->createPlaceholdersFrom($account, $path, $result->json() ?? []);
    }

    /**
     * List only the IMMEDIATE children of $path (cheap even on a huge folder)
     * and recurse into each subdir, splitting the heavy work across many small
     * listings instead of one that times out.
     */
    private function shardAndRecurse(Account $account, string $path, int $depth): int
    {
        $remote = $account->remote_name.':'.ltrim($path, '/');

        $top = $this->rclone->run([
            'lsjson', '--files-only=false', $remote,
            '--exclude', 'Cofre Pessoal/**', '--exclude', 'Personal Vault/**',
            '--exclude', '.Trash-1000/**',
        ], ['timeout' => self::SHARD_LIST_TIMEOUT]);

        if (! $top->successful()) {
            return 0;
        }

        $created = 0;
        foreach ($top->json() ?? [] as $entry) {
            $name = $entry['Path'] ?? $entry['Name'] ?? '';
            if ($name === '') {
                continue;
            }
            $childRel = trim(($path !== '' ? $path.'/' : '').$name, '/');
            $local = $this->localPathFor($account, $childRel);

            if (($entry['IsDir'] ?? false) === true) {
                File::ensureDirectoryExists($local);
                try {
                    $created += $this->materializeSubtree($account, $childRel, $depth + 1);
                } catch (\Throwable $e) {
                    Log::warning("materialize: skipped subtree {$childRel}: ".$e->getMessage());
                }

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
     * Create dirs + 0-byte placeholders from a recursive listing whose entry
     * Paths are relative to $path.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function createPlaceholdersFrom(Account $account, string $path, array $entries): int
    {
        $created = 0;
        foreach ($entries as $entry) {
            $rel = trim(($path !== '' ? $path.'/' : '').($entry['Path'] ?? ''), '/');
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
