<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\WarmCacheJob;
use App\Models\Account;
use App\Services\Accounts\AccountsService;
use App\Services\Cache\CacheService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * File browser (SPEC F1.5 + F3.5/F3.6/F3.7/F3.8).
 *
 * Listing comes from `rclone lsjson`. Each entry shows its cache status
 * (online / cached / pinned) and offers pin / free-up-space actions.
 * Path is reflected in the URL so it is shareable.
 */
#[Layout('components.layouts.app')]
class FileBrowser extends Component
{
    public Account $account;

    #[Url(as: 'path')]
    public string $path = '';

    public bool $rcloneUnavailable = false;

    public function mount(Account $account): void
    {
        $this->account = $account;
    }

    public function open(string $name): void
    {
        $this->path = trim($this->path.'/'.$name, '/');
    }

    public function goTo(string $path): void
    {
        $this->path = trim($path, '/');
    }

    public function pin(string $name, bool $isDir, int $size, CacheService $cache): void
    {
        $full = trim($this->path.'/'.$name, '/');

        if (! $cache->pin($this->account, $full, $isDir, $size)) {
            // SPEC F3.6 EARS: file larger than cache → warn, offer increase.
            $this->dispatch('toast', type: 'warning', message: __('cache.pin_too_large'));

            return;
        }

        // Download in the background — the UI returns immediately.
        WarmCacheJob::dispatch($this->account->id, $full);

        $this->dispatch('toast', type: 'success', message: __('cache.pinning'));
    }

    public function unpin(string $name, CacheService $cache): void
    {
        $cache->unpin($this->account, trim($this->path.'/'.$name, '/'));
        $this->dispatch('toast', type: 'success', message: __('cache.unpinned'));
    }

    public function freeUp(string $name, CacheService $cache): void
    {
        $cache->freeUpSpace($this->account, trim($this->path.'/'.$name, '/'));
        $this->dispatch('toast', type: 'success', message: __('cache.freed'));
    }

    public function freeAll(CacheService $cache): void
    {
        $cache->freeAllCache();
        $this->dispatch('toast', type: 'success', message: __('cache.freed_all'));
    }

    /**
     * @return list<array{label:string,path:string}>
     */
    public function breadcrumbs(): array
    {
        $crumbs = [['label' => $this->account->name, 'path' => '']];
        $acc = '';

        foreach (array_filter(explode('/', $this->path)) as $segment) {
            $acc = trim($acc.'/'.$segment, '/');
            $crumbs[] = ['label' => $segment, 'path' => $acc];
        }

        return $crumbs;
    }

    public function render(AccountsService $accounts, CacheService $cache)
    {
        $entries = [];

        try {
            $entries = $accounts->listRemote($this->account, $this->path);
        } catch (\Throwable) {
            $this->rcloneUnavailable = true;
        }

        foreach ($entries as &$entry) {
            $entry['status'] = $cache->cacheStatus($this->account, $entry['path']);
        }
        unset($entry);

        return view('livewire.pages.accounts.file-browser', [
            'entries' => $entries,
            'cacheStats' => $cache->stats(),
        ]);
    }
}
