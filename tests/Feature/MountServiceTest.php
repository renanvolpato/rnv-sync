<?php

use App\Models\Account;
use App\Models\MountProcess;
use App\Services\Mount\MountService;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;

it('builds rclone mount args with the spec default flags', function () {
    $account = Account::factory()->create(['name' => 'My OneDrive', 'remote_name' => 'od1']);

    $args = app(MountService::class)->buildMountArgs($account);

    expect($args)->toContain('mount')
        ->toContain('od1:')
        ->toContain('--vfs-cache-mode=full')
        ->toContain('--allow-non-empty')
        ->and(collect($args)->contains(fn ($a) => str_starts_with($a, '--vfs-cache-max-size=')))->toBeTrue();
});

it('computes the cache limit with override and clamps the automatic value', function () {
    $svc = app(MountService::class);
    $gb = 1024 ** 3;

    app(SettingsRepository::class)->set('cache_max_gb', 5);
    expect($svc->cacheLimitBytes())->toBe(5 * $gb);

    app(SettingsRepository::class)->set('cache_max_gb', null);
    $auto = $svc->cacheLimitBytes();
    expect($auto)->toBeGreaterThanOrEqual(1 * $gb)
        ->and($auto)->toBeLessThanOrEqual(20 * $gb);
});

it('mounts an account and tracks the PID (SPEC F3.1)', function () {
    $account = Account::factory()->create();

    $this->mock(RcloneRunner::class)
        ->shouldReceive('runBackground')->andReturn(4242);

    $mp = app(MountService::class)->mount($account);

    expect($mp->pid)->toBe(4242)
        ->and($mp->status)->toBe('running');
    $this->assertDatabaseHas('rnvsync_mount_processes', [
        'account_id' => $account->id, 'pid' => 4242, 'status' => 'running',
    ]);
});

it('unmounts by killing the tracked process', function () {
    $account = Account::factory()->create();
    MountProcess::create([
        'account_id' => $account->id, 'mount_point' => '/x',
        'pid' => 999, 'status' => 'running',
    ]);

    $mock = $this->mock(RcloneRunner::class);
    $mock->shouldReceive('killProcess')->with(999)->once()->andReturnTrue();

    app(MountService::class)->unmount($account);

    expect(MountProcess::where('account_id', $account->id)->first()->status)->toBe('stopped');
});

it('mounts only active accounts that are not already healthy', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();
    MountProcess::create(['account_id' => $b->id, 'mount_point' => '/b', 'pid' => 10, 'status' => 'running']);

    $mock = $this->mock(RcloneRunner::class);
    $mock->shouldReceive('isProcessAlive')->with(10)->andReturnTrue();
    $mock->shouldReceive('runBackground')->once()->andReturn(77); // only $a

    expect(app(MountService::class)->mountAllActive())->toBe(1);
});
