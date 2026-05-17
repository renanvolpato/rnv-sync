<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\StartSyncJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Sync\SyncService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Folders + sync history + manual controls (SPEC F2.3/F2.4/F2.6 /
 * Key Screen 3 Folders & Activity tabs).
 */
#[Layout('components.layouts.app')]
class SyncActivity extends Component
{
    use WithPagination;

    public Account $account;

    public bool $paused = false;

    public function mount(Account $account, SyncService $sync): void
    {
        $this->account = $account;
        $this->paused = $sync->isPaused();
    }

    public function syncNow(int $folderId): void
    {
        StartSyncJob::dispatch($folderId);
        session()->flash('status', __('sync.queued'));
    }

    public function toggleFolder(int $folderId): void
    {
        $folder = SyncFolder::findOrFail($folderId);
        $folder->update(['is_active' => ! $folder->is_active]);

        if ($folder->is_active) {
            StartSyncJob::dispatch($folder->id); // EARS: sync within 30s
        }
    }

    public function togglePause(SyncService $sync): void
    {
        $this->paused = ! $this->paused;
        $sync->setPaused($this->paused);
    }

    public function render()
    {
        return view('livewire.pages.accounts.sync-activity', [
            'folders' => $this->account->syncFolders()->orderBy('remote_path')->get(),
            'history' => SyncHistory::where('account_id', $this->account->id)
                ->orderByDesc('started_at')
                ->paginate(25),
        ]);
    }
}
