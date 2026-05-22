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

        // Atomic write: render into a temp file in the same directory,
        // set 0600 (the config holds decrypted tokens), then rename over
        // the target. rename(2) is atomic on the same filesystem, so a
        // concurrent rclone — or a crash mid-write — never observes a
        // truncated config. A partial config fails every transfer with
        // "didn't find section in config file" and breaks ALL syncing
        // until the next regenerate; the plain truncate-then-write this
        // replaces had exactly that race (queue + scheduler + web can all
        // regenerate at once).
        $content = $this->build();
        $tmp = $path.'.tmp.'.getmypid();
        File::put($tmp, $content);
        @chmod($tmp, 0600);

        if (! @rename($tmp, $path)) {
            // Fallback (e.g. cross-device): write in place rather than
            // leaving the freshly-built config unwritten.
            File::put($path, $content);
            @chmod($path, 0600);
            @unlink($tmp);
        }

        return $path;
    }

    /** Build the INI content. Includes active accounts plus
     *  bundled-client accounts (rclone refreshes those itself, so they
     *  can self-heal from a transient disconnect). */
    public function build(): string
    {
        $clientId = (string) config('rnvsync.oauth.client_id');
        $existing = $this->readConfTokens();

        $blocks = Account::query()
            ->where(fn ($q) => $q->where('status', Account::STATUS_ACTIVE)
                ->orWhere('uses_bundled_client', true))
            ->get()
            ->map(fn (Account $account): string => $this->renderRemote($account, $clientId, $existing))
            ->all();

        return implode("\n", $blocks)."\n";
    }

    /**
     * Current `token = {...}` per remote in the existing rclone.conf.
     *
     * @return array<string,string>
     */
    public function readConfTokens(): array
    {
        $path = $this->binary->configPath();
        if (! is_file($path)) {
            return [];
        }

        $tokens = [];
        $current = null;
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^\[(.+)\]\s*$/', $line, $m)) {
                $current = $m[1];
            } elseif ($current !== null && preg_match('/^token\s*=\s*(.+)$/', $line, $m)) {
                $tokens[$current] = trim($m[1]);
            }
        }

        return $tokens;
    }

    /**
     * Persist rclone's (possibly refreshed) token back into the DB so it
     * doesn't get clobbered by a stale stored token. Bundled accounts
     * only — rclone owns their token lifecycle.
     */
    public function syncTokenBack(Account $account): void
    {
        if (! $account->uses_bundled_client) {
            return;
        }

        $confToken = $this->readConfTokens()[$account->remote_name] ?? null;

        if ($confToken && $confToken !== '{}' && $confToken !== $account->oauth_token) {
            $account->forceFill(['oauth_token' => $confToken])->save();
        }
    }

    /**
     * @param  array<string,string>  $existing
     */
    private function renderRemote(Account $account, string $clientId, array $existing = []): string
    {
        // For bundled accounts, prefer the token already in rclone.conf
        // (rclone may have just refreshed it) over the stored one.
        $token = ($account->uses_bundled_client && ! empty($existing[$account->remote_name]))
            ? $existing[$account->remote_name]
            : ($account->oauth_token ?: '{}');

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
