<?php

use App\Jobs\StartSyncJob;
use App\Livewire\Pages\ConflictsPage;
use App\Models\Account;
use App\Models\Conflict;
use App\Models\SyncFolder;
use App\Models\User;
use App\Services\Conflicts\ConflictsService;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Sync\SyncService;
use Livewire\Livewire;

it('resolves a conflict from the conflicts page', function () {
    $this->actingAs(User::factory()->create());
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturn(new RcloneResult(0, '', ''));

    $account = Account::factory()->create();
    $conflict = Conflict::create([
        'account_id' => $account->id, 'path' => 'a.txt',
        'status' => 'pending', 'detected_at' => now(),
    ]);

    Livewire::test(ConflictsPage::class)
        ->call('resolve', $conflict->id, 'local')
        ->assertDispatched('toast');

    expect($conflict->fresh()->status)->toBe('resolved_local');
});

it('does not sync a folder whose account is auto-paused (EARS F4.4)', function () {
    $account = Account::factory()->create();
    $folder = SyncFolder::factory()->create([
        'account_id' => $account->id, 'is_active' => true,
    ]);

    app(ConflictsService::class)->setAccountPaused($account, true);

    // SyncService must never be asked to run for a paused account.
    $this->mock(SyncService::class, function ($m) {
        $m->shouldReceive('isPaused')->andReturnFalse();
        $m->shouldNotReceive('runSync');
    });

    (new StartSyncJob($folder->id))->handle(app(SyncService::class));

    // No sync ran, so no history was recorded.
    $this->assertDatabaseCount('rnvsync_sync_history', 0);
});
