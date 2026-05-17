<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised when an rclone invocation fails or is misconfigured. */
class RcloneException extends RuntimeException
{
    public static function binaryMissing(string $path): self
    {
        return new self("rclone binary not found or not executable at: {$path}");
    }

    public static function commandFailed(string $command, int $exitCode, string $stderr): self
    {
        return new self("rclone command failed (exit {$exitCode}): {$command}\n{$stderr}");
    }
}
