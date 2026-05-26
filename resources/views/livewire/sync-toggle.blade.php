<div wire:poll.10s>
    @if ($paused)
        {{-- Highlighted so a paused state is obvious — click to resume. --}}
        <flux:button wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
            variant="primary" size="sm" icon="play">
            {{ __('sync.resume') }}
        </flux:button>
    @else
        <flux:button wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
            variant="ghost" size="sm" icon="pause">
            {{ __('sync.pause') }}
        </flux:button>
    @endif
</div>
