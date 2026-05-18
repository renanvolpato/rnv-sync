<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Typed accessor over the rnvsync_settings key/value table (SPEC §7).
 * Values are JSON-encoded.
 */
class SettingsRepository
{
    public const KEY_LANGUAGE = 'ui_language';

    public const KEY_THEME = 'theme';

    public const KEY_MOUNT_BASE = 'mount_base';

    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->where('key', $key)->value('value');

        if ($row === null) {
            return $default;
        }

        return json_decode($row, true);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()],
        );

        Cache::forget('rnvsync.setup_complete');
    }

    public function language(): string
    {
        return (string) $this->get(self::KEY_LANGUAGE, config('rnvsync.default_locale'));
    }

    public function theme(): string
    {
        return (string) $this->get(self::KEY_THEME, config('rnvsync.defaults.theme'));
    }

    public function mountBase(): string
    {
        return (string) $this->get(self::KEY_MOUNT_BASE, config('rnvsync.rclone.mount_base'));
    }

    public const KEY_STORAGE_MODE = 'storage_mode';

    /** 'physical' (real files, no FUSE) or 'mount' (FUSE on-demand). */
    public function storageMode(): string
    {
        $mode = (string) $this->get(self::KEY_STORAGE_MODE, config('rnvsync.storage_mode'));

        return in_array($mode, ['physical', 'mount'], true) ? $mode : 'physical';
    }

    public function isPhysical(): bool
    {
        return $this->storageMode() === 'physical';
    }

    /**
     * Setup is complete once a panel user exists (SPEC F1.2 / EARS:
     * "WHEN no panel password is set, redirect all routes to the wizard").
     */
    public function setupComplete(): bool
    {
        try {
            return Cache::remember(
                'rnvsync.setup_complete',
                now()->addMinutes(5),
                fn (): bool => User::query()->exists(),
            );
        } catch (\Throwable) {
            // DB driver/file not ready yet (pre-bootstrap). The
            // requirements preflight handles this case.
            return false;
        }
    }
}
