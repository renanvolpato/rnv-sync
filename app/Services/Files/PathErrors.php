<?php

declare(strict_types=1);

namespace App\Services\Files;

use Illuminate\Support\Facades\File;

/**
 * Records the last failure for a download/keep-online operation, keyed
 * by absolute local path, so the UI shows an explicit "error" state
 * (with the reason on hover) instead of silently reverting to ☁.
 */
class PathErrors
{
    public static function file(): string
    {
        return storage_path('app/rnvsync-errors.json');
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $f = self::file();
        if (! is_file($f)) {
            return [];
        }
        $d = json_decode((string) @file_get_contents($f), true);

        return is_array($d) ? $d : [];
    }

    public static function mark(string $absPath, string $message): void
    {
        $all = self::all();
        $all[rtrim($absPath, '/')] = mb_substr(trim($message), 0, 300);
        self::write($all);
    }

    public static function clear(string $absPath): void
    {
        $all = self::all();
        unset($all[rtrim($absPath, '/')]);
        self::write($all);
    }

    public static function has(string $absPath): bool
    {
        return array_key_exists(rtrim($absPath, '/'), self::all());
    }

    public static function get(string $absPath): ?string
    {
        return self::all()[rtrim($absPath, '/')] ?? null;
    }

    /** @param array<string,string> $all */
    private static function write(array $all): void
    {
        File::ensureDirectoryExists(dirname(self::file()));
        File::put(self::file(), json_encode($all));
    }
}
