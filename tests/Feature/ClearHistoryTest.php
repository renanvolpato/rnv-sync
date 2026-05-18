<?php

use App\Livewire\Pages\Accounts\SyncActivity;
use App\Models\Account;
use App\Models\SyncHistory;
use App\Models\User;
use Livewire\Livewire;

it('clears finished history but keeps a run in progress', function () {
    $this->actingAs(User::factory()->create());
    $account = Account::factory()->create();

    SyncHistory::insert([
        ['account_id' => $account->id, 'status' => 'success', 'started_at' => now()],
        ['account_id' => $account->id, 'status' => 'error', 'started_at' => now()],
        ['account_id' => $account->id, 'status' => 'running', 'started_at' => now()],
    ]);

    Livewire::test(SyncActivity::class, ['account' => $account])
        ->call('clearHistory');

    expect(SyncHistory::where('account_id', $account->id)->count())->toBe(1)
        ->and(SyncHistory::where('account_id', $account->id)->first()->status)->toBe('running');
});
