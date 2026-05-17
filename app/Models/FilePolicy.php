<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SPEC §7 rnvsync_file_policies — per-path offline/online overrides. */
class FilePolicy extends Model
{
    protected $table = 'rnvsync_file_policies';

    /** @var list<string> */
    protected $fillable = ['account_id', 'path', 'is_directory', 'policy'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_directory' => 'boolean'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
