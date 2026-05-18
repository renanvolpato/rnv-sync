<?php

use App\Jobs\MaterializePlaceholdersJob;
use App\Jobs\StartSyncJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-ondemand-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('materializes cloud placeholders (0-byte) without downloading', function () {
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([
            ['Path' => 'sub', 'IsDir' => true],
            ['Path' => 'sub/a.txt', 'IsDir' => false, 'Size' => 1234],
        ]), '')
    );

    $folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'remote_path' => 'Docs',
        'local_path' => $this->base.'/OneDrive/Docs', 'sync_mode' => 'on_demand',
        'is_active' => true,
    ]);

    (new MaterializePlaceholdersJob($folder->id))->handle(app(LocalFiles::class));

    $stub = $this->base.'/OneDrive/Docs/sub/a.txt';
    expect(File::isDirectory($this->base.'/OneDrive/Docs/sub'))->toBeTrue()
        ->and(File::exists($stub))->toBeTrue()
        ->and(filesize($stub))->toBe(0); // cloud placeholder, not downloaded
});

it('never bisyncs an on-demand folder (would wipe cloud data)', function () {
    $folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'sync_mode' => 'on_demand', 'is_active' => true,
    ]);

    $this->mock(SyncService::class, function ($m) {
        $m->shouldReceive('isPaused')->andReturnFalse();
        $m->shouldNotReceive('runSync');
    });

    (new StartSyncJob($folder->id))->handle(app(SyncService::class));

    $this->assertDatabaseCount('rnvsync_sync_history', 0);
});
