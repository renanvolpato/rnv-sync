<?php

use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-changes-'.uniqid();
    $this->account = Account::factory()->create(['remote_name' => 'od1']);
    $this->folder = SyncFolder::factory()->create([
        'account_id' => $this->account->id,
        'remote_path' => 'Docs',
        'local_path' => $this->base.'/OneDrive/Docs',
        'sync_mode' => 'on_demand',
        'is_active' => true,
    ]);
    File::ensureDirectoryExists($this->folder->local_path);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('pushes real files only (skips placeholders) and pulls just kept files', function () {
    // a real kept-offline file + a 0-byte placeholder
    File::put($this->folder->local_path.'/keep.txt', 'real content');
    File::put($this->folder->local_path.'/ph.txt', '');

    $calls = [];
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturnUsing(function ($args) use (&$calls) {
            $calls[] = $args;

            return new RcloneResult(0, '', '');
        });
    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');

    (new SyncChangesJob($this->folder->id))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class)
    );

    // push: copy local -> remote, --min-size 1b (skip 0-byte placeholders),
    // --update (don't clobber newer cloud), real-only (no --files-from).
    $push = $calls[0];
    expect($push[0])->toBe('copy')
        ->and($push[1])->toBe($this->folder->local_path)
        ->and($push)->toContain('--min-size')->toContain('1b')
        ->and($push)->toContain('--update')
        ->and($push)->toContain('--exclude')
        ->and($push)->not->toContain('--files-from');

    // Between push and pull there's now a SAFETY probe — a batched
    // 'rclone lsjson --files-from list' that drops any path whose CLOUD entry
    // is 0 bytes, so the pull can never replace a real local file with a
    // corrupted 0-byte cloud upload.
    $safety = $calls[1];
    expect($safety[0])->toBe('lsjson')
        ->and($safety)->toContain('--files-from');

    // pull: copy remote -> local --files-from <list of real files>, --update,
    // and crucially NO --exclude (rclone rejects --files-from + filters).
    $pull = $calls[2];
    expect($pull[0])->toBe('copy')
        ->and($pull[2])->toBe($this->folder->local_path)
        ->and($pull)->toContain('--files-from')
        ->and($pull)->toContain('--update')
        ->and($pull)->not->toContain('--exclude');

    // The change-sync's only heavy verbs are copy (push/pull) and the new
    // lightweight lsjson probe in between; the recursive listing moved to
    // rnvsync:refresh-placeholders.
    expect(collect($calls)->every(fn ($c) => in_array($c[0] ?? '', ['copy', 'lsjson'], true)))->toBeTrue();
});

it('does no transfer work for a fully-online folder (placeholders only)', function () {
    // Only 0-byte placeholders → nothing real to push or pull, and the heavy
    // listing lives elsewhere, so the job must not touch rclone at all.
    File::put($this->folder->local_path.'/a.txt', '');
    File::put($this->folder->local_path.'/b.txt', '');

    $this->mock(RcloneRunner::class)->shouldNotReceive('run');
    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');

    (new SyncChangesJob($this->folder->id))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class)
    );

    expect($this->folder->fresh()->last_sync_status)->toBe('success');
});

it('does nothing for a bisync folder', function () {
    $this->folder->update(['sync_mode' => 'bisync']);

    $this->mock(RcloneRunner::class)->shouldNotReceive('run');
    $this->mock(RcloneConfigGenerator::class)->shouldNotReceive('regenerate');

    (new SyncChangesJob($this->folder->id))->handle(
        app(RcloneRunner::class), app(RcloneConfigGenerator::class)
    );

    expect($this->folder->fresh()->sync_mode)->toBe('bisync');
});
