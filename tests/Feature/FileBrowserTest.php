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

it('shows "rclone unavailable" only when the bundled binary is genuinely missing', function () {
    config(['rnvsync.rclone.binary_path' => '/no/such/rclone-binary']);

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSet('rcloneUnavailable', true)
        ->assertSee(__('errors.rclone_unavailable_title'));
});

it('shows a transient "could not load" state (NOT "rclone missing") when a listing throws but the binary exists', function () {
    // The binary is present (real repo path); a single listing error must not
    // be reported as a missing engine, and the flag must not latch.
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andThrow(new RcloneException('couldn\'t connect: i/o timeout'));

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSet('rcloneUnavailable', false)
        ->assertSet('listingFailed', true)
        ->assertSee(__('errors.listing_failed_title'))
        ->assertDontSee(__('errors.rclone_unavailable_title'));
});

it('clears a previously-failed listing once rclone responds again (no latched banner)', function () {
    // First render throws, second succeeds — the banner must reset on success.
    $calls = 0;
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? throw new RcloneException('transient')
                : new RcloneResult(0, json_encode([['Name' => 'Back', 'IsDir' => true, 'Size' => -1]]), '');
        });

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSet('listingFailed', true)
        ->call('$refresh')
        ->assertSet('listingFailed', false)
        ->assertSee('Back');
});

it('navigates into a subdirectory', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturn(new RcloneResult(0, '[]', ''));

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('open', 'Documents')
        ->assertSet('path', 'Documents');
});
