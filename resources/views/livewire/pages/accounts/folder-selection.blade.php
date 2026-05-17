<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

    <flux:heading size="xl">{{ __('sync.select_folders') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('sync.select_folders_hint') }}</flux:subheading>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($folders as $folder)
            <label class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                <flux:checkbox wire:model="selected.{{ $folder['path'] }}" />
                <flux:icon.folder class="size-4 text-sky-600 dark:text-sky-500" />
                <span>{{ $folder['name'] }}</span>
            </label>
        @empty
            <div class="p-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('accounts.empty_folder') }}
            </div>
        @endforelse
    </div>

    <div class="mt-6 sticky bottom-4">
        <flux:button wire:click="save" variant="primary" icon="check">
            <span wire:loading.remove wire:target="save">{{ __('sync.save_selection') }}</span>
            <span wire:loading wire:target="save">{{ __('common.loading') }}</span>
        </flux:button>
    </div>
</div>
