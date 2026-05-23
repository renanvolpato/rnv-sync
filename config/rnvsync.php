<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Identity
    |--------------------------------------------------------------------------
    |
    | RNV Sync (formerly "Cirrus" in the original specification). The internal
    | identifier "rnvsync" is used for the database table prefix, rclone binary
    | name and default mount directory.
    |
    */

    'name' => 'RNV Sync',

    'table_prefix' => 'rnvsync_',

    /*
    |--------------------------------------------------------------------------
    | rclone
    |--------------------------------------------------------------------------
    */

    'rclone' => [
        'version' => '1.67.0',
        'binary_path' => env('RCLONE_BINARY_PATH', base_path('rclone/rclone')),
        'config_path' => env('RCLONE_CONFIG_PATH', storage_path('rclone/rclone.conf')),
        'cache_dir' => env('RCLONE_CACHE_DIR', storage_path('rclone/cache')),
        'mount_base' => env('RCLONE_MOUNT_BASE', ($_SERVER['HOME'] ?? sys_get_temp_dir()).'/RnvSync'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft OAuth (OneDrive)
    |--------------------------------------------------------------------------
    |
    | Default public client id is rclone's well-known OneDrive application id,
    | so users do not have to register their own Azure app. Power users can
    | override it with their own registration via the environment.
    |
    */

    'oauth' => [
        'client_id' => env('ONEDRIVE_CLIENT_ID', 'b15665d9-eda6-4092-8539-0eec376afd59'),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET', ''),

        // Microsoft identity "tenant" segment:
        //  - common      → work/school AND personal (default)
        //  - consumers   → personal Microsoft accounts only (OneDrive
        //                   Personal — avoids being treated as a guest
        //                   of a work tenant)
        //  - organizations or a tenant id/domain → work/school only
        'tenant' => env('ONEDRIVE_TENANT', 'common'),

        'graph_base' => 'https://graph.microsoft.com/v1.0',
        'scopes' => 'Files.ReadWrite.All offline_access User.Read',
        // Refresh tokens when within this many seconds of expiry (10 minutes).
        'refresh_window_seconds' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage model
    |--------------------------------------------------------------------------
    |
    | physical → real files on disk, no FUSE mount, no virtual device.
    |            Selective per-folder/file download; file-manager emblems
    |            (✓ downloaded / ☁ cloud-only). Default.
    | mount    → rclone FUSE mount (Files-on-Demand). Low disk, but the
    |            account appears as a mounted volume.
    |
    */

    'storage_mode' => env('RNVSYNC_STORAGE_MODE', 'physical'),

    /*
    |--------------------------------------------------------------------------
    | Internationalization
    |--------------------------------------------------------------------------
    */

    'available_locales' => ['en', 'pt-BR'],
    'default_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Defaults & Fallbacks (SPEC section 17)
    |--------------------------------------------------------------------------
    */

    'mount' => [
        // Default rclone mount flags (SPEC §8 "For mounts").
        'vfs_cache_mode' => 'full',
        'vfs_cache_max_age' => '168h',
        'vfs_read_ahead' => '128M',
        'buffer_size' => '64M',
        'dir_cache_time' => '5m',
        'poll_interval' => '15s',
        'tpslimit' => 10,
        'tpslimit_burst' => 20,
        // Lifecycle (SPEC §17 / §9 v0.3.0 EARS).
        'max_restarts' => 3,
        'health_unresponsive_after_seconds' => 60,
    ],

    'sync' => [
        // Default rclone flags for sync operations (SPEC §8).
        'transfers' => 4,
        'checkers' => 8,
        'tpslimit' => 10,
        'tpslimit_burst' => 20,
        // Network-failure retry policy (SPEC §9 v0.2.0 EARS / §17).
        'max_retries' => 3,
        'backoff_seconds' => [5, 30, 300],
        // How often an on-demand folder re-lists the whole remote tree to
        // surface NEW cloud-side files as ☁ placeholders. This recursive
        // listing is the heaviest part of sync (minutes for 50k+ files), so
        // it is throttled. Local edits still upload in real time (the file
        // watcher) and "Sync now" forces an immediate refresh, so a longer
        // interval mainly delays auto-discovery of files created on the
        // OneDrive website. Override with RNVSYNC_PLACEHOLDER_REFRESH_MINUTES.
        'placeholder_refresh_minutes' => (int) env('RNVSYNC_PLACEHOLDER_REFRESH_MINUTES', 120),
    ],

    'defaults' => [
        'theme' => 'auto',
        'sync_interval_minutes' => 15,
        'bandwidth_limit_kbps' => null,
        'cache' => [
            'free_space_fraction' => 0.10,
            'max_gb' => 20,
            'min_gb' => 1,
        ],
        'password_min_length' => 12,
    ],

];
