<div>
    <flux:heading size="xl">{{ __('search.title') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('search.subtitle') }}</flux:subheading>

    <flux:input wire:model.live.debounce.500ms="q" :placeholder="__('search.placeholder')"
        icon="magnifying-glass" class="mb-6" />

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
        @if (strlen(trim($q)) < 2)
            <p class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('search.hint') }}</p>
        @elseif (empty($results))
            <p class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('search.no_results') }}</p>
        @else
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($results as $r)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800/60 last:border-0">
                            <td class="px-4 py-2.5">
                                <flux:icon :name="$r['is_dir'] ? 'folder' : 'document'" class="size-4 inline text-zinc-400" />
                                <span class="font-mono">{{ $r['path'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs text-zinc-500">{{ $r['account'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
