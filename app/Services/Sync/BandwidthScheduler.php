<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Services\Settings\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Bandwidth scheduler (SPEC F5.2): an optional time window during which
 * a different (typically lower) bandwidth limit applies — e.g. throttle
 * during work hours, unlimited at night.
 *
 * Settings:
 *  - bandwidth_limit_kbps        global limit (KB/s) or null
 *  - bandwidth_schedule_enabled  bool
 *  - bandwidth_schedule_start    "HH:MM"
 *  - bandwidth_schedule_end      "HH:MM"
 *  - bandwidth_schedule_kbps     limit while inside the window
 */
class BandwidthScheduler
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Effective limit in KB/s for "now" (null = unlimited).
     */
    public function effectiveLimitKbps(?CarbonInterface $now = null): ?int
    {
        $base = $this->settings->get('bandwidth_limit_kbps');

        if (! $this->settings->get('bandwidth_schedule_enabled', false)) {
            return $base ? (int) $base : null;
        }

        $now ??= Carbon::now();
        $start = (string) $this->settings->get('bandwidth_schedule_start', '09:00');
        $end = (string) $this->settings->get('bandwidth_schedule_end', '18:00');

        if ($this->withinWindow($now, $start, $end)) {
            $scheduled = $this->settings->get('bandwidth_schedule_kbps');

            return $scheduled ? (int) $scheduled : ($base ? (int) $base : null);
        }

        return $base ? (int) $base : null;
    }

    private function withinWindow(CarbonInterface $now, string $start, string $end): bool
    {
        $minutes = $now->hour * 60 + $now->minute;
        $s = $this->toMinutes($start);
        $e = $this->toMinutes($end);

        // Handles overnight windows (e.g. 22:00–06:00).
        return $s <= $e
            ? $minutes >= $s && $minutes < $e
            : $minutes >= $s || $minutes < $e;
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
