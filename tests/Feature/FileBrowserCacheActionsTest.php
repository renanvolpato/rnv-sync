<?php

use App\Livewire\Pages\Accounts\FileBrowser;
use App\Models\Account;
use App\Models\User;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Livewire\Livewire;

beforeEach(function () {
    config(['rnvsync.rclone.cache_dir' => sys_get_temp_dir().'/rnvsync-fb-'.uniqid()]);
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create([
        'remote_name' => 'od1',
        'oauth_token' => json_encode([
            'access_token' => 't', 'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);

    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(0, json_encode([
            ['Name' => 'report.pdf', 'IsDir' => false, 'Size' => 500],
        ]), ''));
});

it('pins a file from the browser (F3.6)', function () {
    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('pin', 'report.pdf', false, 500);

    $this->assertDatabaseHas('rnvsync_file_policies', [
        'account_id' => $this->account->id,
        'path' => 'report.pdf',
        'policy' => 'always_offline',
    ]);
});

it('warns when pinning a file larger than the cache limit (EARS F3.6)', function () {
    config(['rnvsync.defaults.cache' => ['free_space_fraction' => 0, 'min_gb' => 1, 'max_gb' => 1]]);

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('pin', 'report.pdf', false, 5 * 1024 ** 3)
        ->assertDispatched('toast', type: 'warning');

    $this->assertDatabaseMissing('rnvsync_file_policies', [
        'account_id' => $this->account->id, 'path' => 'report.pdf',
    ]);
});

it('frees all cache from the browser (F3.8)', function () {
    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('freeAll')
        ->assertDispatched('toast', type: 'success');
});
