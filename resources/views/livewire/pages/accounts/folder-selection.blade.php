<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

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

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($folders as $folder)
            <div class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                <flux:checkbox wire:model="selected" value="{{ $folder['path'] }}" />
                <flux:icon.folder class="size-4 text-sky-600 dark:text-sky-500 shrink-0" />
                <span class="flex-1 truncate">{{ $folder['name'] }}</span>

                @if ($running && in_array($folder['path'], $selected, true))
                    <flux:badge size="sm" color="sky">
                        <flux:icon.arrow-path class="size-3 animate-spin mr-1" /> {{ __('sync.active') }}
                    </flux:badge>
                @elseif (in_array($folder['path'], $selected, true))
                    <flux:badge size="sm" color="emerald">{{ __('sync.selected') }}</flux:badge>
                @endif

                <flux:button wire:click="open('{{ addslashes($folder['name']) }}')"
                    size="xs" variant="ghost" icon="chevron-right">
                    {{ __('sync.open_subfolders') }}
                </flux:button>
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
