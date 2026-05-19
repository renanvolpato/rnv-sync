<?php

use App\Services\Update\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

beforeEach(fn () => Cache::flush());

it('reports up to date when not behind the remote', function () {
    Process::fake([
        'git fetch --quiet' => Process::result(''),
        'git rev-parse --short HEAD' => Process::result("abc1234\n"),
        'git rev-list --count HEAD..@{u}' => Process::result("0\n"),
        'git rev-parse --short @{u}' => Process::result("abc1234\n"),
    ]);

    $s = (new UpdateService(base_path()))->checkForUpdates(force: true);

    expect($s['available'])->toBeFalse()
        ->and($s['behind'])->toBe(0)
        ->and($s['error'])->toBeNull()
        ->and($s['commits'])->toBe([]);
});

it('detects available updates and lists the commits', function () {
    Process::fake([
        'git fetch --quiet' => Process::result(''),
        'git rev-parse --short HEAD' => Process::result("aaaaaaa\n"),
        'git rev-list --count HEAD..@{u}' => Process::result("2\n"),
        'git rev-parse --short @{u}' => Process::result("bbbbbbb\n"),
        'git log --no-merges --format=%s HEAD..@{u}' => Process::result("fix: thing\nfeat: other\n"),
    ]);

    $s = (new UpdateService(base_path()))->checkForUpdates(force: true);

    expect($s['available'])->toBeTrue()
        ->and($s['behind'])->toBe(2)
        ->and($s['latest'])->toBe('bbbbbbb')
        ->and($s['commits'])->toBe(['fix: thing', 'feat: other']);
});

it('flags a non-git install instead of failing', function () {
    $dir = sys_get_temp_dir().'/rnv-nogit-'.uniqid();
    mkdir($dir);

    $s = (new UpdateService($dir))->checkForUpdates(force: true);

    expect($s['error'])->toBe('not_git')
        ->and($s['available'])->toBeFalse();

    rmdir($dir);
});

it('builds a detached, login-shell update command', function () {
    $cmd = (new UpdateService('/opt/rnv-sync'))->updateCommand();

    expect($cmd)->toStartWith('setsid bash -lc ')
        ->toContain('/opt/rnv-sync/install/update.sh')
        ->toContain('/opt/rnv-sync/storage/logs/update.log');
});

it('caches the status so render never hits the network', function () {
    expect((new UpdateService(base_path()))->cachedStatus())->toBeNull();

    Process::fake([
        'git fetch --quiet' => Process::result(''),
        'git rev-parse --short HEAD' => Process::result("abc1234\n"),
        'git rev-list --count HEAD..@{u}' => Process::result("0\n"),
        'git rev-parse --short @{u}' => Process::result("abc1234\n"),
    ]);
    (new UpdateService(base_path()))->checkForUpdates(force: true);

    expect((new UpdateService(base_path()))->cachedStatus())->not->toBeNull();
});

it('the scheduled command caches an up-to-date status', function () {
    Cache::flush();
    Process::fake([
        'git fetch --quiet' => Process::result(''),
        'git rev-parse --short HEAD' => Process::result("abc1234\n"),
        'git rev-list --count HEAD..@{u}' => Process::result("0\n"),
        'git rev-parse --short @{u}' => Process::result("abc1234\n"),
    ]);

    $this->artisan('rnvsync:check-updates')->assertSuccessful();

    expect(app(UpdateService::class)->cachedStatus())->not->toBeNull();
});
