<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised when the Microsoft OAuth flow fails. */
class OAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $userMessageKey = 'errors.oauth_failed',
    ) {
        parent::__construct($message);
    }

    public static function stateMismatch(): self
    {
        return new self('OAuth state parameter mismatch (possible CSRF).', 'errors.oauth_state_mismatch');
    }

    public static function denied(): self
    {
        return new self('User denied the authorization request.', 'errors.oauth_denied');
    }

    public static function tokenExchangeFailed(string $detail): self
    {
        return new self("Token exchange failed: {$detail}", 'errors.oauth_token_exchange');
    }

    public static function refreshFailed(string $detail): self
    {
        return new self("Token refresh failed: {$detail}", 'errors.oauth_refresh_failed');
    }
}
