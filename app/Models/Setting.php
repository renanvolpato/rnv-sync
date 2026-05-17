<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SPEC §7 rnvsync_settings — one row per setting, JSON-encoded value. */
class Setting extends Model
{
    protected $table = 'rnvsync_settings';

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['key', 'value'];
}
