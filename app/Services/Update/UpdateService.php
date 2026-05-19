<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Self-update: check the git remote for newer commits and apply them
 * by launching install/update.sh detached (so it survives the web
 * service restarting itself mid-update). One-click, no terminal.
 */
class UpdateService
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? base_path();
    }

    /** Only meaningful when the install is a git clone. */
    public function isGitInstall(): bool
    {
        return is_dir($this->dir.'/.git');
    }

    /** Short SHA the app is currently running. */
    public function currentRef(): string
    {
        return trim($this->git('rev-parse --short HEAD')->output()) ?: 'unknown';
    }

    /**
     * Last known status without ever touching the network — safe to
     * call from render(). Returns null if no check ran yet.
     *
     * @return array<string,mixed>|null
     */
    public function cachedStatus(): ?array
    {
        return Cache::get('rnvsync.update.status');
    }

    /**
     * Fetch the remote and report whether we are behind. Cached for a
     * few minutes so the Settings page doesn't hit the network on
     * every render. Pass force=true for an explicit "check now".
     *
     * @return array{available:bool,behind:int,current:string,latest:string,commits:list<string>,error:?string,checked_at:string}
     */
    public function checkForUpdates(bool $force = false): array
    {
        $key = 'rnvsync.update.status';

        if (! $force && ($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $base = [
            'available' => false, 'behind' => 0,
            'current' => $this->currentRef(), 'latest' => '',
            'commits' => [], 'error' => null,
            'checked_at' => now()->toDateTimeString(),
        ];

        if (! $this->isGitInstall()) {
            $base['error'] = 'not_git';

            return tap($base, fn ($s) => Cache::put($key, $s, now()->addMinutes(10)));
        }

        $fetch = $this->git('fetch --quiet', 60);
        if (! $fetch->successful()) {
            $base['error'] = 'fetch_failed';

            return tap($base, fn ($s) => Cache::put($key, $s, now()->addMinutes(2)));
        }

        // Upstream of the current branch (set by `git clone`).
        $behind = (int) trim($this->git('rev-list --count HEAD..@{u}')->output());
        $base['behind'] = $behind;
        $base['available'] = $behind > 0;
        $base['latest'] = trim($this->git('rev-parse --short @{u}')->output());

        if ($behind > 0) {
            $log = $this->git('log --no-merges --format=%s HEAD..@{u}')->output();
            $base['commits'] = array_slice(array_filter(
                array_map('trim', explode("\n", trim($log)))
            ), 0, 10);
        }

        return tap($base, fn ($s) => Cache::put($key, $s, now()->addMinutes(10)));
    }

    /**
     * Launch the updater fully detached: setsid + login shell (so
     * composer/npm/git are on PATH) so it keeps running after
     * update.sh restarts the web service. Returns immediately.
     */
    public function runUpdate(): void
    {
        Cache::forget('rnvsync.update.status');
        $cmd = $this->updateCommand();
        // Fire-and-forget; intentionally not awaited.
        @exec($cmd.' >/dev/null 2>&1 &');
    }

    /** The detached shell command (separated out so it is testable). */
    public function updateCommand(): string
    {
        $script = $this->dir.'/install/update.sh';
        $log = $this->dir.'/storage/logs/update.log';
        $inner = sprintf('bash %s >> %s 2>&1', escapeshellarg($script), escapeshellarg($log));

        return 'setsid bash -lc '.escapeshellarg($inner);
    }

    private function git(string $args, int $timeout = 15): ProcessResult
    {
        return Process::path($this->dir)->timeout($timeout)->run('git '.$args);
    }
}
