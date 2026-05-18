<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncChangesJob;
use App\Models\SyncFolder;
use App\Services\Files\PendingOps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Real-time uploader (OneDrive-style). Watches every active on-demand
 * folder with inotify and, a few seconds after the last change, runs
 * the lightweight two-way sync for that folder — so a saved file
 * reaches OneDrive in seconds instead of waiting for the 15-minute
 * scheduler (which stays on as a safety net).
 *
 * inotify is event-driven: this process is ~0% CPU until a file
 * actually changes, then it does one small debounced sync.
 */
class WatchFilesCommand extends Command
{
    protected $signature = 'rnvsync:watch';

    protected $description = 'Watch synced folders and push changes to OneDrive in real time';

    /** Quiet period after the last change before syncing a folder. */
    private const DEBOUNCE_SECONDS = 4;

    /** Never re-sync the same folder more often than this. */
    private const MIN_INTERVAL_SECONDS = 10;

    /** Re-read the active-folder set this often (folders change rarely). */
    private const REFRESH_SECONDS = 60;

    /** Paths we never react to (our own placeholders / vault / noise). */
    private const IGNORE_SUBSTRINGS = [
        '/Cofre Pessoal/', '/Personal Vault/', '/.Trash-1000/',
        '/.~', '/.goutputstream', '.rclone_chunk.', '.partial',
    ];

    public function handle(): int
    {
        if (Process::run(['bash', '-lc', 'command -v inotifywait'])->failed()) {
            $this->error('inotify-tools (inotifywait) is not installed — real-time '
                .'sync is off. Install it and restart rnv-sync-watch. '
                .'The 15-minute scheduled sync still runs.');
            sleep(60); // avoid a tight systemd restart loop

            return self::FAILURE;
        }

        // Long-lived: re-evaluate the folder set and (re)attach the
        // watcher forever. Returns only on a fatal error.
        while (true) {
            $folders = $this->activeFolders();
            if ($folders === []) {
                sleep(self::REFRESH_SECONDS);

                continue;
            }

            $this->info('Watching '.count($folders).' folder(s) for changes…');
            $this->watch($folders);
            // watch() returns when the folder set changed or
            // inotifywait died — loop straight back and re-attach.
        }
    }

    /** @return array<int,string> folderId => existing local_path */
    public function activeFolders(): array
    {
        $out = [];
        foreach (SyncFolder::query()
            ->where('is_active', true)
            ->where('sync_mode', 'on_demand')
            ->get(['id', 'local_path']) as $f) {
            if (is_dir($f->local_path)) {
                $out[$f->id] = rtrim($f->local_path, '/');
            }
        }

        return $out;
    }

    /** True for paths that must not trigger a sync. */
    public function shouldIgnore(string $path): bool
    {
        foreach (self::IGNORE_SUBSTRINGS as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        // Our own download/keep-online in progress for this path.
        return PendingOps::has(rtrim($path, '/'));
    }

    /**
     * Longest-prefix match of a changed path to its folder id.
     *
     * @param  array<int,string>  $folders  folderId => local_path
     */
    public function folderIdForPath(string $path, array $folders): ?int
    {
        $match = null;
        $matchLen = -1;
        foreach ($folders as $id => $base) {
            if (($path === $base || str_starts_with($path, $base.'/'))
                && strlen($base) > $matchLen) {
                $match = $id;
                $matchLen = strlen($base);
            }
        }

        return $match;
    }

    /** @param array<int,string> $folders */
    private function watch(array $folders): void
    {
        $proc = proc_open(
            array_merge(
                ['inotifywait', '-m', '-r', '-q',
                    '-e', 'close_write', '-e', 'create',
                    '-e', 'moved_to', '-e', 'moved_from', '-e', 'delete',
                    '--format', '%w%f'],
                array_values($folders),
            ),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($proc)) {
            $this->error('Could not start inotifywait.');
            sleep(30);

            return;
        }

        stream_set_blocking($pipes[1], false);

        /** @var array<int,float> $dirty folderId => last-event epoch */
        $dirty = [];
        /** @var array<int,float> $lastSync folderId => last dispatch epoch */
        $lastSync = [];
        $lastRefresh = microtime(true);

        while (true) {
            if (proc_get_status($proc)['running'] === false) {
                break; // inotifywait died → let the loop respawn it
            }

            $read = [$pipes[1]];
            $w = $e = null;
            if (stream_select($read, $w, $e, 1) > 0) {
                while (($line = fgets($pipes[1])) !== false) {
                    $path = trim($line);
                    if ($path === '' || $this->shouldIgnore($path)) {
                        continue;
                    }
                    if ($id = $this->folderIdForPath($path, $folders)) {
                        $dirty[$id] = microtime(true);
                    }
                }
            }

            $now = microtime(true);
            foreach ($dirty as $id => $changedAt) {
                if ($now - $changedAt < self::DEBOUNCE_SECONDS) {
                    continue;
                }
                if ($now - ($lastSync[$id] ?? 0) < self::MIN_INTERVAL_SECONDS) {
                    continue;
                }
                SyncChangesJob::dispatch($id);
                $lastSync[$id] = $now;
                unset($dirty[$id]);
            }

            // Pick up folders that were just (un)synced elsewhere.
            if ($now - $lastRefresh >= self::REFRESH_SECONDS) {
                $lastRefresh = $now;
                if ($this->activeFolders() !== $folders) {
                    break; // restart with the new set
                }
            }
        }

        foreach ($pipes as $p) {
            is_resource($p) && fclose($p);
        }
        proc_terminate($proc);
        proc_close($proc);
    }
}
