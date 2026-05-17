<?php

namespace App\Models;

use Database\Factories\SyncFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SPEC §7 rnvsync_sync_folders. */
class SyncFolder extends Model
{
    /** @use HasFactory<SyncFolderFactory> */
    use HasFactory;

    protected $table = 'rnvsync_sync_folders';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'remote_path', 'local_path', 'sync_mode',
        'transfers', 'checkers', 'chunk_size',
        'is_active', 'last_synced_at', 'last_sync_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
