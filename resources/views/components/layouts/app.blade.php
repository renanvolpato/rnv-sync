<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ?? '') ? $title.' · ' : '' }}{{ config('rnvsync.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="h-full bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded focus:bg-sky-600 focus:px-3 focus:py-2 focus:text-white">
        {{ __('common.skip_to_content') }}
    </a>
    <div class="min-h-full">
        <header class="border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
            <div class="mx-auto max-w-6xl px-4 py-2 flex items-center justify-between gap-2 flex-wrap">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold">
                    <flux:icon.cloud class="size-6 text-sky-600 dark:text-sky-500" />
                    <span>{{ config('rnvsync.name') }}</span>
                </a>

                @php
                    $switcherAccounts = \App\Models\Account::orderBy('name')->get(['id', 'name']);
                    $pendingConflicts = app(\App\Services\Conflicts\ConflictsService::class)->pendingCount();
                @endphp

                <nav class="flex items-center gap-1">
                    @if ($switcherAccounts->isNotEmpty())
                        <div x-data="{ open: false }" class="relative">
                            <flux:button @click="open = !open" variant="ghost" size="sm" icon="chevron-down">
                                {{ __('accounts.accounts') }}
                            </flux:button>
                            <div x-show="open" x-on:click.outside="open = false" x-cloak
                                class="absolute right-0 mt-1 w-56 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-lg py-1 z-50">
                                @foreach ($switcherAccounts as $acc)
                                    <a href="{{ route('accounts.activity', $acc) }}"
                                        class="block px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $acc->name }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="home">
                        {{ __('dashboard.title') }}
                    </flux:button>

                    <div x-data="{ count: {{ $pendingConflicts }} }"
                        x-init="if (window.Echo) window.Echo.channel('rnvsync').listen('.conflict.detected', e => {
                            count = e.pendingCount;
                            if (window.Notification && Notification.permission === 'granted') new Notification('{{ __('conflicts.notify_title') }}');
                            else if (window.Notification && Notification.permission !== 'denied') Notification.requestPermission();
                        });">
                        <flux:button :href="route('conflicts')" variant="ghost" size="sm" icon="exclamation-triangle">
                            {{ __('conflicts.title') }}
                            <template x-if="count > 0">
                                <span class="ml-1 inline-flex items-center justify-center rounded-full bg-rose-600 text-white text-xs px-1.5" x-text="count"></span>
                            </template>
                        </flux:button>
                    </div>

                    <flux:button :href="route('search')" variant="ghost" size="sm" icon="magnifying-glass">
                        {{ __('search.title') }}
                    </flux:button>
                    <flux:button :href="route('trends')" variant="ghost" size="sm" icon="chart-bar">
                        {{ __('trends.title') }}
                    </flux:button>
                    <flux:button :href="route('settings')" variant="ghost" size="sm" icon="cog-6-tooth">
                        {{ __('settings.title') }}
                    </flux:button>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">
                            {{ __('auth.logout') }}
                        </flux:button>
                    </form>
                </nav>
            </div>
        </header>

        <main id="main" class="mx-auto max-w-6xl px-4 py-8">
            @if (session('status'))
                <flux:callout variant="success" class="mb-6" icon="check-circle">
                    {{ session('status') }}
                </flux:callout>
            @endif

            {{ $slot }}
        </main>

        {{-- Toast notifications (SPEC §10): bottom-right, errors persist --}}
        <div
            x-data="{ toasts: [] }"
            @toast.window="
                const t = { id: Date.now(), ...$event.detail };
                toasts.push(t);
                if (t.type !== 'error') setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), 5000);
            "
            class="fixed bottom-4 right-4 z-50 space-y-2 w-80"
        >
            <template x-for="t in toasts" :key="t.id">
                <div
                    @click="toasts = toasts.filter(x => x.id !== t.id)"
                    class="rounded-lg shadow-lg p-3 text-sm text-white cursor-pointer"
                    :class="{
                        'bg-emerald-600': t.type === 'success',
                        'bg-sky-600': t.type === 'info',
                        'bg-amber-500': t.type === 'warning',
                        'bg-rose-600': t.type === 'error',
                    }"
                    x-text="t.message"
                ></div>
            </template>
        </div>
    </div>
    @fluxScripts
</body>
</html>
