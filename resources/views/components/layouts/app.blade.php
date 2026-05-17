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
    <div class="min-h-full">
        <header class="border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
            <div class="mx-auto max-w-6xl px-4 h-14 flex items-center justify-between">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold">
                    <flux:icon.cloud class="size-6 text-sky-600 dark:text-sky-500" />
                    <span>{{ config('rnvsync.name') }}</span>
                </a>

                <nav class="flex items-center gap-1">
                    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="home">
                        {{ __('dashboard.title') }}
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

        <main class="mx-auto max-w-6xl px-4 py-8">
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
