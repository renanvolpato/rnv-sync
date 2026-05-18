<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SPEC §7 rnvsync_sync_history — audit log of sync runs. */
class SyncHistory extends Model
{
    protected $table = 'rnvsync_sync_history';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'sync_folder_id', 'started_at', 'completed_at',
        'status', 'files_transferred', 'bytes_transferred', 'errors_count', 'log_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'files_transferred' => 'integer',
            'bytes_transferred' => 'integer',
            'errors_count' => 'integer',
        ];
    }

    /**
     * Mark "running" rows that outlived the longest job timeout as
     * interrupted. Without this, a job killed mid-run (e.g. the dev
     * server stopped) leaves the UI showing "syncing…" forever.
     *
     * @return int rows fixed
     */
    public static function sweepStale(int $minutes = 65): int
    {
        return static::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->update(['status' => 'error', 'completed_at' => now()]);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
