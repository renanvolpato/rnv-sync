<?php

use App\Exceptions\OAuthException;
use App\Models\Account;
use App\Services\Graph\OneDriveOAuth;
use Illuminate\Support\Facades\Http;

it('refreshes the token when within 10 minutes of expiry (EARS)', function () {
    $account = Account::factory()->create([
        'oauth_token' => json_encode([
            'access_token' => 'old',
            'refresh_token' => 'refresh-abc',
            'expiry' => now()->addMinutes(5)->toRfc3339String(),
        ]),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ]),
    ]);

    $refreshed = app(OneDriveOAuth::class)->refreshIfNeeded($account);

    expect($refreshed)->toBeTrue()
        ->and($account->fresh()->tokenPayload()['access_token'])->toBe('new-access');
});

it('does not refresh a token that is far from expiry', function () {
    $account = Account::factory()->create([
        'oauth_token' => json_encode([
            'access_token' => 'still-good',
            'refresh_token' => 'refresh-abc',
            'expiry' => now()->addHours(2)->toRfc3339String(),
        ]),
    ]);

    Http::fake();

    expect(app(OneDriveOAuth::class)->refreshIfNeeded($account))->toBeFalse();
    Http::assertNothingSent();
});

it('marks the account disconnected when refresh fails', function () {
    $account = Account::factory()->create([
        'oauth_token' => json_encode([
            'access_token' => 'old',
            'refresh_token' => 'bad',
            'expiry' => now()->subMinute()->toRfc3339String(),
        ]),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => app(OneDriveOAuth::class)->refreshIfNeeded($account))
        ->toThrow(OAuthException::class);

    expect($account->fresh()->status)->toBe(Account::STATUS_DISCONNECTED);
});
