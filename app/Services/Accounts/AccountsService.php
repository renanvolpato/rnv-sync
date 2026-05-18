<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\OAuthException;
use App\Models\Account;
use App\Services\Graph\OneDriveOAuth;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Str;

/**
 * Orchestrates account lifecycle: OAuth onboarding, quota refresh and
 * read-only remote listing (SPEC F1.4, F1.5, F1.6).
 */
class AccountsService
{
    public function __construct(
        private readonly OneDriveOAuth $oauth,
        private readonly RcloneConfigGenerator $configGenerator,
        private readonly RcloneRunner $rclone,
    ) {}

    /**
     * Begin the OAuth flow. Returns [authorizeUrl, state]; caller stores
     * state in the session for CSRF verification on callback.
     *
     * @return array{0:string,1:string}
     */
    public function initiateOAuth(): array
    {
        $state = $this->oauth->newState();

        return [$this->oauth->authorizeUrl($state), $state];
    }

    /**
     * Complete the OAuth flow: exchange code, persist the encrypted
     * account, and regenerate the rclone config.
     *
     * @throws OAuthException
     */
    public function completeOAuth(string $code, string $provider = Account::PROVIDER_PERSONAL): Account
    {
        $token = $this->oauth->exchangeCode($code);
        $user = $this->oauth->fetchUser($token['access_token']);
        $drive = $this->oauth->fetchDrive($token['access_token']);

        $account = Account::create([
            'name' => $user['name'] ?: ($user['email'] ?: 'OneDrive'),
            'provider' => $provider,
            'remote_name' => $this->uniqueRemoteName($user['email'] ?? 'onedrive'),
            'drive_id' => $drive['drive_id'],
            'drive_type' => $drive['drive_type'],
            'tenant_id' => $drive['tenant_id'],
            'email' => $user['email'],
            'oauth_token' => json_encode($token),
            'status' => Account::STATUS_ACTIVE,
        ]);

        $quota = $this->oauth->fetchQuota($token['access_token']);
        if ($quota !== null) {
            $account->update([
                'quota_total_bytes' => $quota['total'],
                'quota_used_bytes' => $quota['used'],
            ]);
        }

        $this->configGenerator->regenerate();

        return $account;
    }

    /**
     * Complete zero-config ("easy") onboarding from a token issued by
     * rclone's own OAuth. The account is flagged uses_bundled_client so
     * the generated rclone remote omits client_id (matching the token).
     *
     * @param  array<string,mixed>  $token
     */
    public function completeFromToken(array $token): Account
    {
        $access = (string) ($token['access_token'] ?? '');
        $user = $this->oauth->fetchUser($access);
        $drive = $this->oauth->fetchDrive($access);

        $account = Account::create([
            'name' => $user['name'] ?: ($user['email'] ?: 'OneDrive'),
            'provider' => Account::PROVIDER_PERSONAL,
            'remote_name' => $this->uniqueRemoteName($user['email'] ?? 'onedrive'),
            'drive_id' => $drive['drive_id'],
            'drive_type' => $drive['drive_type'],
            'tenant_id' => $drive['tenant_id'],
            'uses_bundled_client' => true,
            'email' => $user['email'],
            'oauth_token' => json_encode($token),
            'status' => Account::STATUS_ACTIVE,
        ]);

        $quota = $this->oauth->fetchQuota($access);
        if ($quota !== null) {
            $account->update([
                'quota_total_bytes' => $quota['total'],
                'quota_used_bytes' => $quota['used'],
            ]);
        }

        $this->configGenerator->regenerate();

        return $account;
    }

    /**
     * Refresh quota for the dashboard (SPEC F1.6 EARS: on failure show
     * "Quota unavailable" and retry next load). Returns false on failure.
     */
    public function refreshQuota(Account $account): bool
    {
        try {
            $this->oauth->refreshIfNeeded($account);
            $account->refresh();
        } catch (OAuthException) {
            return false;
        }

        $payload = $account->tokenPayload();
        if (! $payload) {
            return false;
        }

        $quota = $this->oauth->fetchQuota($payload['access_token']);
        if ($quota === null) {
            return false;
        }

        $account->update([
            'quota_total_bytes' => $quota['total'],
            'quota_used_bytes' => $quota['used'],
        ]);

        return true;
    }

    /**
     * List a remote directory read-only via `rclone lsjson` (SPEC F1.5).
     *
     * @return list<array{name:string,path:string,is_dir:bool,size:int}>
     */
    public function listRemote(Account $account, string $path = ''): array
    {
        $this->oauth->refreshIfNeeded($account);
        $this->configGenerator->regenerate();

        $remote = $account->remote_name.':'.ltrim($path, '/');

        $result = $this->rclone->run(['lsjson', $remote], ['timeout' => 60]);

        if (! $result->successful()) {
            return [];
        }

        $entries = $result->json() ?? [];

        $mapped = array_map(fn (array $e): array => [
            'name' => (string) ($e['Name'] ?? ''),
            'path' => trim($path.'/'.($e['Name'] ?? ''), '/'),
            'is_dir' => (bool) ($e['IsDir'] ?? false),
            'size' => (int) ($e['Size'] ?? 0),
        ], $entries);

        // Directories first, then alphabetical.
        usort($mapped, function (array $a, array $b): int {
            return [$b['is_dir'], strtolower($a['name'])]
                <=> [$a['is_dir'], strtolower($b['name'])];
        });

        return $mapped;
    }

    private function uniqueRemoteName(string $seed): string
    {
        $base = 'onedrive_'.Str::slug(Str::before($seed, '@'), '_');
        $name = $base;
        $i = 1;

        while (Account::query()->where('remote_name', $name)->exists()) {
            $name = $base.'_'.$i++;
        }

        return $name;
    }
}
