<?php

use App\Jobs\DownloadPathJob;
use App\Jobs\FreeOnlineJob;
use App\Models\Account;
use App\Services\Files\DiskGuard;
use App\Services\Files\LocalFiles;
use App\Services\Files\PathErrors;
use App\Services\Files\QueuedFileOps;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Land jobs in the `jobs` table (not run inline) so we can inspect/purge them.
    config(['queue.default' => 'database']);
    $this->account = Account::factory()->create();
});

it('switching a path online drops the downloads queued under it (and leaves the rest)', function () {
    DownloadPathJob::dispatch($this->account->id, 'Big/sub/a.txt');
    DownloadPathJob::dispatch($this->account->id, 'Big/b.txt');
    DownloadPathJob::dispatch($this->account->id, 'Other/c.txt');
    FreeOnlineJob::dispatch($this->account->id, 'Big/x.txt'); // a free must NOT be touched

    expect(DB::table('jobs')->count())->toBe(4);

    $removed = QueuedFileOps::cancelDownloadsUnder($this->account->id, 'Big');

    expect($removed)->toBe(2)                       // Big/sub/a.txt + Big/b.txt
        ->and(DB::table('jobs')->count())->toBe(2); // Other download + the Big free remain
});

it('keeping a path local drops the frees queued under it', function () {
    FreeOnlineJob::dispatch($this->account->id, 'Docs/a.txt');
    FreeOnlineJob::dispatch($this->account->id, 'Other/b.txt');
    DownloadPathJob::dispatch($this->account->id, 'Docs/keep.txt'); // a download must NOT be touched

    $removed = QueuedFileOps::cancelFreesUnder($this->account->id, 'Docs');

    expect($removed)->toBe(1)                       // only Docs/a.txt
        ->and(DB::table('jobs')->count())->toBe(2); // Other free + Docs download remain
});

it('purge never crosses accounts', function () {
    $other = Account::factory()->create();
    DownloadPathJob::dispatch($this->account->id, 'Big/a.txt');
    DownloadPathJob::dispatch($other->id, 'Big/a.txt');

    expect(QueuedFileOps::cancelDownloadsUnder($this->account->id, 'Big'))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1); // the other account's job survives
});

it('disk guard is disabled at 100% and blocks once usage passes the threshold', function () {
    $used = DiskGuard::usedPercent(sys_get_temp_dir());
    expect($used)->toBeGreaterThanOrEqual(0.0);

    config(['rnvsync.sync.download_max_disk_percent' => 100]);
    expect(DiskGuard::hasRoom(sys_get_temp_dir()))->toBeTrue(); // disabled

    if ($used > 1.0) {
        config(['rnvsync.sync.download_max_disk_percent' => $used - 1.0]);
        expect(DiskGuard::hasRoom(sys_get_temp_dir()))->toBeFalse(); // past threshold
    }
});

it('the download job skips when sync is paused and flags a paused error', function () {
    app(SyncService::class)->setPaused(true);

    $target = sys_get_temp_dir().'/rnv-pause-'.uniqid().'.bin';
    $files = $this->mock(LocalFiles::class);
    $files->shouldReceive('localPathFor')->andReturn($target);
    $files->shouldNotReceive('download'); // pause must prevent the download

    (new DownloadPathJob($this->account->id, 'Big/file.bin'))->handle($files);

    expect(PathErrors::has($target))->toBeTrue();
    PathErrors::clear($target);
    app(SyncService::class)->setPaused(false);
});

it('the download job skips and flags an error when the disk is past the fill threshold', function () {
    $used = DiskGuard::usedPercent(sys_get_temp_dir());
    config(['rnvsync.sync.download_max_disk_percent' => max(0.001, $used / 2)]); // guaranteed below usage
    $target = sys_get_temp_dir().'/rnv-diskguard-'.uniqid().'.bin';

    $files = $this->mock(LocalFiles::class);
    $files->shouldReceive('localPathFor')->andReturn($target);
    $files->shouldNotReceive('download'); // the guard must prevent the actual download

    (new DownloadPathJob($this->account->id, 'Big/file.bin'))->handle($files);

    expect(PathErrors::has($target))->toBeTrue();
    PathErrors::clear($target);
});
