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

    // 1) push: copy local -> remote with --min-size 1 (no placeholders)
    $push = $calls[0];
    expect($push[0])->toBe('copy')
        ->and($push[1])->toBe($this->folder->local_path)
        ->and($push)->toContain('--min-size')->toContain('1');

    // 2) pull: copy remote -> local --files-from <list of real files>
    $pull = $calls[1];
    expect($pull[0])->toBe('copy')
        ->and($pull[2])->toBe($this->folder->local_path)
        ->and($pull)->toContain('--files-from');

    expect($push)->not->toContain('--files-from'); // push is real-only
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
