<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Update\UpdateService;
use Illuminate\Console\Command;

/**
 * Background update check (scheduled twice a day). Refreshes the
 * cached status so the panel shows an "update available" badge
 * without the user clicking anything and without hitting the
 * network on page loads.
 */
class CheckUpdatesCommand extends Command
{
    protected $signature = 'rnvsync:check-updates';

    protected $description = 'Check the git remote for updates and cache the result';

    public function handle(UpdateService $updater): int
    {
        $s = $updater->checkForUpdates(force: true);

        $this->info(match (true) {
            $s['error'] === 'not_git' => 'Not a git install — skipped.',
            $s['error'] !== null => 'Update check failed (network?).',
            $s['available'] => "Update available: {$s['behind']} commit(s) behind.",
            default => 'Up to date.',
        });

        return self::SUCCESS;
    }
}
