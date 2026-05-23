<?php

use App\Models\Account;
use App\Models\SyncHistory;
use App\Services\Files\PendingOps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// PendingOps lives in a real file (the watcher/tray read it too), so save and
// restore it around each test instead of clobbering the running app's state.
beforeEach(function () {
    $this->pendingFile = PendingOps::file();
    $this->pendingBackup = is_file($this->pendingFile) ? file_get_contents($this->pendingFile) : null;
});

afterEach(function () {
    if ($this->pendingBackup !== null) {
        file_put_contents($this->pendingFile, $this->pendingBackup);
    } else {
        @unlink($this->pendingFile);
    }
});

it('marks a stuck "running" sync run as error', function () {
    $account = Account::factory()->create();
    $run = SyncHistory::create([
        'account_id' => $account->id,
        'started_at' => Carbon::now()->subHours(2), // older than the 65-min ceiling
        'status' => 'running',
    ]);

    $this->artisan('rnvsync:heal')->assertSuccessful();

    expect($run->fresh()->status)->toBe('error');
});

it('drops pending markers whose file no longer exists', function () {
    $missing = sys_get_temp_dir().'/rnv-heal-missing-'.uniqid().'.txt';
    PendingOps::mark($missing);

    $this->artisan('rnvsync:heal')->assertSuccessful();

    expect(PendingOps::has($missing))->toBeFalse();
});

it('clears an orphaned pending marker when no download/free job backs it', function () {
    $f = sys_get_temp_dir().'/rnv-heal-orphan-'.uniqid().'.txt';
    File::put($f, 'real'); // exists, so it is not "missing" — only orphaned
    PendingOps::mark($f);

    $this->artisan('rnvsync:heal')->assertSuccessful();

    expect(PendingOps::has($f))->toBeFalse();
    @unlink($f);
});

it('keeps a pending marker while a download/free job is in flight', function () {
    $f = sys_get_temp_dir().'/rnv-heal-keep-'.uniqid().'.txt';
    File::put($f, 'real');
    PendingOps::mark($f);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DownloadPathJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $this->artisan('rnvsync:heal')->assertSuccessful();

    expect(PendingOps::has($f))->toBeTrue(); // left alone — a real op is queued
    @unlink($f);
});
