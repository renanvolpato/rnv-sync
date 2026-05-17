<?php

use App\Logging\SecretRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;

function makeRecord(string $message, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'rnvsync-app',
        level: Level::Info,
        message: $message,
        context: $context,
    );
}

it('redacts tokens in the message string', function () {
    $processor = new SecretRedactionProcessor;

    $record = $processor(makeRecord('got {"access_token":"abc123secret","foo":"bar"}'));

    expect($record->message)->not->toContain('abc123secret')
        ->and($record->message)->toContain('[REDACTED]');
});

it('redacts sensitive context keys', function () {
    $processor = new SecretRedactionProcessor;

    $record = $processor(makeRecord('login', [
        'refresh_token' => 'super-secret',
        'email' => 'user@example.com',
    ]));

    expect($record->context['refresh_token'])->toBe('[REDACTED]')
        ->and($record->context['email'])->toBe('user@example.com');
});

it('redacts rclone config token lines and bearer headers', function () {
    $processor = new SecretRedactionProcessor;

    $record = $processor(makeRecord('token = {"access_token":"zzz"} Authorization: Bearer ey.JJ.xx'));

    expect($record->message)->not->toContain('zzz')
        ->and($record->message)->not->toContain('ey.JJ.xx');
});
