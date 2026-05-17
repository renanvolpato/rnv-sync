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
    </div>
    @fluxScripts
</body>
</html>
