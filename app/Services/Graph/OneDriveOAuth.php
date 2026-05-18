<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Exceptions\OAuthException;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * In-app Microsoft OAuth for OneDrive (SPEC §8 "OAuth Flow", Path B).
 *
 * Tokens are stored in the rclone-compatible JSON shape
 * ({access_token, token_type, refresh_token, expiry}) and encrypted at
 * rest on the Account model. rclone reads them via the generated config.
 */
class OneDriveOAuth
{
    /**
     * Build the Microsoft authorize URL and remember the CSRF state.
     */
    public function authorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('rnvsync.oauth.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => config('rnvsync.oauth.scopes'),
            'state' => $state,
        ]);

        return $this->endpoint('authorize').'?'.$params;
    }

    /**
     * Build a tenant-aware Microsoft identity endpoint. The tenant
     * segment (common / consumers / organizations / tenant id) controls
     * whether a personal account is used directly or pulled into a work
     * tenant as a guest.
     */
    private function endpoint(string $type): string
    {
        $tenant = trim((string) config('rnvsync.oauth.tenant', 'common')) ?: 'common';

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$type}";
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/oauth/callback';
    }

    /**
     * Exchange an authorization code for a token set.
     *
     * @return array<string,mixed> rclone-shaped token payload
     *
     * @throws OAuthException
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post($this->endpoint('token'), [
            'client_id' => config('rnvsync.oauth.client_id'),
            'client_secret' => config('rnvsync.oauth.client_secret') ?: null,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => config('rnvsync.oauth.scopes'),
        ]);

        if ($response->failed()) {
            throw OAuthException::tokenExchangeFailed($response->json('error_description') ?? $response->body());
        }

        return $this->normalizeToken($response->json());
    }

    /**
     * Refresh an account's token if it is within the refresh window of
     * expiry. Returns true if a refresh actually happened.
     *
     * SPEC F1 EARS: refresh automatically within 10 minutes of expiry.
     *
     * @throws OAuthException
     */
    public function refreshIfNeeded(Account $account): bool
    {
        // Zero-config accounts hold a token minted by rclone's built-in
        // client. rclone refreshes it itself (it has the matching
        // client id/secret embedded) whenever it runs against the
        // remote. Refreshing here with our public client would fail
        // (AADSTS70002: client_secret required) and wrongly disconnect
        // the account — so leave it to rclone.
        if ($account->uses_bundled_client) {
            return false;
        }

        $payload = $account->tokenPayload();

        if (! $payload || empty($payload['refresh_token'])) {
            return false;
        }

        $expiry = isset($payload['expiry'])
            ? CarbonImmutable::parse($payload['expiry'])
            : CarbonImmutable::now()->subMinute();

        $window = (int) config('rnvsync.oauth.refresh_window_seconds');

        if ($expiry->isAfter(CarbonImmutable::now()->addSeconds($window))) {
            return false;
        }

        $response = Http::asForm()->post($this->endpoint('token'), [
            'client_id' => config('rnvsync.oauth.client_id'),
            'client_secret' => config('rnvsync.oauth.client_secret') ?: null,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $payload['refresh_token'],
            'scope' => config('rnvsync.oauth.scopes'),
        ]);

        if ($response->failed()) {
            $account->update(['status' => Account::STATUS_DISCONNECTED]);

            throw OAuthException::refreshFailed($response->json('error_description') ?? $response->body());
        }

        $token = $this->normalizeToken($response->json(), $payload['refresh_token']);

        $account->update([
            'oauth_token' => json_encode($token),
            'status' => Account::STATUS_ACTIVE,
        ]);

        return true;
    }

    /**
     * Fetch the signed-in user's email/display name (User.Read scope).
     *
     * @return array{email:?string,name:?string}
     */
    public function fetchUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('rnvsync.oauth.graph_base').'/me');

        if ($response->failed()) {
            return ['email' => null, 'name' => null];
        }

        return [
            'email' => $response->json('mail') ?? $response->json('userPrincipalName'),
            'name' => $response->json('displayName'),
        ];
    }

    /**
     * Detect the user's drive id/type and tenant (SPEC F4.1 EARS:
     * detect the tenant and configure the correct drive_id/drive_type).
     *
     * @return array{drive_id:?string,drive_type:?string,tenant_id:?string}
     */
    public function fetchDrive(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('rnvsync.oauth.graph_base').'/me/drive');

        return [
            'drive_id' => $response->json('id'),
            'drive_type' => $response->json('driveType'), // personal|business|documentLibrary
            'tenant_id' => $this->tenantFromToken($accessToken),
        ];
    }

    /** Extract the `tid` (tenant) claim from a Microsoft access token JWT. */
    public function tenantFromToken(string $accessToken): ?string
    {
        $parts = explode('.', $accessToken);
        if (count($parts) < 2) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);

        return is_array($payload) ? ($payload['tid'] ?? null) : null;
    }

    /**
     * Fetch drive quota (SPEC F1.6). Returns null on failure so the UI can
     * show "Quota unavailable" and retry next load.
     *
     * @return array{total:int,used:int}|null
     */
    public function fetchQuota(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->get(config('rnvsync.oauth.graph_base').'/me/drive');

        if ($response->failed() || ! $response->json('quota')) {
            return null;
        }

        return [
            'total' => (int) $response->json('quota.total'),
            'used' => (int) $response->json('quota.used'),
        ];
    }

    /**
     * Normalize a Microsoft token response into rclone's stored shape.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function normalizeToken(array $data, ?string $fallbackRefresh = null): array
    {
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        return [
            'access_token' => $data['access_token'] ?? '',
            'token_type' => $data['token_type'] ?? 'Bearer',
            'refresh_token' => $data['refresh_token'] ?? $fallbackRefresh ?? '',
            'expiry' => CarbonImmutable::now()->addSeconds($expiresIn)->toRfc3339String(),
        ];
    }
}
