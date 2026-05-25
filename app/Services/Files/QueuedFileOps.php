<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Jobs\DownloadPathJob;
use App\Jobs\FreeOnlineJob;
use Illuminate\Support\Facades\DB;

/**
 * Cancels QUEUED (not-yet-started) file ops that a newer, opposite action makes
 * pointless. The motivating bug: marking a folder "keep local" queues a big
 * recursive download; switching it back to "online" used to leave those
 * downloads in the FIFO queue, so they kept running and filling the disk before
 * the "free" ever ran. Now switching online drops the pending downloads (and
 * vice-versa). A download already RUNNING can't be unqueued — the disk guard
 * (see DiskGuard) bounds that case.
 */
class QueuedFileOps
{
    /** Drop pending downloads for $path and everything under it. Returns count. */
    public static function cancelDownloadsUnder(int $accountId, string $path): int
    {
        return self::purge(DownloadPathJob::class, $accountId, $path);
    }

    /** Drop pending "keep online" frees for $path and everything under it. */
    public static function cancelFreesUnder(int $accountId, string $path): int
    {
        return self::purge(FreeOnlineJob::class, $accountId, $path);
    }

    private static function purge(string $jobClass, int $accountId, string $path): int
    {
        $needle = class_basename($jobClass);
        $removed = 0;

        // Pre-filter by class name in the JSON payload, then confirm by
        // unwrapping the serialized job (these jobs hold only scalars).
        foreach (DB::table('jobs')->where('payload', 'like', '%'.$needle.'%')->get(['id', 'payload']) as $row) {
            $command = json_decode($row->payload, true)['data']['command'] ?? null;
            if (! is_string($command)) {
                continue;
            }

            try {
                $job = unserialize($command);
            } catch (\Throwable) {
                continue;
            }

            if ($job instanceof $jobClass
                && ($job->accountId ?? null) === $accountId
                && self::isUnder($job->path ?? '', $path)) {
                $removed += DB::table('jobs')->where('id', $row->id)->delete();
            }
        }

        return $removed;
    }

    /** True if $candidate equals $prefix or is nested under it ('' = whole account). */
    private static function isUnder(string $candidate, string $prefix): bool
    {
        $candidate = trim($candidate, '/');
        $prefix = trim($prefix, '/');

        return $prefix === '' || $candidate === $prefix || str_starts_with($candidate, $prefix.'/');
    }
}
