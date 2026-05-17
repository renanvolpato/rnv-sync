<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Services\Rclone\RcloneBinary;

/**
 * Environment preflight (WordPress-style first-run requirements check).
 *
 * Detects everything RNV Sync needs before the setup wizard can run and,
 * for each missing piece, provides a copy-paste command tailored to the
 * detected Linux distribution. Must never touch the database — it runs
 * before the DB driver may even be available.
 */
class RequirementsService
{
    public function __construct(private readonly RcloneBinary $rclone) {}

    /**
     * @return list<array{key:string,label:string,ok:bool,critical:bool,hint:string,command:?string}>
     */
    public function checks(): array
    {
        return [
            $this->check(
                'php',
                'PHP >= 8.3',
                version_compare(PHP_VERSION, '8.3.0', '>='),
                true,
                'RNV Sync requires PHP 8.3 or newer. Current: '.PHP_VERSION,
                null,
            ),
            $this->check(
                'pdo_sqlite',
                'SQLite PHP extension (pdo_sqlite, sqlite3)',
                extension_loaded('pdo_sqlite') && extension_loaded('sqlite3'),
                true,
                'The SQLite driver is missing. Install it, then re-check.',
                $this->sqlitePackageCommand(),
            ),
            $this->check(
                'app_key',
                'Application encryption key',
                ! empty(config('app.key')),
                true,
                'APP_KEY is not set (tokens are encrypted with it).',
                'php artisan key:generate',
            ),
            $this->check(
                'storage_writable',
                'Writable storage directory',
                is_writable(storage_path()) && is_writable(base_path()),
                true,
                'The storage/ directory must be writable by the web user.',
                'chmod -R u+rwX '.storage_path(),
            ),
            $this->check(
                'database',
                'SQLite database file',
                $this->databaseReady(),
                true,
                'The SQLite database file is missing or migrations have not run.',
                'touch '.$this->databasePath().' && php artisan migrate --force',
            ),
            $this->check(
                'rclone',
                'Bundled rclone binary',
                $this->rclone->isAvailable(),
                false, // non-critical: panel works; sync/mount need it
                'rclone is not bundled yet. Sync and Files-on-Demand need it.',
                'bash install/bootstrap.sh',
            ),
        ];
    }

    public function allCriticalMet(): bool
    {
        foreach ($this->checks() as $c) {
            if ($c['critical'] && ! $c['ok']) {
                return false;
            }
        }

        return true;
    }

    /** The single command that fixes everything (used by the UI banner). */
    public function bootstrapCommand(): string
    {
        return 'bash install/bootstrap.sh';
    }

    /** Configured SQLite target (may be the literal ":memory:"). */
    private function configuredDatabase(): string
    {
        return (string) config('database.connections.sqlite.database');
    }

    /** A concrete path to suggest in the fix command. */
    private function databasePath(): string
    {
        $db = $this->configuredDatabase();

        return ($db === '' || $db === ':memory:') ? storage_path('database.sqlite') : $db;
    }

    private function databaseReady(): bool
    {
        if (! extension_loaded('pdo_sqlite')) {
            return false;
        }

        // In-memory (tests) is always ready once the driver is present.
        if ($this->configuredDatabase() === ':memory:') {
            return true;
        }

        return is_file($this->databasePath());
    }

    /**
     * @return array{key:string,label:string,ok:bool,critical:bool,hint:string,command:?string}
     */
    private function check(string $key, string $label, bool $ok, bool $critical, string $hint, ?string $command): array
    {
        return compact('key', 'label', 'ok', 'critical', 'hint', 'command');
    }

    /** Distribution-aware install command for the SQLite PHP extension. */
    private function sqlitePackageCommand(): string
    {
        return match ($this->distroId()) {
            'ubuntu', 'debian', 'pop', 'linuxmint' => 'sudo apt-get install -y php8.3-sqlite3',
            'fedora', 'rhel', 'centos' => 'sudo dnf install -y php-pdo',
            'arch', 'manjaro' => 'sudo pacman -S --noconfirm php-sqlite',
            'alpine' => 'apk add --no-cache php83-pdo_sqlite php83-sqlite3',
            default => 'sudo apt-get install -y php8.3-sqlite3   # adjust for your distro',
        };
    }

    private function distroId(): string
    {
        if (! is_readable('/etc/os-release')) {
            return 'unknown';
        }

        foreach (file('/etc/os-release') ?: [] as $line) {
            if (str_starts_with($line, 'ID=')) {
                return trim(strtolower(trim(substr($line, 3), "\"' \n")));
            }
        }

        return 'unknown';
    }
}
