<?php

use App\Models\Account;
use App\Models\User;
use App\Services\Graph\OneDriveOAuth;
use App\Services\Rclone\RcloneConfigGenerator;
use Illuminate\Support\Facades\Http;

it('detects drive id, drive type and tenant for a Business account (EARS F4.1)', function () {
    $this->actingAs(User::factory()->create());

    // Build an access token JWT carrying a tenant (tid) claim.
    $jwt = 'h.'.rtrim(strtr(base64_encode(json_encode(['tid' => 'tenant-xyz'])), '+/', '-_'), '=').'.s';

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => $jwt, 'refresh_token' => 'r', 'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/me' => Http::response(['displayName' => 'Biz', 'mail' => 'biz@contoso.com']),
        'graph.microsoft.com/v1.0/me/drive' => Http::response([
            'id' => 'b!DRIVEID', 'driveType' => 'business',
            'quota' => ['total' => 100, 'used' => 10],
        ]),
    ]);

    session(['oauth_state' => 's', 'oauth_provider' => Account::PROVIDER_BUSINESS]);

    $this->get(route('oauth.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect(route('dashboard'));

    $account = Account::first();
    expect($account->provider)->toBe(Account::PROVIDER_BUSINESS)
        ->and($account->drive_id)->toBe('b!DRIVEID')
        ->and($account->drive_type)->toBe('business')
        ->and($account->tenant_id)->toBe('tenant-xyz');
});

it('decodes the tenant from a token and null for a non-JWT', function () {
    $oauth = app(OneDriveOAuth::class);
    $jwt = 'a.'.rtrim(strtr(base64_encode(json_encode(['tid' => 't1'])), '+/', '-_'), '=').'.z';

    expect($oauth->tenantFromToken($jwt))->toBe('t1')
        ->and($oauth->tenantFromToken('not-a-jwt'))->toBeNull();
});

it('writes drive_id into the generated rclone config', function () {
    $account = Account::factory()->create([
        'remote_name' => 'biz', 'drive_id' => 'DRV1',
        'drive_type' => 'business', 'oauth_token' => json_encode(['access_token' => 'a']),
    ]);

    $ini = app(RcloneConfigGenerator::class)->build();

    expect($ini)->toContain('[biz]')
        ->toContain('drive_id = DRV1')
        ->toContain('drive_type = business');
});
