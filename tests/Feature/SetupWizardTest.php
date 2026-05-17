<?php

use App\Livewire\Pages\Setup\Wizard;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Livewire\Livewire;

it('redirects all routes to the wizard when no panel password is set', function () {
    // EARS: WHEN no panel password is set, redirect all routes to setup.
    $this->get('/')->assertRedirect(route('setup.index'));
    $this->get('/settings')->assertRedirect(route('setup.index'));
});

it('redirects away from the wizard once setup is complete', function () {
    User::factory()->create();

    $this->get(route('setup.index'))->assertRedirect(route('dashboard'));
});

it('creates the panel user, stores settings and logs in', function () {
    Livewire::test(Wizard::class)
        ->set('step', 2)
        ->set('email', 'owner@example.com')
        ->set('password', 'a-very-long-password')
        ->set('password_confirmation', 'a-very-long-password')
        ->call('next')
        ->set('language', 'pt-BR')
        ->call('next')
        ->set('mount_base', '/home/user/RnvSync')
        ->call('finish')
        ->assertRedirect(route('dashboard'));

    expect(User::where('email', 'owner@example.com')->exists())->toBeTrue()
        ->and(auth()->check())->toBeTrue()
        ->and(app(SettingsRepository::class)->language())->toBe('pt-BR');
});

it('rejects a password shorter than 12 characters', function () {
    Livewire::test(Wizard::class)
        ->set('step', 2)
        ->set('email', 'owner@example.com')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('next')
        ->assertHasErrors(['password']);
});
