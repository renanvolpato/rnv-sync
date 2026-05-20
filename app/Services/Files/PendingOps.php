<?php

declare(strict_types=1);

namespace App\Services\Files;

use Illuminate\Support\Facades\File;

/**
 * Tracks in-flight download/free operations by absolute local path so
 * both the web file browser and the Nautilus extension can show a
 * "syncing…" state instead of a stale cloud/✓ emblem until the
 * operation finishes.
 *
 * Backed by a small JSON file (the extension reads it too).
 */
class PendingOps
{
    public static function file(): string
    {
        return storage_path('app/rnvsync-pending.json');
    }

    /** @return list<string> */
    public static function all(): array
    {
        $f = self::file();

        if (! is_file($f)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($f), true);

        return is_array($data) ? array_values(array_unique($data)) : [];
    }

    public static function mark(string $absPath): void
    {
        $list = self::all();
        $list[] = rtrim($absPath, '/');
        self::write(array_values(array_unique($list)));
    }

    public static function clear(string $absPath): void
    {
        $abs = rtrim($absPath, '/');
        self::write(array_values(array_filter(
            self::all(),
            fn (string $p) => $p !== $abs,
        )));
    }

    public static function has(string $absPath): bool
    {
        return in_array(rtrim($absPath, '/'), self::all(), true);
    }

    /**
     * Drop entries whose local path no longer exists on disk — a job
     * that crashed or test garbage that leaked otherwise pins the
     * tray icon on "syncing…" forever. Returns the count removed.
     */
    public static function sweepStale(): int
    {
        $list = self::all();
        $live = array_values(array_filter($list, fn (string $p) => file_exists($p)));
        $dropped = count($list) - count($live);
        if ($dropped > 0) {
            self::write($live);
        }

        return $dropped;
    }

    /** @param list<string> $list */
    private static function write(array $list): void
    {
        File::ensureDirectoryExists(dirname(self::file()));
        File::put(self::file(), json_encode($list));
    }
}
