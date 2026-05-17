<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An authenticated cloud account (SPEC §7 rnvsync_accounts).
 *
 * The `oauth_token` column holds the full Microsoft token JSON encrypted
 * at rest via Laravel's Crypt facade (cast `encrypted`).
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $table = 'rnvsync_accounts';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'provider',
        'remote_name',
        'email',
        'oauth_token',
        'status',
        'quota_total_bytes',
        'quota_used_bytes',
        'last_synced_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'oauth_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'oauth_token' => 'encrypted',
            'quota_total_bytes' => 'integer',
            'quota_used_bytes' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<SyncFolder, $this> */
    public function syncFolders(): HasMany
    {
        return $this->hasMany(SyncFolder::class);
    }

    /** @return HasMany<MountProcess, $this> */
    public function mountProcesses(): HasMany
    {
        return $this->hasMany(MountProcess::class);
    }

    /**
     * Decoded token payload, or null if absent/corrupt.
     *
     * @return array<string, mixed>|null
     */
    public function tokenPayload(): ?array
    {
        if (blank($this->oauth_token)) {
            return null;
        }

        $decoded = json_decode((string) $this->oauth_token, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function quotaPercentUsed(): ?float
    {
        if (! $this->quota_total_bytes) {
            return null;
        }

        return round(($this->quota_used_bytes ?? 0) / $this->quota_total_bytes * 100, 1);
    }
}
