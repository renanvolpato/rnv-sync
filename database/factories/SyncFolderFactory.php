<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SyncFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncFolder>
 */
class SyncFolderFactory extends Factory
{
    protected $model = SyncFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->word();

        return [
            'account_id' => Account::factory(),
            'remote_path' => $path,
            'local_path' => '/home/user/RnvSync'.$path,
            'sync_mode' => 'bisync',
            'is_active' => false,
        ];
    }
}
