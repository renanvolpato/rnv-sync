<div>
    @if ($path !== '')
        {{-- Inside a subfolder: go up one level instead of leaving. --}}
        <flux:button wire:click="goUp" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
            {{ __('common.back') }}
        </flux:button>
    @else
        <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
            {{ __('common.back') }}
        </flux:button>
    @endif

    <flux:heading size="xl">{{ __('sync.select_folders') }}</flux:heading>
    <flux:subheading class="mb-4">{{ __('sync.select_folders_hint') }}</flux:subheading>

    {{-- Breadcrumb: drill into subfolders --}}
    <div class="flex items-center gap-2 mb-3 text-sm flex-wrap">
        @foreach ($this->breadcrumbs() as $crumb)
            @if (! $loop->first)
                <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
            @endif
            <button wire:click="goTo('{{ addslashes($crumb['path']) }}')"
                @class(['hover:underline', 'font-semibold' => $loop->last,
                    'text-zinc-500 dark:text-zinc-400' => ! $loop->last])
            >{{ $crumb['label'] }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800" wire:poll.5s>
        @if (! empty($folders))
            <div class="flex items-center gap-3 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/40">
                <span class="w-8"></span>
                <span class="flex-1">{{ __('accounts.col_name') }}</span>
                <span class="w-44 border-l border-zinc-200 dark:border-zinc-800 pl-3">{{ __('cache.col_status') }}</span>
                <span class="w-52 border-l border-zinc-200 dark:border-zinc-800 pl-3 text-right">{{ __('sync.col_action') }}</span>
            </div>
        @endif
        @forelse ($folders as $folder)
            @php
                $st = $folder['status'] ?? 'cloud';
                $isDir = $folder['is_dir'];
                $synced = $folder['synced'] ?? false;
            @endphp
            <div @if ($isDir) x-on:dblclick="$wire.open(@js($folder['name']))"
                    title="{{ __('sync.open_folder_hint') }}" @endif
                class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 @if ($isDir) cursor-pointer select-none @endif">
                <div class="w-8 flex items-center">
                    @if ($isDir)
                        <flux:checkbox wire:model="selected" value="{{ $folder['path'] }}" />
                    @endif
                </div>

                <div class="flex-1 min-w-0 flex items-center gap-2">
                    <flux:icon :name="$isDir ? 'folder' : 'document'"
                        class="size-4 shrink-0 {{ $isDir ? 'text-sky-600 dark:text-sky-500' : 'text-zinc-400' }}" />
                    <span class="truncate">{{ $folder['name'] }}</span>
                </div>

                {{-- STATUS column --}}
                <div class="w-44 border-l border-zinc-200 dark:border-zinc-800 pl-3">
                    @if (! $synced)
                        <span class="inline-flex items-center gap-1 text-zinc-400 dark:text-zinc-500 text-xs" title="{{ __('cache.tip_unsynced') }}">
                            <flux:icon.minus-circle class="size-3.5" /> {{ __('cache.status_unsynced') }}
                        </span>
                    @elseif ($st === 'syncing')
                        <span class="inline-flex items-center gap-1 text-sky-600 dark:text-sky-500 text-xs" title="{{ __('cache.tip_syncing') }}">
                            <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('cache.status_syncing') }}
                        </span>
                    @elseif ($st === 'error')
                        <span class="inline-flex items-center gap-1 text-rose-600 dark:text-rose-500 text-xs"
                            title="{{ $folder['errmsg'] ?? __('cache.tip_error') }}">
                            <flux:icon.exclamation-triangle class="size-3.5" /> {{ __('cache.status_error') }}
                        </span>
                    @elseif ($st === 'downloaded')
                        <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-500 text-xs" title="{{ __('cache.tip_downloaded') }}">
                            <flux:icon.check-circle variant="solid" class="size-3.5" /> {{ __('cache.status_downloaded') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-sky-600 dark:text-sky-500 text-xs" title="{{ __('cache.tip_cloud') }}">
                            <flux:icon.cloud variant="solid" class="size-3.5" /> {{ __('cache.status_cloud') }}
                        </span>
                    @endif
                </div>

                {{-- ACTIONS column --}}
                <div class="w-52 border-l border-zinc-200 dark:border-zinc-800 pl-3 flex items-center justify-end gap-1">
                    @if (! $synced)
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('sync.tick_to_sync') }}</span>
                    @elseif ($st === 'syncing')
                        <span class="text-xs text-zinc-400">{{ __('common.loading') }}</span>
                    @elseif ($st === 'error')
                        <span title="{{ $folder['errmsg'] ?? __('cache.tip_error') }}">
                            <flux:button wire:click="keepOffline('{{ addslashes($folder['name']) }}')" size="xs" variant="ghost" icon="arrow-path">
                                {{ __('common.retry') }}
                            </flux:button>
                        </span>
                    @elseif ($st === 'downloaded')
                        <span title="{{ __('cache.tip_free_action') }}">
                            <flux:button wire:click="freeOnline('{{ addslashes($folder['name']) }}')" size="xs" variant="ghost" icon="cloud">
                                {{ __('cache.free') }}
                            </flux:button>
                        </span>
                    @else
                        <span title="{{ __('cache.tip_download_action') }}">
                            <flux:button wire:click="keepOffline('{{ addslashes($folder['name']) }}')" size="xs" variant="ghost" icon="arrow-down-tray">
                                {{ __('cache.download') }}
                            </flux:button>
                        </span>
                    @endif
                    @if ($isDir)
                        <flux:button wire:click="open('{{ addslashes($folder['name']) }}')"
                            size="xs" variant="ghost" icon="chevron-right" />
                    @endif
                </div>
            </div>
        @empty
            <div class="p-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('accounts.empty_folder') }}
            </div>
        @endforelse
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('sync.subfolder_hint') }}</p>
    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('sync.uncheck_hint') }}</p>

    <div class="mt-4 sticky bottom-4 flex items-center gap-3">
        <flux:button wire:click="save" variant="primary" icon="check">
            <span wire:loading.remove wire:target="save">{{ __('sync.save_selection') }}</span>
            <span wire:loading wire:target="save">{{ __('common.loading') }}</span>
        </flux:button>
        <span class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ count($selected) }} {{ __('sync.selected_count') }}
        </span>
    </div>
</div>
