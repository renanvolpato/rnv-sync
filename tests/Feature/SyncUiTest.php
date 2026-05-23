<?php

use App\Jobs\MaterializePlaceholdersJob;
use App\Jobs\StartSyncJob;
use App\Jobs\SyncChangesJob;
use App\Livewire\Pages\Accounts\SyncActivity;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\User;
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

it('unsyncs a folder (removes it) and pauses globally', function () {
    Queue::fake();
    $folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
    ]);

    $component = Livewire::test(SyncActivity::class, ['account' => $this->account])
        ->call('unsync', $folder->id);

    expect(SyncFolder::find($folder->id))->toBeNull();

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

it('manual sync of an on-demand folder actually pushes/pulls (recovery)', function () {
    Queue::fake();
    $folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id,
        'sync_mode' => 'on_demand',
        'is_active' => true,
    ]);

    Livewire::test(SyncActivity::class, ['account' => $this->account])
        ->call('syncNow', $folder->id);

    // Not just placeholders: the change sync must be chained so a
    // "didn't sync" item is actually re-uploaded / re-downloaded.
    Queue::assertPushed(MaterializePlaceholdersJob::class, function ($job) {
        return collect($job->chained)->contains(
            fn ($s) => str_contains($s, SyncChangesJob::class)
        );
    });
});
