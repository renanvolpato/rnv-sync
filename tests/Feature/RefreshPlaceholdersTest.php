<?php

use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;

it('materialises placeholders for active on-demand folders only, throttled per folder', function () {
    $account = Account::factory()->create(['remote_name' => 'od1']);
    SyncFolder::factory()->create(['account_id' => $account->id, 'remote_path' => 'A', 'sync_mode' => 'on_demand', 'is_active' => true]);
    SyncFolder::factory()->create(['account_id' => $account->id, 'remote_path' => 'B', 'sync_mode' => 'on_demand', 'is_active' => true]);
    // inactive and bisync folders must be ignored
    SyncFolder::factory()->create(['account_id' => $account->id, 'remote_path' => 'C', 'sync_mode' => 'on_demand', 'is_active' => false]);
    SyncFolder::factory()->create(['account_id' => $account->id, 'remote_path' => 'D', 'sync_mode' => 'bisync', 'is_active' => true]);

    $seen = [];
    $this->mock(LocalFiles::class)
        ->shouldReceive('materializeCloudPlaceholders')
        ->andReturnUsing(function ($acc, $path) use (&$seen) {
            $seen[] = $path;

            return 0;
        });

    // First run mirrors the two active on-demand folders.
    $this->artisan('rnvsync:refresh-placeholders')->assertSuccessful();
    expect($seen)->toEqualCanonicalizing(['A', 'B']);

    // Second run within the window is throttled → nothing new.
    $this->artisan('rnvsync:refresh-placeholders')->assertSuccessful();
    expect($seen)->toEqualCanonicalizing(['A', 'B']);

    // --force bypasses the throttle and refreshes them again.
    $this->artisan('rnvsync:refresh-placeholders', ['--force' => true])->assertSuccessful();
    expect(collect($seen)->countBy()->all())->toMatchArray(['A' => 2, 'B' => 2]);
});
