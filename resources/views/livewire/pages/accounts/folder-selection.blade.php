<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

    <flux:heading size="xl">{{ __('sync.select_folders') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('sync.select_folders_hint') }}</flux:subheading>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($folders as $folder)
            @php $isSynced = $synced->has($folder['path']); $st = $synced[$folder['path']] ?? null; @endphp
            <label class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                <flux:checkbox wire:model="selected.{{ $folder['path'] }}" />
                <flux:icon.folder class="size-4 text-sky-600 dark:text-sky-500 shrink-0" />
                <span class="flex-1 truncate">{{ $folder['name'] }}</span>
                @if ($isSynced && $running)
                    <flux:badge size="sm" color="sky">
                        <flux:icon.arrow-path class="size-3 animate-spin mr-1" /> {{ __('sync.active') }}
                    </flux:badge>
                @elseif ($isSynced && $st === 'error')
                    <flux:badge size="sm" color="rose">{{ __('sync.status_error') }}</flux:badge>
                @elseif ($isSynced)
                    <flux:badge size="sm" color="emerald">{{ __('sync.status_success') }}</flux:badge>
                @endif
            </label>
        @empty
            <div class="p-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('accounts.empty_folder') }}
            </div>
        @endforelse
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('sync.uncheck_hint') }}</p>

    <div class="mt-4 sticky bottom-4">
        <flux:button wire:click="save" variant="primary" icon="check">
            <span wire:loading.remove wire:target="save">{{ __('sync.save_selection') }}</span>
            <span wire:loading wire:target="save">{{ __('common.loading') }}</span>
        </flux:button>
    </div>
</div>
