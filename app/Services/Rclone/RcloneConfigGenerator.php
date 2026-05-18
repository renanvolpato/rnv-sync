<?php

declare(strict_types=1);

namespace App\Services\Rclone;

use App\Models\Account;
use Illuminate\Support\Facades\File;

/**
 * Generates rclone.conf from the accounts table (SPEC §8
 * "rclone Config File Management"). The user never edits this file.
 *
 * Regenerated on startup and after any account change. Written with
 * 0600 permissions (SPEC §12).
 */
class RcloneConfigGenerator
{
    public function __construct(private readonly RcloneBinary $binary) {}

    /**
     * Rewrite the rclone config file from current accounts.
     *
     * @return string The path to the written config file.
     */
    public function regenerate(): string
    {
        $path = $this->binary->configPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->build());

        // Owner read/write only — config holds decrypted tokens.
        @chmod($path, 0600);

        return $path;
    }

    /** Build the INI content for all active accounts. */
    public function build(): string
    {
        $clientId = (string) config('rnvsync.oauth.client_id');

        $blocks = Account::query()
            ->where('status', Account::STATUS_ACTIVE)
            ->get()
            ->map(fn (Account $account): string => $this->renderRemote($account, $clientId))
            ->all();

        return implode("\n", $blocks)."\n";
    }

    private function renderRemote(Account $account, string $clientId): string
    {
        $token = $account->oauth_token ?: '{}';

        $driveType = $account->drive_type ?: match ($account->provider) {
            'onedrive_business' => 'business',
            'sharepoint' => 'documentLibrary',
            default => 'personal',
        };

        return implode("\n", array_filter([
            "[{$account->remote_name}]",
            'type = onedrive',
            // Zero-config accounts use a token issued to rclone's built-in
            // client — pinning a client_id here would mismatch it.
            $account->uses_bundled_client ? null : "client_id = {$clientId}",
            $account->drive_id ? "drive_id = {$account->drive_id}" : null,
            "drive_type = {$driveType}",
            "token = {$token}",
        ]));
    }
}
