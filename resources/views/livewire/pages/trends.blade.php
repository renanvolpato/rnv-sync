<div>
    @php use App\Support\Bytes; @endphp
    <flux:heading size="xl">{{ __('trends.title') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('trends.subtitle') }}</flux:subheading>

    <flux:card>
        @if ($rows->isEmpty())
            <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('trends.empty') }}</p>
        @else
            <div class="flex items-end gap-2 h-56" role="img" aria-label="{{ __('trends.title') }}">
                @foreach ($rows as $row)
                    <div class="flex-1 flex flex-col items-center gap-1" title="{{ $row->captured_on }}">
                        <div class="w-full flex items-end gap-0.5 h-48">
                            <div class="flex-1 bg-sky-600 rounded-t"
                                style="height: {{ max(2, (int) round($row->cloud / $max * 100)) }}%"
                                aria-label="{{ __('trends.cloud') }}: {{ Bytes::human((int) $row->cloud) }}"></div>
                            <div class="flex-1 bg-emerald-600 rounded-t"
                                style="height: {{ max(2, (int) round($row->cache / $max * 100)) }}%"
                                aria-label="{{ __('trends.cache') }}: {{ Bytes::human((int) $row->cache) }}"></div>
                        </div>
                        <span class="text-[10px] text-zinc-500">{{ \Illuminate\Support\Carbon::parse($row->captured_on)->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex gap-4 text-xs">
                <span class="flex items-center gap-1"><span class="size-3 rounded bg-sky-600"></span>{{ __('trends.cloud') }}</span>
                <span class="flex items-center gap-1"><span class="size-3 rounded bg-emerald-600"></span>{{ __('trends.cache') }}</span>
            </div>
        @endif
    </flux:card>
</div>
