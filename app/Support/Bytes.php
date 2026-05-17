<?php

declare(strict_types=1);

namespace App\Support;

/** Locale-neutral human-readable byte formatting (SPEC §13). */
final class Bytes
{
    public static function human(?int $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        $value = $bytes / (1024 ** $pow);

        return number_format($value, $pow === 0 ? 0 : $precision).' '.$units[$pow];
    }
}
