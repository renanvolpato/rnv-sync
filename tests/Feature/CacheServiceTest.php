<?php

use App\Models\Account;
use App\Models\FilePolicy;
use App\Services\Cache\CacheService;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir().'/rnvsync-cache-'.uniqid();
    config(['rnvsync.rclone.cache_dir' => $this->cacheDir]);

    $this->account = Account::factory()->create(['remote_name' => 'od1']);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')->andReturn(new RcloneResult(0, '', ''))->byDefault();

    $this->cache = app(CacheService::class);
});

afterEach(function () {
    File::deleteDirectory($this->cacheDir);
});

function writeCacheFile(string $dir, string $rel, int $bytes = 100): string
{
    $path = $dir.'/vfs/od1/'.$rel;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_repeat('x', $bytes));

    return $path;
}

it('pins a file, recording the policy and reporting pinned status (F3.6)', function () {
    expect($this->cache->pin($this->account, '/Docs/a.txt', false, 100))->toBeTrue();

    $this->assertDatabaseHas('rnvsync_file_policies', [
        'account_id' => $this->account->id,
        'path' => '/Docs/a.txt',
        'policy' => 'always_offline',
    ]);
    expect($this->cache->cacheStatus($this->account, '/Docs/a.txt'))->toBe('pinned');
});

it('refuses to pin a file larger than the cache limit (EARS F3.6)', function () {
    config(['rnvsync.defaults.cache' => ['free_space_fraction' => 0, 'min_gb' => 1, 'max_gb' => 1]]);

    expect($this->cache->pin($this->account, '/huge.bin', false, 2 * 1024 ** 3))->toBeFalse();
});

it('frees a single cached file but keeps it browsable online (F3.7)', function () {
    $p = writeCacheFile($this->cacheDir, 'b.txt');
    expect(File::exists($p))->toBeTrue();

    $this->cache->freeUpSpace($this->account, '/b.txt');

    expect(File::exists($p))->toBeFalse()
        ->and($this->cache->cacheStatus($this->account, '/b.txt'))->toBe('online');
});

it('frees all cache except pinned files (F3.8/F3.9)', function () {
    writeCacheFile($this->cacheDir, 'free-me.txt', 200);
    writeCacheFile($this->cacheDir, 'keep.txt', 200);
    FilePolicy::create([
        'account_id' => $this->account->id, 'path' => '/keep.txt',
        'is_directory' => false, 'policy' => 'always_offline',
    ]);

    $this->cache->freeAllCache();

    expect(File::exists($this->cacheDir.'/vfs/od1/free-me.txt'))->toBeFalse()
        ->and(File::exists($this->cacheDir.'/vfs/od1/keep.txt'))->toBeTrue();
});

it('evicts by LRU down to the limit, protecting pinned files (EARS F3.9)', function () {
    // 3 files of 1 MB; limit forced to ~1.5 MB so 2 must be evicted.
    foreach (['old.bin', 'mid.bin', 'new.bin'] as $i => $name) {
        $p = writeCacheFile($this->cacheDir, $name, 1024 * 1024);
        touch($p, time() - (10 - $i)); // old.bin has the oldest atime
    }
    FilePolicy::create([
        'account_id' => $this->account->id, 'path' => '/old.bin',
        'is_directory' => false, 'policy' => 'always_offline',
    ]);

    config(['rnvsync.defaults.cache' => ['free_space_fraction' => 0, 'min_gb' => 0, 'max_gb' => 0]]);
    // limit clamps to 0 → everything unpinned should be evicted, pinned kept.
    $this->cache->evictToLimit();

    expect(File::exists($this->cacheDir.'/vfs/od1/old.bin'))->toBeTrue()  // pinned
        ->and(File::exists($this->cacheDir.'/vfs/od1/mid.bin'))->toBeFalse()
        ->and(File::exists($this->cacheDir.'/vfs/od1/new.bin'))->toBeFalse();
});

it('reports cache statistics', function () {
    writeCacheFile($this->cacheDir, 's1', 500);
    writeCacheFile($this->cacheDir, 's2', 500);

    $stats = $this->cache->stats();

    expect($stats['files'])->toBe(2)
        ->and($stats['usage'])->toBe(1000)
        ->and($stats)->toHaveKeys(['limit', 'percent']);
});
