<?php

use App\Events\ConflictDetected;
use App\Models\Account;
use App\Models\Conflict;
use App\Services\Conflicts\ConflictsService;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\Event;

function logEntry(string $msg): array
{
    return ['level' => 'info', 'msg' => $msg, 'raw' => []];
}

it('creates conflict records and emits an event (EARS F4.4)', function () {
    Event::fake([ConflictDetected::class]);
    $account = Account::factory()->create();

    $created = app(ConflictsService::class)->detectFromLog($account, [
        logEntry('Conflict detected for "Documents/report.docx"'),
        logEntry('nothing to see here'),
    ]);

    expect($created)->toBe(1);
    $this->assertDatabaseHas('rnvsync_conflicts', [
        'account_id' => $account->id,
        'path' => 'Documents/report.docx',
        'status' => 'pending',
    ]);
    Event::assertDispatched(ConflictDetected::class);
});

it('pauses an account automatically when conflicts exceed 10 (EARS F4.4)', function () {
    $account = Account::factory()->create();
    $svc = app(ConflictsService::class);

    $entries = [];
    for ($i = 1; $i <= 11; $i++) {
        $entries[] = logEntry("conflict on \"file{$i}.txt\"");
    }
    $svc->detectFromLog($account, $entries);

    expect($svc->pendingCount($account))->toBe(11)
        ->and($svc->isAccountPaused($account))->toBeTrue();
});

it('resolves a conflict, applies it and clears the auto-pause', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')->andReturn(new RcloneResult(0, '', ''));

    $account = Account::factory()->create();
    $svc = app(ConflictsService::class);
    $svc->setAccountPaused($account, true);

    $conflict = Conflict::create([
        'account_id' => $account->id, 'path' => 'a.txt',
        'status' => 'pending', 'detected_at' => now(),
    ]);

    $svc->resolve($conflict, 'remote');

    expect($conflict->fresh()->status)->toBe('resolved_remote')
        ->and($svc->isAccountPaused($account))->toBeFalse();
});

it('bulk-resolves all pending conflicts for an account (F4.6)', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')->andReturn(new RcloneResult(0, '', ''));

    $account = Account::factory()->create();
    Conflict::insert([
        ['account_id' => $account->id, 'path' => 'x', 'status' => 'pending', 'detected_at' => now()],
        ['account_id' => $account->id, 'path' => 'y', 'status' => 'pending', 'detected_at' => now()],
    ]);

    $n = app(ConflictsService::class)->resolveAll($account, 'local');

    expect($n)->toBe(2)
        ->and(Conflict::where('status', 'resolved_local')->count())->toBe(2);
});
