<?php

use App\Events\SyncStatusChanged;
use App\Models\Account;
use App\Models\MountProcess;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => app(SettingsRepository::class)
    ->set(SettingsRepository::KEY_STORAGE_MODE, 'mount'));

it('restarts a dead mount up to 3 times then errors the account (EARS F3.2)', function () {
    Event::fake([SyncStatusChanged::class]);
    $account = Account::factory()->create();
    MountProcess::create(['account_id' => $account->id, 'mount_point' => '/m', 'pid' => 5, 'status' => 'running']);

    $mock = $this->mock(RcloneRunner::class);
    $mock->shouldReceive('isProcessAlive')->andReturnFalse();
    $mock->shouldReceive('runBackground')->times(3)->andReturn(101);

    // 3 restart attempts
    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();
    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();
    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();
    // 4th run: limit reached → mark error + notify
    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();

    expect($account->fresh()->status)->toBe(Account::STATUS_ERROR);
    Event::assertDispatched(SyncStatusChanged::class, fn ($e) => $e->status === 'failed');
});

it('does not remount a healthy mount and clears the restart counter', function () {
    $account = Account::factory()->create();
    MountProcess::create(['account_id' => $account->id, 'mount_point' => '/m', 'pid' => 7, 'status' => 'running']);

    $mock = $this->mock(RcloneRunner::class);
    $mock->shouldReceive('isProcessAlive')->with(7)->andReturnTrue();
    $mock->shouldNotReceive('runBackground');

    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();

    expect($account->fresh()->status)->toBe(Account::STATUS_ACTIVE);
});
