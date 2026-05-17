<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SPEC §7 rnvsync_mount_processes — track running rclone mount processes. */
class MountProcess extends Model
{
    protected $table = 'rnvsync_mount_processes';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'mount_point', 'pid', 'started_at',
        'status', 'last_health_check_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'started_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
