<?php

use App\Livewire\Pages\Accounts\FolderSelection;
use App\Models\Account;
use App\Models\User;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create([
        'remote_name' => 'od1',
        'oauth_token' => json_encode([
            'access_token' => 't', 'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturn(new RcloneResult(0, '[]', ''));
});

it('back goes up one level inside a subfolder, then to root', function () {
    Livewire::test(FolderSelection::class, ['account' => $this->account])
        ->set('path', 'A/B/C')
        ->call('goUp')->assertSet('path', 'A/B')
        ->call('goUp')->assertSet('path', 'A')
        ->call('goUp')->assertSet('path', '');
});

it('handles a single-segment path with spaces', function () {
    Livewire::test(FolderSelection::class, ['account' => $this->account])
        ->set('path', 'Arquivos de Microsoft Copilot Chat')
        ->call('goUp')
        ->assertSet('path', '');
});

it('marks paths inside an active folder as synced, others not', function () {
    $c = new FolderSelection;
    $active = ['Documents', 'Photos/Trips'];

    expect($c->isSynced('Documents', $active))->toBeTrue()
        ->and($c->isSynced('Documents/x.txt', $active))->toBeTrue()
        ->and($c->isSynced('Photos/Trips/deep/y.txt', $active))->toBeTrue()
        ->and($c->isSynced('Photos', $active))->toBeFalse()
        ->and($c->isSynced('Music', $active))->toBeFalse()
        ->and($c->isSynced('DocumentsOther', $active))->toBeFalse();
});
