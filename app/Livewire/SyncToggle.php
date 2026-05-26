<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Sync\SyncService;
use Livewire\Component;

/**
 * Header control to pause/resume syncing globally — the obvious "stop/start the
 * cloud" button a user expects, available on every page. (The tray's "Sair" only
 * closes the icon; this is the real on/off, flipping SyncService's sync_paused,
 * which the scheduled sync and sync jobs already respect.)
 */
class SyncToggle extends Component
{
    public bool $paused = false;

    public function mount(SyncService $sync): void
    {
        $this->paused = $sync->isPaused();
    }

    public function toggle(SyncService $sync): void
    {
        $this->paused = ! $this->paused;
        $sync->setPaused($this->paused);

        $this->dispatch(
            'toast',
            type: $this->paused ? 'warning' : 'success',
            message: $this->paused ? __('sync.paused_toast') : __('sync.resumed_toast'),
        );
    }

    public function render()
    {
        return view('livewire.sync-toggle');
    }
}
