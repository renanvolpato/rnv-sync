<?php

use App\Livewire\Pages\Dashboard;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('shows "Quota unavailable" when quota cannot be fetched (EARS F1.6)', function () {
    Account::factory()->create([
        'name' => 'My OneDrive',
        'oauth_token' => json_encode([
            'access_token' => 'tok',
            'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);

    Http::fake([
        'graph.microsoft.com/*' => Http::response([], 500),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('My OneDrive')
        ->assertSee(__('dashboard.quota_unavailable'));
});

it('shows the empty state with no accounts', function () {
    Livewire::test(Dashboard::class)
        ->assertSee(__('dashboard.empty_title'));
});
