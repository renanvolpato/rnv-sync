<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Password reset via CLI (SPEC §12 — there is no email system).
 *
 *   php artisan rnvsync:reset-password
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'rnvsync:reset-password {email? : The panel user email}';

    protected $description = 'Reset the RNV Sync panel user password';

    public function handle(): int
    {
        $user = User::query()->count() === 1
            ? User::query()->first()
            : User::query()->where('email', $this->argument('email') ?: $this->ask('Panel user email'))->first();

        if (! $user) {
            $this->error('No matching panel user found.');

            return self::FAILURE;
        }

        $min = (int) config('rnvsync.defaults.password_min_length');

        $password = $this->secret("New password (min {$min} characters)");

        if (strlen((string) $password) < $min) {
            $this->error("Password must be at least {$min} characters.");

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm new password')) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user->update(['password' => Hash::make($password)]);

        $this->info("Password reset for {$user->email}.");

        return self::SUCCESS;
    }
}
