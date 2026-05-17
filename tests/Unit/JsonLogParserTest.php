<?php

use App\Services\Rclone\JsonLogParser;

it('parses json log lines and skips garbage', function () {
    $parser = new JsonLogParser;

    $log = implode("\n", [
        '{"level":"info","msg":"There was nothing to transfer"}',
        'not json',
        '{"level":"error","msg":"failed to copy"}',
    ]);

    $entries = $parser->parse($log);

    expect($entries)->toHaveCount(2)
        ->and($parser->classify($entries[0]))->toBe('transfer.completed')
        ->and($parser->classify($entries[1]))->toBe('error');
});
