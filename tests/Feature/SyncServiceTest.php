<?php

use App\Events\SyncProgress;
use App\Events\SyncStatusChanged;
use App\Exceptions\RcloneException;
use App\Models\SyncFolder;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\Event;

it('passes --bwlimit when a bandwidth limit is set (EARS F2.8)', function () {
    app(SettingsRepository::class)->set('bandwidth_limit_kbps', 500);

    $args = app(SyncService::class)->bisyncArgs();

    expect($args)->toContain('--bwlimit=500k');
});

it('omits --bwlimit when no limit is set', function () {
    expect(app(SyncService::class)->bisyncArgs())
        ->not->toContain('--bwlimit=0k');
});

it('records history with file count and bytes, and emits events (F2.3/F2.7)', function () {
    Event::fake([SyncProgress::class, SyncStatusChanged::class]);

    $folder = SyncFolder::factory()->create(['is_active' => true]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(0, json_encode([
            'level' => 'info', 'msg' => 'done',
            'stats' => ['transfers' => 7, 'bytes' => 4096, 'errors' => 0],
        ]), ''));

    $history = app(SyncService::class)->runSync($folder);

    expect($history->status)->toBe('success')
        ->and($history->files_transferred)->toBe(7)
        ->and($history->bytes_transferred)->toBe(4096);

    Event::assertDispatched(SyncStatusChanged::class);
    Event::assertDispatched(SyncProgress::class);
});

it('throws on a 429 so the job can respect Retry-After (EARS)', function () {
    $folder = SyncFolder::factory()->create(['is_active' => true]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(1, '', json_encode([
            'level' => 'error', 'msg' => 'HTTP 429 Too Many Requests',
        ])));

    expect(fn () => app(SyncService::class)->runSync($folder))
        ->toThrow(RcloneException::class);
});

it('treats a non-zero exit as a retryable failure', function () {
    $folder = SyncFolder::factory()->create(['is_active' => true]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(1, '', '{"level":"error","msg":"network unreachable"}'));

    expect(fn () => app(SyncService::class)->runSync($folder))
        ->toThrow(RcloneException::class);
});
