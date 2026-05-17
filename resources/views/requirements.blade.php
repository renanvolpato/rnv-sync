<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('system.title') }} · {{ config('rnvsync.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-2xl">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-semibold tracking-tight">{{ config('rnvsync.name') }}</h1>
                <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ __('system.subtitle') }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($checks as $check)
                    <div class="p-4 flex items-start gap-3">
                        <div class="mt-0.5">
                            @if ($check['ok'])
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-emerald-600 text-white text-xs" aria-hidden="true">✓</span>
                            @elseif ($check['critical'])
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-rose-600 text-white text-xs" aria-hidden="true">✕</span>
                            @else
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-amber-500 text-white text-xs" aria-hidden="true">!</span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ $check['label'] }}
                                @unless ($check['critical'])
                                    <span class="text-xs text-zinc-500">({{ __('system.optional') }})</span>
                                @endunless
                            </p>
                            @unless ($check['ok'])
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $check['hint'] }}</p>
                                @if ($check['command'])
                                    <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 text-zinc-100 text-xs p-3"><code>{{ $check['command'] }}</code></pre>
                                @endif
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 rounded-xl border border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 p-4">
                <p class="text-sm font-medium">{{ __('system.one_command') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 text-zinc-100 text-xs p-3"><code>{{ $bootstrap }}</code></pre>
            </div>

            <div class="mt-6 flex justify-center">
                <a href="{{ url('/requirements') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-sky-600 hover:bg-sky-500 px-4 py-2 text-white text-sm font-medium">
                    {{ __('system.recheck') }}
                </a>
            </div>

            <p class="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('system.footer') }}
            </p>
        </div>
    </div>
</body>
</html>
