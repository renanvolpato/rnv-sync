<?php

use App\Console\Commands\WatchFilesCommand;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\PendingOps;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->cmd = new WatchFilesCommand;
    $this->account = Account::factory()->create();
});

it('matches a changed path to its folder by longest prefix', function () {
    $folders = [
        7 => '/home/u/RnvSync/OneDrive/Docs',
        9 => '/home/u/RnvSync/OneDrive/Docs/Sub',
    ];

    expect($this->cmd->folderIdForPath('/home/u/RnvSync/OneDrive/Docs/a.txt', $folders))->toBe(7)
        ->and($this->cmd->folderIdForPath('/home/u/RnvSync/OneDrive/Docs/Sub/b.txt', $folders))->toBe(9)
        ->and($this->cmd->folderIdForPath('/tmp/elsewhere/c.txt', $folders))->toBeNull();
});

it('ignores vault, trash, editor temp files and in-flight ops', function () {
    expect($this->cmd->shouldIgnore('/x/Cofre Pessoal/secret.txt'))->toBeTrue()
        ->and($this->cmd->shouldIgnore('/x/.Trash-1000/old'))->toBeTrue()
        ->and($this->cmd->shouldIgnore('/x/.goutputstream-AB12'))->toBeTrue()
        ->and($this->cmd->shouldIgnore('/x/report.xlsx'))->toBeFalse();

    PendingOps::mark('/x/downloading.bin');
    expect($this->cmd->shouldIgnore('/x/downloading.bin'))->toBeTrue();
    PendingOps::clear('/x/downloading.bin');
});

it('treats a lone OPEN as a hydrate trigger, but not OPEN paired with a change', function () {
    expect($this->cmd->isOpenEvent('OPEN'))->toBeTrue()
        ->and($this->cmd->isOpenEvent('OPEN,ISDIR'))->toBeTrue()      // a dir open (filtered later by is_file)
        ->and($this->cmd->isOpenEvent('CLOSE_WRITE,CLOSE'))->toBeFalse()
        ->and($this->cmd->isOpenEvent('CREATE'))->toBeFalse()
        ->and($this->cmd->isOpenEvent('MOVED_TO'))->toBeFalse()
        ->and($this->cmd->isOpenEvent('DELETE'))->toBeFalse();
});

it('recognises a 0-byte placeholder vs a real file, dir or missing path', function () {
    $dir = sys_get_temp_dir().'/rnv-ph-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put("$dir/cloud.txt", '');     // ☁ placeholder
    File::put("$dir/real.txt", 'data');  // downloaded

    expect($this->cmd->isZeroBytePlaceholder("$dir/cloud.txt"))->toBeTrue()
        ->and($this->cmd->isZeroBytePlaceholder("$dir/real.txt"))->toBeFalse()
        ->and($this->cmd->isZeroBytePlaceholder($dir))->toBeFalse()
        ->and($this->cmd->isZeroBytePlaceholder("$dir/missing"))->toBeFalse();

    File::deleteDirectory($dir);
});

it('only watches active on-demand folders that exist on disk', function () {
    $base = sys_get_temp_dir().'/rnv-watch-'.uniqid();
    File::ensureDirectoryExists($base.'/exists');

    $live = SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
        'sync_mode' => 'on_demand', 'local_path' => $base.'/exists',
    ]);
    SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
        'sync_mode' => 'on_demand', 'local_path' => $base.'/missing',
    ]);
    SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => false,
        'sync_mode' => 'on_demand', 'local_path' => $base.'/exists',
    ]);
    SyncFolder::factory()->create([
        'account_id' => $this->account->id, 'is_active' => true,
        'sync_mode' => 'bisync', 'local_path' => $base.'/exists',
    ]);

    $watched = $this->cmd->activeFolders();

    expect($watched)->toBe([$live->id => $base.'/exists']);

    File::deleteDirectory($base);
});
