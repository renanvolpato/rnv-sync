<?php

use App\Events\SyncStatusChanged;
use App\Jobs\StartSyncJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
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

it('skips placeholder-only on-demand folders to keep the boot sync fast', function () {
    Queue::fake();
    $base = sys_get_temp_dir().'/rnv-sched-'.uniqid();

    // recently synced + only placeholders → SHOULD be skipped
    File::ensureDirectoryExists($base.'/empty/sub');
    File::put($base.'/empty/sub/ph.txt', '');
    SyncFolder::factory()->create([
        'is_active' => true, 'sync_mode' => 'on_demand',
        'local_path' => $base.'/empty', 'last_synced_at' => now()->subMinutes(5),
    ]);

    // recently synced + has a real file → SHOULD run (pull keeps it fresh)
    File::ensureDirectoryExists($base.'/real');
    File::put($base.'/real/keep.txt', 'real content');
    SyncFolder::factory()->create([
        'is_active' => true, 'sync_mode' => 'on_demand',
        'local_path' => $base.'/real', 'last_synced_at' => now()->subMinutes(5),
    ]);

    // placeholder-only but STALE (>4h since last sync) → SHOULD run (safety net)
    File::ensureDirectoryExists($base.'/stale');
    File::put($base.'/stale/ph.txt', '');
    SyncFolder::factory()->create([
        'is_active' => true, 'sync_mode' => 'on_demand',
        'local_path' => $base.'/stale', 'last_synced_at' => now()->subHours(6),
    ]);

    $this->artisan('rnvsync:scheduled-sync')->assertSuccessful();

    Queue::assertPushed(SyncChangesJob::class, 2);

    File::deleteDirectory($base);
});
