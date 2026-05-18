<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\Account;
use App\Models\FilePolicy;
use App\Services\Mount\MountService;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;

/**
 * rclone VFS cache management (SPEC F3.3–F3.9).
 *
 * The full VFS cache lives under {cache_dir}/vfs/{remote}/{path}. Pinned
 * paths (FilePolicy::always_offline) are protected from LRU eviction.
 */
class CacheService
{
    public function __construct(
        private readonly MountService $mounts,
        private readonly RcloneRunner $rclone,
    ) {}

    private function vfsRoot(Account $account): string
    {
        return rtrim((string) config('rnvsync.rclone.cache_dir'), '/').'/vfs/'.$account->remote_name;
    }

    public function cachePathFor(Account $account, string $path): string
    {
        return $this->vfsRoot($account).'/'.ltrim($path, '/');
    }

    public function usageBytes(): int
    {
        $dir = (string) config('rnvsync.rclone.cache_dir');

        if (! is_dir($dir)) {
            return 0;
        }

        $total = 0;
        foreach (File::allFiles($dir) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }

    public function limitBytes(): int
    {
        return $this->mounts->cacheLimitBytes();
    }

    /**
     * @return array{usage:int,limit:int,files:int,percent:float}
     */
    public function stats(): array
    {
        $dir = (string) config('rnvsync.rclone.cache_dir');
        $files = is_dir($dir) ? count(File::allFiles($dir)) : 0;
        $usage = $this->usageBytes();
        $limit = $this->limitBytes();

        return [
            'usage' => $usage,
            'limit' => $limit,
            'files' => $files,
            'percent' => $limit > 0 ? round($usage / $limit * 100, 1) : 0.0,
        ];
    }

    public function isPinned(Account $account, string $path): bool
    {
        return FilePolicy::where('account_id', $account->id)
            ->where('path', $path)
            ->where('policy', 'always_offline')
            ->exists();
    }

    public function cacheStatus(Account $account, string $path): string
    {
        if ($this->isPinned($account, $path)) {
            return 'pinned';
        }

        return File::exists($this->cachePathFor($account, $path)) ? 'cached' : 'online';
    }

    /**
     * SPEC F3.6 EARS: download immediately and add to the protected pin
     * list. Returns false if the file is larger than the cache limit
     * (SPEC F3.6 EARS: warn and offer to increase the limit).
     */
    public function pin(Account $account, string $path, bool $isDir, int $sizeBytes = 0): bool
    {
        if (! $isDir && $sizeBytes > $this->limitBytes()) {
            return false;
        }

        // Record the policy immediately (fast). The actual download is
        // done in the background by WarmCacheJob so the UI never blocks
        // (a pinned folder can be huge).
        FilePolicy::updateOrCreate(
            ['account_id' => $account->id, 'path' => $path],
            ['is_directory' => $isDir, 'policy' => 'always_offline'],
        );

        return true;
    }

    /**
     * Download a pinned path into the VFS cache. Runs inside the queue
     * worker (WarmCacheJob), never in a web request.
     */
    public function warm(Account $account, string $path): void
    {
        $this->rclone->run(
            ['copy', $account->remote_name.':'.ltrim($path, '/'), $this->cachePathFor($account, $path)],
            ['timeout' => 3600],
        );
    }

    public function unpin(Account $account, string $path): void
    {
        FilePolicy::where('account_id', $account->id)
            ->where('path', $path)
            ->update(['policy' => 'default']);
    }

    /** SPEC F3.7: evict from cache, keeping the placeholder (online file). */
    public function freeUpSpace(Account $account, string $path): void
    {
        $target = $this->cachePathFor($account, $path);

        File::isDirectory($target)
            ? File::deleteDirectory($target)
            : File::delete($target);
    }

    /** SPEC F3.8: free all cache except pinned paths. */
    public function freeAllCache(): int
    {
        $freed = 0;

        foreach (Account::all() as $account) {
            $root = $this->vfsRoot($account);
            if (! is_dir($root)) {
                continue;
            }

            $pinned = FilePolicy::where('account_id', $account->id)
                ->where('policy', 'always_offline')
                ->pluck('path')
                ->map(fn ($p) => $this->cachePathFor($account, $p))
                ->all();

            foreach (File::allFiles($root) as $file) {
                if ($this->isProtected($file->getPathname(), $pinned)) {
                    continue;
                }
                $freed += $file->getSize();
                File::delete($file->getPathname());
            }
        }

        return $freed;
    }

    /**
     * SPEC F3.9 / EARS: when cache exceeds the limit, evict by LRU
     * (oldest access time first), excluding pinned files.
     */
    public function evictToLimit(): int
    {
        if ($this->usageBytes() <= $this->limitBytes()) {
            return 0;
        }

        $pinned = [];
        foreach (Account::all() as $account) {
            foreach (FilePolicy::where('account_id', $account->id)
                ->where('policy', 'always_offline')->pluck('path') as $p) {
                $pinned[] = $this->cachePathFor($account, $p);
            }
        }

        $dir = (string) config('rnvsync.rclone.cache_dir');
        $files = collect(File::allFiles($dir))
            ->reject(fn ($f) => $this->isProtected($f->getPathname(), $pinned))
            ->sortBy(fn ($f) => fileatime($f->getPathname()) ?: $f->getMTime())
            ->values();

        $freed = 0;
        $limit = $this->limitBytes();
        $usage = $this->usageBytes();

        foreach ($files as $file) {
            if ($usage <= $limit) {
                break;
            }
            $size = $file->getSize();
            File::delete($file->getPathname());
            $usage -= $size;
            $freed += $size;
        }

        return $freed;
    }

    /** @param list<string> $protected */
    private function isProtected(string $path, array $protected): bool
    {
        foreach ($protected as $p) {
            if ($path === $p || str_starts_with($path, rtrim($p, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
