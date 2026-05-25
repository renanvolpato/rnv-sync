<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Stops downloads from filling a small disk. "Keep local" on a big folder (or a
 * pile of queued downloads) can otherwise download more than the SSD holds.
 * Before each download we check the target filesystem and skip if it is past
 * the configured fill threshold (RNVSYNC_DOWNLOAD_MAX_DISK_PERCENT, default 95%).
 * Fail-open: if disk figures can't be read, downloads are allowed.
 */
class DiskGuard
{
    /** Percentage of the filesystem holding $path that is currently used. */
    public static function usedPercent(string $path): float
    {
        $dir = is_dir($path) ? $path : dirname($path);
        while ($dir !== '/' && $dir !== '' && ! is_dir($dir)) {
            $dir = dirname($dir);
        }

        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);
        if (! is_float($total) && ! is_int($total)) {
            return 0.0; // unreadable → don't block
        }
        if ($total <= 0) {
            return 0.0;
        }

        return (1 - $free / $total) * 100;
    }

    /** True if there is room to download into $path (under the fill threshold). */
    public static function hasRoom(string $path): bool
    {
        $max = (float) config('rnvsync.sync.download_max_disk_percent', 95);
        if ($max <= 0 || $max >= 100) {
            return true; // guard disabled / misconfigured → never block
        }

        return self::usedPercent($path) < $max;
    }
}
