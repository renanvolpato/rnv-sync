<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Daily storage usage point for trends (SPEC F5.5). */
class UsageSnapshot extends Model
{
    protected $table = 'rnvsync_usage_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'cloud_used_bytes', 'cloud_total_bytes',
        'cache_used_bytes', 'captured_on',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'cloud_used_bytes' => 'integer',
            'cloud_total_bytes' => 'integer',
            'cache_used_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
