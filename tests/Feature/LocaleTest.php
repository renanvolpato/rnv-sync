<?php

use App\Models\User;
use App\Services\Settings\SettingsRepository;

it('detects pt-BR from the Accept-Language header before setup (SPEC §13)', function () {
    $this->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9'])
        ->get(route('setup.index'))
        ->assertOk();

    expect(app()->getLocale())->toBe('pt-BR');
});

it('prefers the saved DB preference over the browser header', function () {
    User::factory()->create();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_LANGUAGE, 'en');

    $this->actingAs(User::first())
        ->withHeaders(['Accept-Language' => 'pt-BR'])
        ->get(route('dashboard'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('falls back to English for an unsupported language', function () {
    $this->withHeaders(['Accept-Language' => 'fr-FR'])
        ->get(route('setup.index'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});
