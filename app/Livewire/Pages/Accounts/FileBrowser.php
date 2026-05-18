<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\DownloadPathJob;
use App\Jobs\WarmCacheJob;
use App\Models\Account;
use App\Services\Accounts\AccountsService;
use App\Services\Cache\CacheService;
use App\Services\Files\LocalFiles;
use App\Services\Settings\SettingsRepository;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * File browser (SPEC F1.5 + Files-on-Demand).
 *
 * Physical mode (default): real files on disk; status is
 * downloaded (✓) / cloud (☁); actions download / free.
 * Mount mode: rclone VFS cache; status online/cached/pinned.
 */
#[Layout('components.layouts.app')]
class FileBrowser extends Component
{
    public Account $account;

    #[Url(as: 'path')]
    public string $path = '';

    public bool $rcloneUnavailable = false;

    public bool $physical = true;

    public function mount(Account $account, SettingsRepository $settings): void
    {
        $this->account = $account;
        $this->physical = $settings->isPhysical();
    }

    public function open(string $name): void
    {
        $this->path = trim($this->path.'/'.$name, '/');
    }

    public function goTo(string $path): void
    {
        $this->path = trim($path, '/');
    }

    /** Physical: download to disk. Mount: pin into cache. */
    public function download(string $name, bool $isDir, int $size): void
    {
        $full = trim($this->path.'/'.$name, '/');

        if ($this->physical) {
            DownloadPathJob::dispatch($this->account->id, $full);
            $this->dispatch('toast', type: 'success', message: __('cache.pinning'));

            return;
        }

        $cache = app(CacheService::class);
        if (! $cache->pin($this->account, $full, $isDir, $size)) {
            $this->dispatch('toast', type: 'warning', message: __('cache.pin_too_large'));

            return;
        }
        WarmCacheJob::dispatch($this->account->id, $full);
        $this->dispatch('toast', type: 'success', message: __('cache.pinning'));
    }

    /** Physical: delete local copy (keep cloud). Mount: evict / unpin. */
    public function free(string $name): void
    {
        $full = trim($this->path.'/'.$name, '/');

        if ($this->physical) {
            app(LocalFiles::class)->free($this->account, $full);
        } else {
            $cache = app(CacheService::class);
            $cache->unpin($this->account, $full);
            $cache->freeUpSpace($this->account, $full);
        }

        $this->dispatch('toast', type: 'success', message: __('cache.freed'));
    }

    public function freeAll(): void
    {
        if (! $this->physical) {
            app(CacheService::class)->freeAllCache();
            $this->dispatch('toast', type: 'success', message: __('cache.freed_all'));
        }
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

    public function render(AccountsService $accounts, CacheService $cache, LocalFiles $local)
    {
        $entries = [];

        try {
            $entries = $accounts->listRemote($this->account, $this->path);
        } catch (\Throwable) {
            $this->rcloneUnavailable = true;
        }

        foreach ($entries as &$entry) {
            $entry['status'] = $this->physical
                ? $local->status($this->account, $entry['path'])
                : $cache->cacheStatus($this->account, $entry['path']);
        }
        unset($entry);

        return view('livewire.pages.accounts.file-browser', [
            'entries' => $entries,
            'physical' => $this->physical,
            'cacheStats' => $this->physical ? null : $cache->stats(),
        ]);
    }
}
