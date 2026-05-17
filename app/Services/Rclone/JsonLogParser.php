<?php

declare(strict_types=1);

namespace App\Services\Rclone;

/**
 * Parses rclone `--use-json-log` output, one JSON object per line
 * (SPEC §8 "Parsing JSON Log Output").
 *
 * v0.1.0 only needs classification; Reverb event dispatch for progress
 * arrives with sync in v0.2.0.
 */
class JsonLogParser
{
    /**
     * Parse a block of log text into structured entries.
     *
     * @return list<array{level:string,msg:string,raw:array<string,mixed>}>
     */
    public function parse(string $log): array
    {
        $entries = [];

        foreach (preg_split('/\r?\n/', trim($log)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            $entries[] = [
                'level' => (string) ($decoded['level'] ?? 'info'),
                'msg' => (string) ($decoded['msg'] ?? ''),
                'raw' => $decoded,
            ];
        }

        return $entries;
    }

    /**
     * Classify a single parsed entry into an app-level event name.
     *
     * @param  array{level:string,msg:string,raw:array<string,mixed>}  $entry
     */
    public function classify(array $entry): string
    {
        if ($entry['level'] === 'error') {
            return 'error';
        }

        return match (true) {
            str_contains($entry['msg'], 'Transferred:') => 'transfer.progress',
            str_contains($entry['msg'], 'There was nothing to transfer') => 'transfer.completed',
            default => 'info',
        };
    }
}
