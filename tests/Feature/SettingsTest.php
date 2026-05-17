<?php

use App\Livewire\Pages\Settings\SettingsPage;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'password' => Hash::make('the-current-password'),
    ]);
    $this->actingAs($this->user);
});

it('saves language and mount base (F1.8)', function () {
    Livewire::test(SettingsPage::class)
        ->set('language', 'pt-BR')
        ->set('mount_base', '/data/RnvSync')
        ->call('saveGeneral')
        ->assertRedirect(route('settings'));

    $settings = app(SettingsRepository::class);
    expect($settings->language())->toBe('pt-BR')
        ->and($settings->mountBase())->toBe('/data/RnvSync');
});

it('changes the panel password with the correct current password', function () {
    Livewire::test(SettingsPage::class)
        ->set('current_password', 'the-current-password')
        ->set('new_password', 'a-brand-new-password')
        ->set('new_password_confirmation', 'a-brand-new-password')
        ->call('changePassword')
        ->assertRedirect(route('settings'));

    expect(Hash::check('a-brand-new-password', $this->user->fresh()->password))->toBeTrue();
});

it('rejects a wrong current password', function () {
    Livewire::test(SettingsPage::class)
        ->set('current_password', 'nope')
        ->set('new_password', 'a-brand-new-password')
        ->set('new_password_confirmation', 'a-brand-new-password')
        ->call('changePassword')
        ->assertHasErrors('current_password');
});
