<?php

declare(strict_types=1);

namespace App\Services\Rclone;

/** Immutable result of an rclone invocation. */
final class RcloneResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Decode stdout as JSON (used for `lsjson`, `about --json`, etc.).
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->stdout, true);

        return is_array($decoded) ? $decoded : null;
    }
}
