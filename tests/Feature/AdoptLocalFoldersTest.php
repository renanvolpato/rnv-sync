<?php

use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-adopt-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od']);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('adopts a locally-created folder that has real files and pushes it up', function () {
    Queue::fake();
    $root = $this->base.'/OneDrive';

    // user-created folder with real content, not tracked yet
    File::ensureDirectoryExists($root.'/NovaPasta/sub');
    File::put($root.'/NovaPasta/sub/doc.txt', 'real content');

    // a placeholder-only folder that must NOT be adopted
    File::ensureDirectoryExists($root.'/SoNuvem');
    File::put($root.'/SoNuvem/ph.txt', '');

    $this->artisan('rnvsync:adopt-local-folders')->assertSuccessful();

    $adopted = SyncFolder::where('account_id', $this->account->id)
        ->where('remote_path', 'NovaPasta')->first();
    expect($adopted)->not->toBeNull()
        ->and($adopted->is_active)->toBeTrue()
        ->and(SyncFolder::where('remote_path', 'SoNuvem')->exists())->toBeFalse();

    Queue::assertPushed(SyncChangesJob::class, 1);
});

it('does not re-adopt an already-active folder', function () {
    Queue::fake();
    $root = $this->base.'/OneDrive';
    File::ensureDirectoryExists($root.'/Existente');
    File::put($root.'/Existente/a.txt', 'x');
    SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
        'remote_path' => 'Existente', 'local_path' => $root.'/Existente',
    ]);

    $this->artisan('rnvsync:adopt-local-folders')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('prune does NOT deactivate a remote-missing folder that still has local files', function () {
    $root = $this->base.'/OneDrive';
    File::ensureDirectoryExists($root.'/PendingUpload');
    File::put($root.'/PendingUpload/keep.txt', 'real');
    $f = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
        'remote_path' => 'PendingUpload', 'local_path' => $root.'/PendingUpload',
    ]);

    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturn(new RcloneResult(3, '', 'ERROR : PendingUpload: directory not found'));

    $this->artisan('rnvsync:prune-orphan-folders')->assertSuccessful();

    expect($f->fresh()->is_active)->toBeTrue();
});
