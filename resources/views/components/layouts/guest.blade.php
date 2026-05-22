<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('rnvsync.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="h-full bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
        <div class="mb-8 flex items-center gap-2">
            <flux:icon.cloud class="size-7 text-sky-600 dark:text-sky-500" />
            <span class="text-xl font-semibold tracking-tight">{{ config('rnvsync.name') }}</span>
        </div>

        <div class="w-full max-w-md">
            {{ $slot }}
        </div>

        <p class="mt-8 text-xs text-zinc-500 dark:text-zinc-400 text-center">
            {{ __('common.powered_by_rclone') }}
        </p>
    </div>
    @fluxScripts
</body>
</html>
