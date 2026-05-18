<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Exceptions\OAuthException;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Zero-config OneDrive sign-in using rclone's own OAuth
 * (`rclone authorize "onedrive"`). This reuses rclone's public
 * application and its already-registered loopback redirect
 * (http://127.0.0.1:53682), so the user does NOT have to register a
 * Microsoft Entra app.
 *
 * Flow: spawn `rclone authorize`, read the auth URL it prints, send the
 * user there; rclone's local server catches the callback and prints the
 * token, which we then capture and store.
 */
class RcloneAuthorize
{
    public function __construct(private readonly RcloneRunner $rclone) {}

    private function dir(): string
    {
        return storage_path('app/oauth');
    }

    private function logPath(string $session): string
    {
        return $this->dir().'/'.$session.'.log';
    }

    /**
     * Start the rclone authorize process and return the session id plus
     * the Microsoft sign-in URL to send the user to.
     *
     * @return array{session:string,auth_url:string}
     *
     * @throws OAuthException
     */
    public function start(): array
    {
        File::ensureDirectoryExists($this->dir());

        $session = Str::random(32);
        $log = $this->logPath($session);
        File::put($log, '');

        $this->rclone->runBackgroundLogged(['authorize', 'onedrive'], $log, jsonLog: false);

        // rclone prints the auth URL within ~1–2s; poll briefly.
        for ($i = 0; $i < 30; $i++) {
            $url = $this->parseAuthUrl((string) @file_get_contents($log));
            if ($url !== null) {
                return ['session' => $session, 'auth_url' => $url];
            }
            usleep(300_000);
        }

        throw new OAuthException(
            'rclone did not produce an authorization URL in time.',
            'errors.oauth_failed',
        );
    }

    /**
     * Poll a session. Returns one of:
     *  ['state' => 'pending']
     *  ['state' => 'ready', 'token' => array]
     *  ['state' => 'error', 'message' => string]
     *
     * @return array{state:string,token?:array<string,mixed>,message?:string}
     */
    public function status(string $session): array
    {
        $log = $this->logPath($session);

        if (! is_file($log)) {
            return ['state' => 'error', 'message' => 'Unknown session.'];
        }

        $output = (string) @file_get_contents($log);
        $token = $this->parseToken($output);

        if ($token !== null) {
            @unlink($log);

            return ['state' => 'ready', 'token' => $token];
        }

        if (str_contains($output, 'Failed to') || str_contains(strtolower($output), 'error')) {
            return ['state' => 'error', 'message' => 'Authorization failed. Please try again.'];
        }

        return ['state' => 'pending'];
    }

    /** Extract rclone's loopback auth URL from its output. */
    public function parseAuthUrl(string $output): ?string
    {
        if (preg_match('#https?://(?:127\.0\.0\.1|localhost):53682/auth\?\S+#', $output, $m)) {
            return rtrim($m[0], '.');
        }

        return null;
    }

    /**
     * Extract the rclone token JSON. rclone prints it between
     * "--->" and "<---End paste".
     *
     * @return array<string,mixed>|null
     */
    public function parseToken(string $output): ?array
    {
        if (preg_match('/--->\s*(\{.*?\})\s*<---End paste/s', $output, $m)) {
            $json = $m[1];
        } elseif (preg_match('/\{[^{}]*"access_token"[^{}]*\}/s', $output, $m)) {
            $json = $m[0];
        } else {
            return null;
        }

        $decoded = json_decode(trim($json), true);

        return is_array($decoded) && isset($decoded['access_token']) ? $decoded : null;
    }
}
