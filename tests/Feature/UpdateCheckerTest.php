<?php

use App\Livewire\UpdateChecker;
use App\Models\User;
use App\Services\Update\UpdateService;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('renders the header update control without hitting the network', function () {
    $this->mock(UpdateService::class, function ($m) {
        $m->shouldReceive('isGitInstall')->andReturnTrue();
        $m->shouldReceive('cachedStatus')->andReturnNull();
    });

    Livewire::test(UpdateChecker::class)
        ->assertOk()
        ->assertSee(__('settings.update_check'));
});

it('checks for updates via UpdateService and toasts the result', function () {
    $this->mock(UpdateService::class, function ($m) {
        $m->shouldReceive('isGitInstall')->andReturnTrue();
        $m->shouldReceive('cachedStatus')->andReturnNull();
        $m->shouldReceive('checkForUpdates')->with(true)
            ->andReturn(['available' => true, 'behind' => 3, 'error' => null]);
    });

    Livewire::test(UpdateChecker::class)
        ->call('check')
        ->assertDispatched('toast')
        ->assertSet('status.available', true);
});

it('renders inside the app header on a real page (layout smoke test)', function () {
    $this->actingAs(User::factory()->create());
    \App\Models\Account::factory()->create();

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee(__('settings.update_check'));
});

it('apply refuses when the install is not a git clone', function () {
    $this->mock(UpdateService::class, function ($m) {
        $m->shouldReceive('isGitInstall')->andReturnFalse();
        $m->shouldReceive('cachedStatus')->andReturnNull();
        $m->shouldNotReceive('runUpdate');
    });

    Livewire::test(UpdateChecker::class)
        ->call('apply')
        ->assertDispatched('toast');
});
