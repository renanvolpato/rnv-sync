<?php

use App\Events\SyncStatusChanged;
use App\Jobs\StartSyncJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

it('uses 3 retries with exponential backoff (SPEC §17)', function () {
    $job = new StartSyncJob(1);

    expect($job->tries)->toBe(4)
        ->and($job->backoff())->toBe([5, 30, 300]);
});

it('marks folder and account as error and notifies after retries exhausted', function () {
    Event::fake([SyncStatusChanged::class]);

    $folder = SyncFolder::factory()->create(['is_active' => true]);

    (new StartSyncJob($folder->id))->failed(new RuntimeException('boom'));

    expect($folder->fresh()->last_sync_status)->toBe('error')
        ->and($folder->account->fresh()->status)->toBe(Account::STATUS_ERROR);

    Event::assertDispatched(SyncStatusChanged::class,
        fn ($e) => $e->status === 'failed');
});

it('scheduled command queues a job per active folder, skips when paused', function () {
    Queue::fake();

    $active = SyncFolder::factory()->create(['is_active' => true]);
    SyncFolder::factory()->create(['is_active' => false]);

    $this->artisan('rnvsync:scheduled-sync')->assertSuccessful();
    Queue::assertPushed(StartSyncJob::class, 1);

    app(SyncService::class)->setPaused(true);
    $this->artisan('rnvsync:scheduled-sync')->assertSuccessful();
    Queue::assertPushed(StartSyncJob::class, 1); // still 1 — paused skipped
});

it('pull NEVER replaces a real local file with a 0-byte cloud entry (data-safety)', function () {
    // Regression: rclone --update copies remote→local on newer cloud mtime
    // regardless of SIZE. A corrupted 0-byte cloud upload with a newer mtime
    // would TRUNCATE the user's real local file. Pre-filter the pull list to
    // skip those entries before --files-from sees them.
    $base = sys_get_temp_dir().'/rnv-pull-safe-'.uniqid();
    File::ensureDirectoryExists($base.'/Folder');
    File::put($base.'/Folder/real.txt', 'real content');

    $account = Account::factory()->create(['remote_name' => 'od']);
    $folder = SyncFolder::factory()->create([
        'account_id' => $account->id, 'is_active' => true,
        'sync_mode' => 'on_demand', 'local_path' => $base.'/Folder',
        'remote_path' => 'Folder',
    ]);

    $pullList = null;
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturnUsing(function (array $args) use (&$pullList) {
            // Cloud reports the file as 0 bytes (the broken state).
            if (($args[0] ?? '') === 'lsjson') {
                return new RcloneResult(0, json_encode([
                    ['Path' => 'real.txt', 'Size' => 0],
                ]), '');
            }
            // 'copy' + source contains ':' → this is the pull (remote→local).
            if (($args[0] ?? '') === 'copy' && str_contains((string) ($args[1] ?? ''), ':')) {
                $idx = array_search('--files-from', $args, true);
                if ($idx !== false && isset($args[$idx + 1]) && is_file($args[$idx + 1])) {
                    $pullList = trim((string) @file_get_contents($args[$idx + 1]));
                }
            }

            return new RcloneResult(0, '', '');
        });

    (new SyncChangesJob($folder->id))->handle(
        app(RcloneRunner::class),
        app(RcloneConfigGenerator::class),
    );

    expect($pullList ?? '')->not->toContain('real.txt')      // pull skipped the broken entry
        ->and(File::get($base.'/Folder/real.txt'))->toBe('real content'); // local untouched

    File::deleteDirectory($base);
});

it('queues a change-sync for every active on-demand folder (so cloud additions are discovered)', function () {
    Queue::fake();
    $base = sys_get_temp_dir().'/rnv-sched-'.uniqid();

    // A placeholder-only folder MUST still be queued: new files/subfolders
    // created on the OneDrive website are only found by re-listing the
    // remote, which the change-sync now does every run. (The old code
    // skipped these and the additions stayed invisible for hours.)
    File::ensureDirectoryExists($base.'/empty/sub');
    File::put($base.'/empty/sub/ph.txt', '');
    SyncFolder::factory()->create([
        'is_active' => true, 'sync_mode' => 'on_demand',
        'local_path' => $base.'/empty', 'last_synced_at' => now()->subMinutes(5),
    ]);

    // A folder with a real file is queued as well.
    File::ensureDirectoryExists($base.'/real');
    File::put($base.'/real/keep.txt', 'real content');
    SyncFolder::factory()->create([
        'is_active' => true, 'sync_mode' => 'on_demand',
        'local_path' => $base.'/real', 'last_synced_at' => now()->subMinutes(5),
    ]);

    $this->artisan('rnvsync:scheduled-sync')->assertSuccessful();

    Queue::assertPushed(SyncChangesJob::class, 2); // both, none skipped

    File::deleteDirectory($base);
});
