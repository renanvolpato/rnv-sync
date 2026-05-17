<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'provider' => 'onedrive_personal',
            'remote_name' => 'onedrive_'.fake()->unique()->numberBetween(1, 9999),
            'email' => fake()->safeEmail(),
            'oauth_token' => json_encode([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'expiry' => now()->addHour()->toIso8601String(),
            ]),
            'status' => Account::STATUS_ACTIVE,
            'quota_total_bytes' => 1099511627776, // 1 TiB
            'quota_used_bytes' => 274877906944,   // 256 GiB
        ];
    }

    public function disconnected(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_DISCONNECTED]);
    }
}
