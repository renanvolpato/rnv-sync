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
        $name = fake()->unique()->word();

        return [
            'account_id' => Account::factory(),
            'remote_path' => $name,
            'local_path' => sys_get_temp_dir().'/rnvsync-test/'.$name,
            'sync_mode' => 'bisync',
            'is_active' => false,
        ];
    }
}
