<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Update\UpdateService;
use Livewire\Component;

/**
 * Compact "check for updates / update now" control for the app header, so
 * updating doesn't require digging into Settings. Mirrors the Settings page
 * actions (same UpdateService); both stay available.
 */
class UpdateChecker extends Component
{
    /** @var array<string,mixed>|null last known status (no network on mount) */
    public ?array $status = null;

    public bool $isGit = true;

    public function mount(UpdateService $updater): void
    {
        $this->isGit = $updater->isGitInstall();
        $this->status = $updater->cachedStatus();
    }

    /** Explicit "check now" — hits the network, then caches. */
    public function check(UpdateService $updater): void
    {
        $this->status = $updater->checkForUpdates(force: true);

        $msg = match (true) {
            $this->status['error'] === 'not_git' => __('settings.update_not_git'),
            $this->status['error'] !== null => __('settings.update_check_failed'),
            $this->status['available'] => __('settings.update_available', ['n' => $this->status['behind']]),
            default => __('settings.update_up_to_date'),
        };

        $this->dispatch('toast', type: $this->status['error'] ? 'error' : 'success', message: $msg);
    }

    /** Apply updates: launch the detached updater and tell the user. */
    public function apply(UpdateService $updater): void
    {
        if (! $updater->isGitInstall()) {
            $this->dispatch('toast', type: 'error', message: __('settings.update_not_git'));

            return;
        }

        $updater->runUpdate();
        $this->status = null;
        $this->dispatch('toast', type: 'success', message: __('settings.update_started'));
    }

    public function render()
    {
        return view('livewire.update-checker');
    }
}
