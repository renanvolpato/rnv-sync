<div>
    <flux:heading size="xl">{{ __('conflicts.title') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('conflicts.subtitle') }}</flux:subheading>

    @forelse ($conflicts as $accountId => $rows)
        <flux:card class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="lg">{{ $rows->first()->account->name }}</flux:heading>
                <div class="flex gap-1">
                    <flux:button size="xs" variant="ghost"
                        wire:click="resolveAll({{ $accountId }}, 'local')">{{ __('conflicts.keep_all_local') }}</flux:button>
                    <flux:button size="xs" variant="ghost"
                        wire:click="resolveAll({{ $accountId }}, 'remote')">{{ __('conflicts.keep_all_remote') }}</flux:button>
                    <flux:button size="xs" variant="ghost"
                        wire:click="resolveAll({{ $accountId }}, 'both')">{{ __('conflicts.keep_all_both') }}</flux:button>
                </div>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($rows as $c)
                    <div class="py-3 flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-mono text-sm truncate">{{ $c->path }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('conflicts.detected') }} {{ $c->detected_at?->diffForHumans() }}
                            </p>
                        </div>
                        <div class="flex gap-1 shrink-0">
                            <flux:button size="xs" variant="ghost" wire:click="resolve({{ $c->id }}, 'local')">{{ __('conflicts.keep_local') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="resolve({{ $c->id }}, 'remote')">{{ __('conflicts.keep_remote') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="resolve({{ $c->id }}, 'both')">{{ __('conflicts.keep_both') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="resolve({{ $c->id }}, 'ignore')">{{ __('conflicts.ignore') }}</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-12 text-center">
            <flux:icon.check-circle class="size-12 mx-auto text-emerald-500" />
            <h3 class="mt-4 font-semibold">{{ __('conflicts.empty_title') }}</h3>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('conflicts.empty_body') }}</p>
        </div>
    @endforelse
</div>
