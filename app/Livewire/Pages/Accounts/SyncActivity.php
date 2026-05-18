<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\MaterializePlaceholdersJob;
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
        // Self-heal: a job killed mid-run (dev server stopped) would
        // otherwise keep the "syncing…" spinner on forever.
        SyncHistory::sweepStale();

        $this->account = $account;
        $this->paused = $sync->isPaused();
    }

    public function syncNow(int $folderId): void
    {
        $folder = SyncFolder::where('id', $folderId)
            ->where('account_id', $this->account->id)->firstOrFail();

        $folder->sync_mode === 'bisync'
            ? StartSyncJob::dispatch($folder->id)
            : MaterializePlaceholdersJob::dispatch($folder->id);

        session()->flash('status', __('sync.queued'));
    }

    /** Clear the sync history (keeps any run still in progress). */
    public function clearHistory(): void
    {
        SyncHistory::where('account_id', $this->account->id)
            ->where('status', '!=', 'running')
            ->delete();

        $this->resetPage();
        session()->flash('status', __('sync.history_cleared'));
    }

    /** Fully remove a folder from sync. Local files are left in place. */
    public function unsync(int $folderId): void
    {
        SyncFolder::where('id', $folderId)
            ->where('account_id', $this->account->id)
            ->delete();

        session()->flash('status', __('sync.unsynced'));
    }

    public function togglePause(SyncService $sync): void
    {
        $this->paused = ! $this->paused;
        $sync->setPaused($this->paused);
    }

    public function render()
    {
        return view('livewire.pages.accounts.sync-activity', [
            // Only folders actually chosen for sync appear here.
            'folders' => $this->account->syncFolders()
                ->where('is_active', true)
                ->orderBy('remote_path')
                ->get(),
            'running' => SyncHistory::where('account_id', $this->account->id)
                ->where('status', 'running')->exists(),
            'history' => SyncHistory::where('account_id', $this->account->id)
                ->orderByDesc('started_at')
                ->paginate(15),
        ]);
    }
}
