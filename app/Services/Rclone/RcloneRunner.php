<?php

declare(strict_types=1);

namespace App\Services\Rclone;

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
    public function __construct(private readonly RcloneBinary $binary) {}

    /**
     * Run rclone synchronously and return a structured result.
     *
     * @param  list<string>  $args
     * @param  array{timeout?:int,json_log?:bool}  $options
     */
    public function run(array $args, array $options = []): RcloneResult
    {
        $this->binary->assertAvailable();

        $command = $this->buildCommand($args, $options['json_log'] ?? false);

        $result = Process::timeout($options['timeout'] ?? 120)->run($command);

        return new RcloneResult(
            exitCode: $result->exitCode() ?? 1,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
        );
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
