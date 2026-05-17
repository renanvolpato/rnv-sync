<?php

use App\Exceptions\RcloneException;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Models\Account;
use App\Models\User;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    // Token far from expiry so listRemote() never hits the network.
    $this->account = Account::factory()->create([
        'oauth_token' => json_encode([
            'access_token' => 'tok',
            'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);
});

it('lists the remote tree via rclone lsjson (SPEC F1.5)', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(0, json_encode([
            ['Name' => 'Documents', 'IsDir' => true, 'Size' => -1],
            ['Name' => 'photo.jpg', 'IsDir' => false, 'Size' => 2048],
        ]), ''));

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSee('Documents')
        ->assertSee('photo.jpg')
        ->assertSee('2.0 KB');
});

it('shows an unavailable state when rclone cannot be reached', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andThrow(new RcloneException('rclone binary not found'));

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSet('rcloneUnavailable', true)
        ->assertSee(__('errors.rclone_unavailable_title'));
});

it('navigates into a subdirectory', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(0, '[]', ''));

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('open', 'Documents')
        ->assertSet('path', 'Documents');
});
