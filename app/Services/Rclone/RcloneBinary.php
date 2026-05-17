<?php

declare(strict_types=1);

namespace App\Services\Rclone;

use App\Exceptions\RcloneException;

/**
 * Resolves the path to the bundled rclone binary.
 *
 * rclone is bundled with the app, never taken from the user's PATH
 * (CLAUDE.md §6, SPEC §8).
 */
class RcloneBinary
{
    public function path(): string
    {
        return (string) config('rnvsync.rclone.binary_path');
    }

    public function configPath(): string
    {
        return (string) config('rnvsync.rclone.config_path');
    }

    public function isAvailable(): bool
    {
        $path = $this->path();

        return is_file($path) && is_executable($path);
    }

    /**
     * @throws RcloneException
     */
    public function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw RcloneException::binaryMissing($this->path());
        }
    }

    /** Installed rclone version string, or null if unavailable. */
    public function version(): ?string
    {
        return $this->isAvailable() ? (string) config('rnvsync.rclone.version') : null;
    }
}
