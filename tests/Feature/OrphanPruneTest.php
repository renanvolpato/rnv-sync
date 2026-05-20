<?php

use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;

it('PendingOps::sweepStale drops entries whose local file vanished', function () {
    $alive = sys_get_temp_dir().'/rnv-pending-alive-'.uniqid();
    $dead = sys_get_temp_dir().'/rnv-pending-dead-'.uniqid();
    File::put($alive, 'x');

    PendingOps::mark($alive);
    PendingOps::mark($dead);   // file never created

    expect(PendingOps::sweepStale())->toBe(1)
        ->and(PendingOps::has($alive))->toBeTrue()
        ->and(PendingOps::has($dead))->toBeFalse();

    PendingOps::clear($alive);
    File::delete($alive);
});

it('prune-orphan-folders deactivates folders rclone reports as missing', function () {
    $account = Account::factory()->create(['remote_name' => 'od']);
    $gone = SyncFolder::factory()->create([
        'account_id' => $account->id, 'is_active' => true,
        'remote_path' => 'RenamedAway',
    ]);
    $kept = SyncFolder::factory()->create([
        'account_id' => $account->id, 'is_active' => true,
        'remote_path' => 'StillThere',
    ]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturnUsing(function (array $args) {
            $remote = $args[count($args) - 1] ?? '';

            return str_contains($remote, 'RenamedAway')
                ? new RcloneResult(3, '', "ERROR : RenamedAway: directory not found\n")
                : new RcloneResult(0, '[]', '');
        });

    $this->artisan('rnvsync:prune-orphan-folders')->assertSuccessful();

    expect($gone->fresh()->is_active)->toBeFalse()
        ->and($kept->fresh()->is_active)->toBeTrue();
});

it('prune-orphan-folders NEVER deactivates on transient network errors', function () {
    $account = Account::factory()->create(['remote_name' => 'od']);
    $folder = SyncFolder::factory()->create([
        'account_id' => $account->id, 'is_active' => true,
        'remote_path' => 'Important',
    ]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(1, '', "ERROR : couldn't connect: i/o timeout\n"));

    $this->artisan('rnvsync:prune-orphan-folders')->assertSuccessful();

    expect($folder->fresh()->is_active)->toBeTrue();
});

it('account-card shows the saved quota even when the latest refresh failed', function () {
    $account = Account::factory()->create([
        'name' => 'OD',
        'status' => 'active',
        'quota_total_bytes' => 1_000_000_000,
        'quota_used_bytes' => 500_000_000,
    ]);

    $html = view('components.rnvsync.account-card',
        ['account' => $account, 'quotaOk' => false])->render();

    expect($html)->not->toContain(__('dashboard.quota_unavailable'))
        ->and($html)->toContain('50%');
});
