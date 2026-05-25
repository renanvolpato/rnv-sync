<div x-data="{ open: false }" class="relative">
    <flux:button @click="open = !open" variant="ghost" size="sm" icon="arrow-path">
        {{ __('settings.update_check') }}
        @if ($status && ($status['available'] ?? false))
            <span class="ml-1 inline-flex items-center justify-center rounded-full bg-amber-500 text-white text-xs px-1.5">{{ $status['behind'] }}</span>
        @endif
    </flux:button>

    <div x-show="open" x-on:click.outside="open = false" x-cloak
        class="absolute right-0 mt-1 w-72 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-lg p-3 z-50 text-sm space-y-2">
        @if (! $isGit)
            <p class="text-zinc-500 dark:text-zinc-400">{{ __('settings.update_not_git') }}</p>
        @else
            <p class="text-zinc-600 dark:text-zinc-300">
                @if ($status && ($status['available'] ?? false))
                    {{ __('settings.update_available', ['n' => $status['behind']]) }}
                @elseif ($status && $status['error'])
                    {{ __('settings.update_check_failed') }}
                @elseif ($status)
                    {{ __('settings.update_up_to_date') }}
                @else
                    {{ __('settings.update_check') }}
                @endif
            </p>

            <flux:button wire:click="check" wire:loading.attr="disabled" wire:target="check"
                size="sm" variant="ghost" icon="arrow-path" class="w-full">
                <span wire:loading.remove wire:target="check">{{ __('settings.update_check') }}</span>
                <span wire:loading wire:target="check">{{ __('settings.update_checking') }}</span>
            </flux:button>

            @if ($status && ($status['available'] ?? false))
                <flux:button wire:click="apply" wire:confirm="{{ __('settings.update_confirm') }}"
                    size="sm" variant="primary" icon="arrow-down-tray" class="w-full">
                    {{ __('settings.update_now', ['n' => $status['behind']]) }}
                </flux:button>
            @endif
        @endif
    </div>
</div>
