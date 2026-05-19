<?php

use App\Jobs\DownloadPathJob;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Models\Account;
use App\Models\User;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-phys-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    app(SettingsRepository::class)->set(SettingsRepository::KEY_STORAGE_MODE, 'physical');
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('reports cloud vs downloaded by real file presence', function () {
    $files = app(LocalFiles::class);
    $rel = 'Docs/a.txt';

    expect($files->status($this->account, $rel))->toBe('cloud');

    $local = $files->localPathFor($this->account, $rel);
    File::ensureDirectoryExists(dirname($local));
    File::put($local, 'real content');

    expect($files->status($this->account, $rel))->toBe('downloaded');
});

it('free() leaves a 0-byte cloud placeholder, keeping it visible', function () {
    // Upload must succeed before the local copy may be dropped.
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')->andReturn(new RcloneResult(0, '', ''));

    $files = app(LocalFiles::class);
    $rel = 'Docs/a.txt';
    $local = $files->localPathFor($this->account, $rel);
    File::ensureDirectoryExists(dirname($local));
    File::put($local, 'real content');

    $files->free($this->account, $rel);

    expect(File::exists($local))->toBeTrue()
        ->and(filesize($local))->toBe(0)
        ->and($files->status($this->account, $rel))->toBe('cloud');
});

it('free() never drops the local file when the upload fails (data-safety)', function () {
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')->andReturn(new RcloneResult(1, '', 'network down'));

    $files = app(LocalFiles::class);
    $rel = 'Docs/a.txt';
    $local = $files->localPathFor($this->account, $rel);
    File::ensureDirectoryExists(dirname($local));
    File::put($local, 'real content');

    expect(fn () => $files->free($this->account, $rel))
        ->toThrow(RuntimeException::class);

    // The user's real content is still on disk, untouched.
    expect(File::get($local))->toBe('real content');
});

it('download() copies a file with rclone copyto to the physical path', function () {
    $seen = [];
    $this->mock(RcloneRunner::class)
        ->shouldReceive('run')
        ->andReturnUsing(function ($args) use (&$seen) {
            $seen[] = $args[0] ?? '';
            if (($args[0] ?? '') === 'lsjson') {
                return new RcloneResult(0, json_encode([['Name' => 'a.txt', 'IsDir' => false]]), '');
            }

            return new RcloneResult(0, '', '');
        });

    app(LocalFiles::class)->download($this->account, 'Docs/a.txt');

    expect($seen)->toContain('copyto');
});

it('the mount supervisor does nothing in physical mode', function () {
    $this->mock(RcloneRunner::class)->shouldNotReceive('runBackground');

    $this->artisan('rnvsync:mount-supervisor')->assertSuccessful();
});

it('file browser (physical) shows cloud and queues a download', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([['Name' => 'report.pdf', 'IsDir' => false, 'Size' => 9]]), '')
    );

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->assertSet('physical', true)
        ->assertSee(__('cache.status_cloud'))
        ->call('download', 'report.pdf', false, 9)
        ->assertDispatched('toast');

    Queue::assertPushed(DownloadPathJob::class);
});

it('rnvsync:fs maps an absolute path back to the account', function () {
    Queue::fake();
    $abs = rtrim($this->base, '/').'/OneDrive/Docs/file.txt';

    $this->artisan('rnvsync:fs', ['action' => 'download', 'path' => $abs])
        ->assertSuccessful();

    Queue::assertPushed(DownloadPathJob::class, fn ($j) => $j->path === 'Docs/file.txt'
        && $j->accountId === $this->account->id);
});

it('rnvsync:nautilus-config writes the extension config', function () {
    $home = sys_get_temp_dir().'/rnv-home-'.uniqid();
    $_SERVER['HOME'] = $home;

    $this->artisan('rnvsync:nautilus-config')->assertSuccessful();

    $cfg = json_decode(File::get($home.'/.config/rnv-sync/extension.json'), true);
    expect($cfg['bases'][0])->toContain('/OneDrive')
        ->and($cfg)->toHaveKeys(['php', 'artisan', 'bases']);

    File::deleteDirectory($home);
});

it('tryRemoveEmptyShell drops a placeholder-only tree', function () {
    $base = sys_get_temp_dir().'/rnv-shell-'.uniqid();
    File::ensureDirectoryExists($base.'/sub/deeper');
    File::put($base.'/a.txt', '');               // placeholder (0 byte)
    File::put($base.'/sub/b.txt', '');           // placeholder

    expect(app(LocalFiles::class)->tryRemoveEmptyShell($base))->toBeTrue()
        ->and(is_dir($base))->toBeFalse();
});

it('tryRemoveEmptyShell keeps the tree if ANY real file exists (data-safety)', function () {
    $base = sys_get_temp_dir().'/rnv-shell-'.uniqid();
    File::ensureDirectoryExists($base.'/sub');
    File::put($base.'/a.txt', '');               // placeholder
    File::put($base.'/sub/keepme.txt', 'real content here');

    expect(app(LocalFiles::class)->tryRemoveEmptyShell($base))->toBeFalse()
        ->and(File::get($base.'/sub/keepme.txt'))->toBe('real content here');

    File::deleteDirectory($base);
});
