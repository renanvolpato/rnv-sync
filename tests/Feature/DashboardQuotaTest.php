<?php

use App\Livewire\Pages\Dashboard;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('shows "Quota unavailable" only when there is NO saved quota AND fetch fails (EARS F1.6)', function () {
    Account::factory()->create([
        'name' => 'My OneDrive',
        'quota_total_bytes' => null,
        'quota_used_bytes' => null,
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

it('keeps showing the last saved quota when only the latest refresh fails', function () {
    // Established account: quota was fetched before; transient Graph
    // failure must NOT hide the saved data ("Cota indisponível" UX bug).
    Account::factory()->create([
        'name' => 'My OneDrive',
        'quota_total_bytes' => 1_000_000_000,
        'quota_used_bytes' => 600_000_000,
        'oauth_token' => json_encode([
            'access_token' => 'tok',
            'refresh_token' => 'r',
            'expiry' => now()->addHours(3)->toRfc3339String(),
        ]),
    ]);

    Http::fake(['graph.microsoft.com/*' => Http::response([], 500)]);

    Livewire::test(Dashboard::class)
        ->assertSee('My OneDrive')
        ->assertSee('60%')
        ->assertDontSee(__('dashboard.quota_unavailable'));
});

it('shows the empty state with no accounts', function () {
    Livewire::test(Dashboard::class)
        ->assertSee(__('dashboard.empty_title'));
});
