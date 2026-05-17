<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SPEC §7 rnvsync_conflicts — detected conflicts awaiting resolution. */
class Conflict extends Model
{
    protected $table = 'rnvsync_conflicts';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'path', 'local_modified_at', 'remote_modified_at',
        'local_size_bytes', 'remote_size_bytes', 'status', 'detected_at', 'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_modified_at' => 'datetime',
            'remote_modified_at' => 'datetime',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'local_size_bytes' => 'integer',
            'remote_size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
