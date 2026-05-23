<?php

use App\Jobs\PropagateDeleteJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;

it('purges a deleted folder on the cloud and stops tracking the top-level folder', function () {
    $account = Account::factory()->create(['remote_name' => 'od1', 'name' => 'OneDrive']);
    $folder = SyncFolder::factory()->create([
        'account_id' => $account->id, 'remote_path' => 'Docs',
        'is_active' => true, 'sync_mode' => 'on_demand',
    ]);

    // The job's final guard checks the local path is gone → point it nowhere.
    $this->mock(LocalFiles::class)->shouldReceive('localPathFor')->andReturn('/no/such/path/Docs');
    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $captured = null;
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturnUsing(function ($args) use (&$captured) {
        $captured = $args;

        return new RcloneResult(0, '', '');
    });

    (new PropagateDeleteJob($account->id, 'Docs', true))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class), app(LocalFiles::class)
    );

    expect($captured[0])->toBe('purge')
        ->and($captured[1])->toBe('od1:Docs')
        ->and($folder->fresh()->is_active)->toBeFalse(); // top-level → untracked
});

it('uses deletefile for a single file and keeps the folder tracked', function () {
    $account = Account::factory()->create(['remote_name' => 'od1', 'name' => 'OneDrive']);
    $folder = SyncFolder::factory()->create([
        'account_id' => $account->id, 'remote_path' => 'Docs',
        'is_active' => true, 'sync_mode' => 'on_demand',
    ]);

    $this->mock(LocalFiles::class)->shouldReceive('localPathFor')->andReturn('/no/such/path/Docs/a.txt');
    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $captured = null;
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturnUsing(function ($args) use (&$captured) {
        $captured = $args;

        return new RcloneResult(0, '', '');
    });

    (new PropagateDeleteJob($account->id, 'Docs/a.txt', false))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class), app(LocalFiles::class)
    );

    expect($captured[0])->toBe('deletefile')
        ->and($captured[1])->toBe('od1:Docs/a.txt')
        ->and($folder->fresh()->is_active)->toBeTrue(); // a subpath → folder stays
});

it('does NOT touch the cloud if the path reappeared locally', function () {
    $account = Account::factory()->create(['remote_name' => 'od1']);

    $tmp = sys_get_temp_dir().'/rnv-reappear-'.uniqid();
    mkdir($tmp, 0777, true);
    $this->mock(LocalFiles::class)->shouldReceive('localPathFor')->andReturn($tmp); // exists again

    $this->mock(RcloneRunner::class)->shouldNotReceive('run');
    $this->mock(RcloneConfigGenerator::class);

    (new PropagateDeleteJob($account->id, 'Docs', true))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class), app(LocalFiles::class)
    );

    rmdir($tmp);
    expect(true)->toBeTrue();
});
