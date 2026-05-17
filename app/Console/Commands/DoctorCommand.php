<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\RequirementsService;
use Illuminate\Console\Command;

/**
 * `php artisan rnvsync:doctor` — CLI mirror of the web preflight.
 * Exit code is non-zero if any critical requirement is unmet.
 */
class DoctorCommand extends Command
{
    protected $signature = 'rnvsync:doctor';

    protected $description = 'Check that the environment is ready to run RNV Sync';

    public function handle(RequirementsService $requirements): int
    {
        $rows = [];

        foreach ($requirements->checks() as $c) {
            $status = $c['ok'] ? '<info>OK</info>' : ($c['critical'] ? '<error>FAIL</error>' : '<comment>WARN</comment>');
            $rows[] = [$status, $c['label'], $c['ok'] ? '' : ($c['command'] ?? $c['hint'])];
        }

        $this->table(['Status', 'Requirement', 'Fix'], $rows);

        if ($requirements->allCriticalMet()) {
            $this->info('All critical requirements met. Run: php artisan migrate && php artisan serve');

            return self::SUCCESS;
        }

        $this->error('Some requirements are missing. Run: '.$requirements->bootstrapCommand());

        return self::FAILURE;
    }
}
