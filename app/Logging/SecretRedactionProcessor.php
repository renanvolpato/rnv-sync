<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts OAuth tokens and other secrets from log records.
 *
 * SPEC §12 / §8: tokens, refresh tokens and client secrets must never reach
 * a log file even if a debug statement or rclone output would capture them.
 */
final class SecretRedactionProcessor implements ProcessorInterface
{
    private const REDACTED = '[REDACTED]';

    /** Context/extra keys whose value is always wiped. */
    private const SENSITIVE_KEYS = [
        'oauth_token', 'access_token', 'refresh_token', 'token',
        'client_secret', 'password', 'authorization',
    ];

    /** Inline patterns scrubbed from any message string. */
    private const PATTERNS = [
        // "access_token":"..." / "refresh_token":"..." in JSON-ish strings
        '/("?(?:access_token|refresh_token|token|client_secret|password)"?\s*[:=]\s*"?)([^"\s,}]+)/i',
        // rclone config token line: token = {...}
        '/(token\s*=\s*).+/i',
        // Bearer headers
        '/(Bearer\s+)[A-Za-z0-9._\-]+/i',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->scrubString($record->message);

        $context = $this->scrubArray($record->context);
        $extra = $this->scrubArray($record->extra);

        return $record->with(
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    private function scrubString(string $value): string
    {
        foreach (self::PATTERNS as $pattern) {
            $value = (string) preg_replace_callback($pattern, function (array $m): string {
                return ($m[1] ?? '').self::REDACTED;
            }, $value);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->scrubString($value);
            }
        }

        return $data;
    }
}
