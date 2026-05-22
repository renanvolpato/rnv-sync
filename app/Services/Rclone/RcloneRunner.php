<?php

declare(strict_types=1);

namespace App\Services\Rclone;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The single gateway for invoking the bundled rclone binary
 * (SPEC §8 "Invocation Pattern"). Mock this class in tests.
 *
 * Every invocation is isolated from the user's own rclone config via
 * `--config={app config path}` and uses `--use-json-log` for structured
 * output.
 */
class RcloneRunner
{
    /**
     * Verbs that actually move bytes. While one of these runs we turn on
     * rclone's remote-control server so the tray can show a live, OneDrive
     * -style per-file transfer queue (read via /sync-state → core/stats).
     */
    private const TRANSFER_VERBS = ['copy', 'copyto', 'sync', 'bisync', 'move', 'moveto'];

    public function __construct(private readonly RcloneBinary $binary) {}

    /** Where the live-stats endpoint of the in-flight transfer is advertised. */
    public static function rcStateFile(): string
    {
        return storage_path('app/rnvsync-rc.json');
    }

    /**
     * Run rclone synchronously and return a structured result.
     *
     * @param  list<string>  $args
     * @param  array{timeout?:int,json_log?:bool}  $options
     */
    public function run(array $args, array $options = []): RcloneResult
    {
        $this->binary->assertAvailable();

        // Only transfers get the live-stats server; quick listings don't
        // need it (and shouldn't pay for a port). Always best-effort: if
        // anything about the rc setup fails, the transfer still runs.
        $rcPort = $this->beginLiveStats($args);
        if ($rcPort !== null) {
            $args = [...$args, '--rc', '--rc-addr', '127.0.0.1:'.$rcPort, '--rc-no-auth'];
        }

        $command = $this->buildCommand($args, $options['json_log'] ?? false);

        try {
            $result = Process::timeout($options['timeout'] ?? 120)->run($command);
        } finally {
            if ($rcPort !== null) {
                $this->endLiveStats();
            }
        }

        return new RcloneResult(
            exitCode: $result->exitCode() ?? 1,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
        );
    }

    /**
     * If $args is a transfer, grab a free localhost port and advertise it
     * so the tray can read live progress; returns the port (or null to run
     * without live stats). Strictly best-effort — never throws.
     *
     * @param  list<string>  $args
     */
    private function beginLiveStats(array $args): ?int
    {
        if (! in_array($args[0] ?? '', self::TRANSFER_VERBS, true)) {
            return null;
        }

        $port = $this->findFreePort();
        if ($port === null) {
            return null; // degrade gracefully — the transfer still runs
        }

        try {
            File::put(self::rcStateFile(), (string) json_encode([
                'port' => $port,
                'verb' => $args[0],
                'started_at' => time(),
            ]));
        } catch (\Throwable) {
            // Stats are a nicety; a storage hiccup must not block syncing.
        }

        return $port;
    }

    private function endLiveStats(): void
    {
        try {
            File::delete(self::rcStateFile());
        } catch (\Throwable) {
            // ignore — a stale file just yields "nothing transferring".
        }
    }

    /**
     * Ask the OS for a free localhost TCP port (bind :0, read it, release).
     * The race between releasing and rclone re-binding is negligible on a
     * single-user box where the queue runs one transfer at a time; if it
     * ever loses, rclone would error and we'd simply lose live stats for
     * that run — the retry picks a fresh port.
     */
    private function findFreePort(): ?int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return null;
        }

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        if (! is_string($name) || ($pos = strrpos($name, ':')) === false) {
            return null;
        }

        $port = (int) substr($name, $pos + 1);

        return $port > 0 ? $port : null;
    }

    /**
     * Spawn rclone detached and return its PID (long-running ops: mount/sync).
     *
     * @param  list<string>  $args
     * @param  array{json_log?:bool}  $options
     */
    public function runBackground(array $args, array $options = []): int
    {
        return $this->spawn($args, '/dev/null', $options['json_log'] ?? true);
    }

    /**
     * Spawn rclone detached, capturing combined output to $logFile.
     * Used by the zero-config OAuth flow (`rclone authorize`).
     *
     * @param  list<string>  $args
     */
    public function runBackgroundLogged(array $args, string $logFile, bool $jsonLog = false): int
    {
        return $this->spawn($args, $logFile, $jsonLog);
    }

    /**
     * @param  list<string>  $args
     */
    private function spawn(array $args, string $outFile, bool $jsonLog): int
    {
        $this->binary->assertAvailable();

        // Properly shell-escape every argument (the previous version
        // concatenated an array, producing a broken command).
        $command = implode(' ', array_map(
            'escapeshellarg',
            $this->buildCommand($args, $jsonLog),
        ));

        // Detach the child so it survives the PHP request:
        //  - setsid: new session, no controlling terminal
        //  - close every inherited fd except 0/1/2 BEFORE exec'ing rclone
        //    so long-lived processes (esp. `rclone mount`) never hold the
        //    web server's listening socket and pin its port.
        $target = escapeshellarg($outFile);
        $inner = 'cd / ; for f in /proc/$$/fd/*; do n=${f##*/}; '
            .'[ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            ."exec {$command} > {$target} 2>&1";
        $wrapped = 'setsid bash -c '.escapeshellarg($inner).' < /dev/null & echo $!';

        $pid = trim(Process::run(['bash', '-c', $wrapped])->output());

        return (int) $pid;
    }

    public function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return Process::run(['bash', '-c', "kill -0 {$pid} 2>/dev/null"])->successful();
    }

    public function killProcess(int $pid): bool
    {
        if (! $this->isProcessAlive($pid)) {
            return false;
        }

        return Process::run(['bash', '-c', "kill -TERM {$pid} 2>/dev/null"])->successful();
    }

    /**
     * Build the full argument list, always prepending the bundled binary,
     * the isolated config path and structured logging.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function buildCommand(array $args, bool $jsonLog): array
    {
        $base = [
            $this->binary->path(),
            '--config='.$this->binary->configPath(),
        ];

        if ($jsonLog) {
            $base[] = '--use-json-log';
        }

        return array_values([...$base, ...$args]);
    }
}
