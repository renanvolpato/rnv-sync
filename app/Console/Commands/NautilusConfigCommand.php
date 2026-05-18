<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Files\PendingOps;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the config the Nautilus extension reads:
 * ~/.config/rnv-sync/extension.json — the command prefix to invoke and
 * the list of account base directories to decorate with emblems.
 */
class NautilusConfigCommand extends Command
{
    protected $signature = 'rnvsync:nautilus-config';

    protected $description = 'Refresh the file-manager extension config (bases + CLI path)';

    public function handle(SettingsRepository $settings): int
    {
        $bases = Account::all()
            ->map(fn (Account $a) => rtrim($settings->mountBase(), '/').'/'.$a->name)
            ->values()
            ->all();

        $config = [
            'php' => PHP_BINARY,
            'artisan' => base_path('artisan'),
            'bases' => $bases,
            'pending' => PendingOps::file(),
        ];

        $dir = ($_SERVER['HOME'] ?? sys_get_temp_dir()).'/.config/rnv-sync';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/extension.json', json_encode($config, JSON_PRETTY_PRINT));

        $this->info('Wrote '.$dir.'/extension.json ('.count($bases).' base(s)).');

        return self::SUCCESS;
    }
}
