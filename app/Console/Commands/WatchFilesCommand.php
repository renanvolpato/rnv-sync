<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DownloadPathJob;
use App\Jobs\PropagateDeleteJob;
use App\Jobs\SyncChangesJob;
use App\Models\SyncFolder;
use App\Services\Files\PendingOps;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Real-time uploader (OneDrive-style). Watches every active on-demand
 * folder with inotify and, a few seconds after the last change, runs
 * the lightweight two-way sync for that folder — so a saved file
 * reaches OneDrive in seconds instead of waiting for the 15-minute
 * scheduler (which stays on as a safety net).
 *
 * It also does best-effort "hydrate on open": when a single ☁ placeholder
 * is opened, it downloads it in the background. Linux has no Cloud Files
 * API to BLOCK the open and fetch content first (that needs FUSE), so the
 * first open still shows the file empty — it becomes real shortly after.
 * Only a lone open triggers it; a folder/thumbnailer scan that opens many
 * placeholders at once is ignored, so the whole drive is never downloaded.
 *
 * It also propagates LOCAL deletions to OneDrive (to its recycle bin), so a
 * deleted folder/file does not reappear from the cloud. Heavily guarded: it
 * ignores our own ops and temp files, only fires when the path is STILL gone
 * after a debounce (so "keep online", which recreates a placeholder instantly,
 * never deletes the cloud), and a sanity cap skips mass-disappearances.
 *
 * inotify is event-driven: this process is ~0% CPU until a file is touched.
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

    /** Quiet period after the last OPEN before deciding to hydrate. */
    private const HYDRATE_WINDOW_SECONDS = 2;

    /** Quiet period after the last delete before propagating it to the cloud.
     *  Long enough that a "keep online" (which deletes then instantly recreates
     *  a placeholder) settles back to "present" and is never propagated. */
    private const DELETE_WINDOW_SECONDS = 8;

    /** Paths we never react to (our own placeholders / vault / noise). */
    private const IGNORE_SUBSTRINGS = [
        '/Cofre Pessoal/', '/Personal Vault/', '/.Trash-1000/',
        '/.~', '/.goutputstream', '.rclone_chunk.', '.partial',
    ];

    public function handle(): int
    {
        // Wait quietly (no crash-loop) until inotify-tools is present;
        // re-check hourly so real-time sync self-activates once the
        // user installs it — no reboot or manual restart needed.
        $warned = false;
        while (Process::run(['bash', '-lc', 'command -v inotifywait'])->failed()) {
            if (! $warned) {
                $this->warn('inotify-tools (inotifywait) not installed — '
                    .'real-time sync is paused. The 15-minute scheduled '
                    .'sync still runs. Will auto-start once it is installed.');
                $warned = true;
            }
            sleep(3600);
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

        // Our own download/keep-online in progress for this path — OR for any
        // ancestor folder of it. The latter is critical for delete-propagation:
        // "keep online" of a whole FOLDER deletes its children locally, and we
        // must NOT mistake those for user deletions and purge the cloud copies.
        $p = rtrim($path, '/');
        if (PendingOps::has($p)) {
            return true;
        }
        while (($p = dirname($p)) !== '' && $p !== '/' && $p !== '.') {
            if (PendingOps::has($p)) {
                return true;
            }
        }

        return false;
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

    /** True for a lone OPEN event (a hydrate request), not an open paired
     *  with a create/write/move/delete (which is a real change). */
    public function isOpenEvent(string $events): bool
    {
        return str_contains($events, 'OPEN')
            && ! preg_match('/CREATE|CLOSE_WRITE|MODIFY|MOVED|DELETE/', $events);
    }

    /** A cloud-only item: a real, 0-byte placeholder file on disk. */
    public function isZeroBytePlaceholder(string $path): bool
    {
        return is_file($path) && @filesize($path) === 0;
    }

    /** A removal at this location (deleted or moved away). */
    public function isDeleteEvent(string $events): bool
    {
        return (bool) preg_match('/\bDELETE\b|\bMOVED_FROM\b/', $events);
    }

    /** inotify marks directory events with ISDIR. */
    public function isDirEvent(string $events): bool
    {
        return str_contains($events, 'ISDIR');
    }

    /**
     * Drop any path that has an ancestor also in the set: deleting (purging)
     * the parent already covers its children, so we never emit a delete per
     * child of a removed folder.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function collapseChildPaths(array $paths): array
    {
        sort($paths); // ancestors sort before their descendants
        $kept = [];
        foreach ($paths as $p) {
            $covered = false;
            foreach ($kept as $k) {
                if (str_starts_with($p, rtrim($k, '/').'/')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $kept[] = $p;
            }
        }

        return $kept;
    }

    /** @param array<int,string> $folders */
    private function watch(array $folders): void
    {
        $hydrate = (bool) config('rnvsync.sync.hydrate_on_open', true);
        $propagate = (bool) config('rnvsync.sync.propagate_deletes', true);

        $events = ['-e', 'close_write', '-e', 'create',
            '-e', 'moved_to', '-e', 'moved_from', '-e', 'delete'];
        if ($hydrate) {
            $events[] = '-e';
            $events[] = 'open';
        }

        $proc = proc_open(
            array_merge(
                ['inotifywait', '-m', '-r', '-q', ...$events, '--format', '%e|%w%f'],
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
        /** @var array<string,float> $opened placeholder path => last-open epoch */
        $opened = [];
        /** @var array<string,float> $deleted removed path => last-event epoch */
        $deleted = [];
        /** @var array<string,bool> $deletedDir removed path => was a directory */
        $deletedDir = [];
        $lastRefresh = microtime(true);

        while (true) {
            if (proc_get_status($proc)['running'] === false) {
                break; // inotifywait died → let the loop respawn it
            }

            $read = [$pipes[1]];
            $w = $e = null;
            if (stream_select($read, $w, $e, 1) > 0) {
                while (($line = fgets($pipes[1])) !== false) {
                    $line = trim($line);
                    if ($line === '' || ! str_contains($line, '|')) {
                        continue;
                    }
                    [$evt, $path] = explode('|', $line, 2);
                    if ($path === '' || $this->shouldIgnore($path)) {
                        continue;
                    }

                    // A lone OPEN never marks a folder dirty (it isn't a
                    // change). For a 0-byte placeholder it's a hydrate request.
                    if ($this->isOpenEvent($evt)) {
                        if ($hydrate && $this->isZeroBytePlaceholder($path)
                            && $this->folderIdForPath($path, $folders) !== null) {
                            $opened[$path] = microtime(true);
                        }

                        continue;
                    }

                    if ($id = $this->folderIdForPath($path, $folders)) {
                        $dirty[$id] = microtime(true);

                        // A removal here may need to be mirrored to the cloud.
                        if ($propagate && $this->isDeleteEvent($evt)) {
                            $deleted[$path] = microtime(true);
                            $deletedDir[$path] = $this->isDirEvent($evt);
                        }
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

            // Flush the opened-placeholder buffer once it has gone quiet: a
            // single file opened on its own → hydrate it; a burst (a folder /
            // thumbnailer scan opening many at once) → ignore, so we never
            // mass-download the whole drive just because a folder was browsed.
            if ($opened !== [] && $now - max($opened) >= self::HYDRATE_WINDOW_SECONDS) {
                $batch = array_keys($opened);
                $opened = [];
                if (count($batch) <= self::maxHydrateBatch()) {
                    foreach ($batch as $abs) {
                        $this->hydrate($abs, $folders);
                    }
                } else {
                    $this->info('Skipped hydrate-on-open for '.count($batch).' files (folder scan, not a deliberate open).');
                }
            }

            // Flush the deletion buffer once quiet: mirror to the cloud
            // (recycle bin) only the paths STILL gone — so "keep online",
            // which recreates a placeholder at once, is never propagated.
            if ($deleted !== [] && $now - max($deleted) >= self::DELETE_WINDOW_SECONDS) {
                $this->flushDeletions($deleted, $deletedDir, $folders);
                $deleted = [];
                $deletedDir = [];
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

    private static function maxHydrateBatch(): int
    {
        return max(1, (int) config('rnvsync.sync.hydrate_max_batch', 1));
    }

    /**
     * Best-effort download of a single opened placeholder, with a desktop
     * toast. Re-checks at flush time so it never clobbers a file the user
     * just typed into, nor double-downloads one already in flight.
     *
     * @param  array<int,string>  $folders
     */
    private function hydrate(string $abs, array $folders): void
    {
        if (! $this->isZeroBytePlaceholder($abs) || PendingOps::has($abs)) {
            return;
        }

        $id = $this->folderIdForPath($abs, $folders);
        if ($id === null) {
            return;
        }

        $folder = SyncFolder::with('account')->find($id);
        if (! $folder || ! $folder->account) {
            return;
        }

        $base = rtrim(app(SettingsRepository::class)->mountBase(), '/').'/'.$folder->account->name;
        if (! str_starts_with($abs, $base.'/')) {
            return;
        }
        $rel = ltrim(substr($abs, strlen($base)), '/');

        PendingOps::mark($abs);
        DownloadPathJob::dispatch($folder->account->id, $rel);
        $this->notify(basename($abs));
        $this->info("Hydrate-on-open: downloading {$rel}");
    }

    /**
     * Decide which buffered deletions to mirror to the cloud and queue them.
     * Guards: the path must still be gone, not be one of our own ops, resolve
     * to an active folder; children are collapsed under a deleted parent; and a
     * mass-disappearance (over the cap) is skipped as a likely bug, never purged.
     *
     * @param  array<string,float>  $deleted  path => last-event epoch
     * @param  array<string,bool>  $deletedDir  path => was a directory
     * @param  array<int,string>  $folders
     */
    private function flushDeletions(array $deleted, array $deletedDir, array $folders): void
    {
        $gone = [];
        foreach (array_keys($deleted) as $path) {
            if (! file_exists($path)
                && ! $this->shouldIgnore($path)
                && $this->folderIdForPath($path, $folders) !== null) {
                $gone[] = $path;
            }
        }
        if ($gone === []) {
            return;
        }

        $gone = $this->collapseChildPaths($gone);

        $cap = max(1, (int) config('rnvsync.sync.propagate_deletes_cap', 50));
        if (count($gone) > $cap) {
            $this->warn('Skipped propagating '.count($gone).' deletions (over the safety cap — looks like a bug/scan, not a deliberate delete).');

            return;
        }

        foreach ($gone as $path) {
            $this->propagateDelete($path, $deletedDir[$path] ?? false, $folders);
        }
    }

    /**
     * Queue a cloud-side deletion (to the recycle bin) for one removed path.
     *
     * @param  array<int,string>  $folders
     */
    private function propagateDelete(string $abs, bool $isDir, array $folders): void
    {
        $id = $this->folderIdForPath($abs, $folders);
        if ($id === null) {
            return;
        }

        $folder = SyncFolder::with('account')->find($id);
        if (! $folder || ! $folder->account) {
            return;
        }

        $base = rtrim(app(SettingsRepository::class)->mountBase(), '/').'/'.$folder->account->name;
        if (! str_starts_with($abs, $base.'/')) {
            return;
        }
        $rel = ltrim(substr($abs, strlen($base)), '/');
        if ($rel === '') {
            return;
        }

        PropagateDeleteJob::dispatch($folder->account->id, $rel, $isDir);
        $this->info("Propagating delete to OneDrive recycle bin: {$rel}");
    }

    /** Best-effort desktop notification (no-op where notify-send is absent). */
    private function notify(string $name): void
    {
        try {
            Process::timeout(5)->run([
                'notify-send', '-a', 'RNV Sync', '-i', 'folder-download',
                'RNV Sync', "Baixando “{$name}”…",
            ]);
        } catch (\Throwable) {
            // No desktop notifications here — ignore.
        }
    }
}
