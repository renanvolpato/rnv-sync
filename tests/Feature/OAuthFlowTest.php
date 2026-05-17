<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('redirects to Microsoft when starting the OAuth flow', function () {
    // EARS: WHEN the user clicks "Add OneDrive Account", redirect to MS login.
    $response = $this->get(route('oauth.start'));

    $response->assertRedirectContains('login.microsoftonline.com');
    expect(session('oauth_state'))->not->toBeNull();
});

it('stores the token encrypted and shows the account on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'real-access-token',
            'refresh_token' => 'real-refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'graph.microsoft.com/v1.0/me' => Http::response([
            'displayName' => 'Jane Doe',
            'mail' => 'jane@example.com',
        ]),
        'graph.microsoft.com/v1.0/me/drive' => Http::response([
            'quota' => ['total' => 1000, 'used' => 250],
        ]),
    ]);

    session(['oauth_state' => 'state-123']);

    $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => 'state-123']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status');

    $account = Account::first();
    expect($account)->not->toBeNull()
        ->and($account->email)->toBe('jane@example.com')
        ->and($account->tokenPayload()['access_token'])->toBe('real-access-token')
        ->and($account->quota_total_bytes)->toBe(1000);

    // Encrypted at rest: the raw column must not contain the plaintext token.
    $raw = DB::table('rnvsync_accounts')->where('id', $account->id)->value('oauth_token');
    expect($raw)->not->toContain('real-access-token');
});

it('shows a localized error and offers retry when the user denies consent', function () {
    $this->get(route('oauth.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('accounts.new'))
        ->assertSessionHasErrors('oauth');
});

it('rejects a state mismatch (CSRF protection)', function () {
    session(['oauth_state' => 'expected']);

    $this->get(route('oauth.callback', ['code' => 'c', 'state' => 'tampered']))
        ->assertRedirect(route('accounts.new'))
        ->assertSessionHasErrors('oauth');

    expect(Account::count())->toBe(0);
});
