<?php

use App\Livewire\SyncToggle;
use App\Models\User;
use App\Services\Sync\SyncService;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('renders the header sync toggle (pause action when active)', function () {
    Livewire::test(SyncToggle::class)
        ->assertOk()
        ->assertSet('paused', false)
        ->assertSee(__('sync.pause'));
});

it('toggles global pause, persists it, and reflects it on a fresh mount', function () {
    expect(app(SyncService::class)->isPaused())->toBeFalse();

    Livewire::test(SyncToggle::class)
        ->call('toggle')
        ->assertSet('paused', true)
        ->assertDispatched('toast');

    expect(app(SyncService::class)->isPaused())->toBeTrue();

    // A freshly mounted control reflects the persisted (paused) state + resume action.
    Livewire::test(SyncToggle::class)
        ->assertSet('paused', true)
        ->assertSee(__('sync.resume'))
        ->call('toggle')
        ->assertSet('paused', false);

    expect(app(SyncService::class)->isPaused())->toBeFalse();
});
