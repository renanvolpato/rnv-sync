<?php

use App\Jobs\StartSyncJob;
use App\Livewire\Pages\Accounts\FolderSelection;
use App\Livewire\Pages\Accounts\SyncActivity;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\User;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create([
        'oauth_token' => json_encode([
            'access_token' => 'tok', 'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);
});

it('saves folder selection and queues a sync within seconds (EARS F2.1)', function () {
    Queue::fake();

    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturn(new RcloneResult(0, json_encode([
            ['Name' => 'Documents', 'IsDir' => true, 'Size' => -1],
        ]), ''));

    Livewire::test(FolderSelection::class, ['account' => $this->account])
        ->set('selected', ['Documents' => true])
        ->call('save')
        ->assertRedirect(route('accounts.files', $this->account));

    // remote_path is normalised (no leading slash); local_path joined
    // with a separator under the mount base.
    $this->assertDatabaseHas('rnvsync_sync_folders', [
        'account_id' => $this->account->id,
        'remote_path' => 'Documents',
        'is_active' => true,
    ]);
    Queue::assertPushed(StartSyncJob::class);
});

it('toggles a folder and queues a sync, and pauses globally', function () {
    Queue::fake();
    $folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => false,
    ]);

    $component = Livewire::test(SyncActivity::class, ['account' => $this->account])
        ->call('toggleFolder', $folder->id);

    expect($folder->fresh()->is_active)->toBeTrue();
    Queue::assertPushed(StartSyncJob::class);

    $component->call('togglePause');
    expect(app(SyncService::class)->isPaused())->toBeTrue();
});

it('queues a manual sync now', function () {
    Queue::fake();
    $folder = SyncFolder::factory()->create(['account_id' => $this->account->id]);

    Livewire::test(SyncActivity::class, ['account' => $this->account])
        ->call('syncNow', $folder->id);

    Queue::assertPushed(StartSyncJob::class);
});
