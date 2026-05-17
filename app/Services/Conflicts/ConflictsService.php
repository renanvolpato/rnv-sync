<?php

declare(strict_types=1);

namespace App\Services\Conflicts;

use App\Events\ConflictDetected;
use App\Models\Account;
use App\Models\Conflict;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Conflict detection and resolution (SPEC F4.4–F4.6).
 *
 * EARS: WHEN bisync detects a conflict, create a rnvsync_conflicts
 * record and emit a WebSocket event. IF more than 10 conflicts exist
 * for one account, pause automatic sync for that account.
 */
class ConflictsService
{
    public const AUTO_PAUSE_THRESHOLD = 10;

    public function __construct(
        private readonly RcloneRunner $rclone,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Detect conflicts from parsed bisync log entries and persist them.
     *
     * @param  list<array{level:string,msg:string,raw:array<string,mixed>}>  $entries
     */
    public function detectFromLog(Account $account, array $entries): int
    {
        $created = 0;

        foreach ($entries as $entry) {
            if (! $this->looksLikeConflict($entry['msg'])) {
                continue;
            }

            $path = $this->extractPath($entry['msg']);

            $conflict = Conflict::firstOrCreate(
                ['account_id' => $account->id, 'path' => $path, 'status' => 'pending'],
                ['detected_at' => Carbon::now()],
            );

            if ($conflict->wasRecentlyCreated) {
                $created++;
            }
        }

        if ($created > 0) {
            $pending = $this->pendingCount($account);

            event(new ConflictDetected($account->id, $pending));

            // SPEC F4.4 EARS: >10 conflicts ⇒ pause auto-sync for account.
            if ($pending > self::AUTO_PAUSE_THRESHOLD) {
                $this->setAccountPaused($account, true);
            }
        }

        return $created;
    }

    public function pendingCount(?Account $account = null): int
    {
        return Conflict::query()
            ->when($account, fn ($q) => $q->where('account_id', $account->id))
            ->where('status', 'pending')
            ->count();
    }

    public function isAccountPaused(Account $account): bool
    {
        return (bool) $this->settings->get("sync_paused_account_{$account->id}", false);
    }

    public function setAccountPaused(Account $account, bool $paused): void
    {
        $this->settings->set("sync_paused_account_{$account->id}", $paused);
    }

    /**
     * Resolve one conflict (SPEC F4.5). $choice ∈
     * local|remote|both|ignore.
     */
    public function resolve(Conflict $conflict, string $choice): void
    {
        $account = $conflict->account;
        $remote = $account->remote_name.':'.ltrim($conflict->path, '/');
        $local = rtrim($this->settings->mountBase(), '/').'/'.$account->name.'/'.ltrim($conflict->path, '/');

        match ($choice) {
            'local' => $this->rclone->run(['copyto', $local, $remote], ['timeout' => 600]),
            'remote' => $this->rclone->run(['copyto', $remote, $local], ['timeout' => 600]),
            'both' => $this->rclone->run(['copyto', $local, $remote.'.local'], ['timeout' => 600]),
            default => null, // ignore
        };

        $conflict->update([
            'status' => match ($choice) {
                'local' => 'resolved_local',
                'remote' => 'resolved_remote',
                'both' => 'resolved_both',
                default => 'ignored',
            },
            'resolved_at' => Carbon::now(),
        ]);

        // Clearing the backlog lifts the automatic per-account pause.
        if ($account && $this->pendingCount($account) <= self::AUTO_PAUSE_THRESHOLD) {
            $this->setAccountPaused($account, false);
        }
    }

    public function resolveAll(Account $account, string $choice): int
    {
        $conflicts = Conflict::where('account_id', $account->id)
            ->where('status', 'pending')->get();

        foreach ($conflicts as $conflict) {
            $this->resolve($conflict, $choice);
        }

        return $conflicts->count();
    }

    private function looksLikeConflict(string $msg): bool
    {
        return str_contains(strtolower($msg), 'conflict');
    }

    private function extractPath(string $msg): string
    {
        if (preg_match('/"([^"]+)"/', $msg, $m)) {
            return $m[1];
        }

        return trim($msg);
    }
}
