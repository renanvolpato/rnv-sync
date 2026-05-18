<?php

use App\Jobs\FreeOnlineJob;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Models\Account;
use App\Models\User;
use App\Services\Files\LocalFiles;
use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-keeponline-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    @unlink(PendingOps::file());
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);
});

afterEach(function () {
    File::deleteDirectory($this->base);
    @unlink(PendingOps::file());
});

it('uploads a real local file before dropping it (no data loss)', function () {
    $uploaded = false;
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturnUsing(function ($args) use (&$uploaded) {
            if (($args[0] ?? '') === 'copyto') {
                $uploaded = true;
            }

            return new RcloneResult(0, '', '');
        });

    $files = app(LocalFiles::class);
    $local = $files->localPathFor($this->account, 'novo.txt');
    File::ensureDirectoryExists(dirname($local));
    File::put($local, 'conteúdo criado localmente');

    $files->free($this->account, 'novo.txt');

    expect($uploaded)->toBeTrue()                 // uploaded first
        ->and(filesize($local))->toBe(0);         // then placeholdered
});

it('never uploads a 0-byte placeholder (would wipe the cloud file)', function () {
    $touched = false;
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturnUsing(function ($args) use (&$touched) {
            if (($args[0] ?? '') === 'copyto') {
                $touched = true;
            }

            return new RcloneResult(0, '', '');
        });

    $files = app(LocalFiles::class);
    $local = $files->localPathFor($this->account, 'ph.txt');
    File::ensureDirectoryExists(dirname($local));
    File::put($local, ''); // our 0-byte placeholder

    $files->free($this->account, 'ph.txt');

    expect($touched)->toBeFalse(); // no upload of an empty placeholder
});

it('keep-online runs in the background and shows syncing', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([['Name' => 'r.txt', 'IsDir' => false, 'Size' => 9]]), '')
    );

    $abs = app(LocalFiles::class)->localPathFor($this->account, 'r.txt');

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('free', 'r.txt');

    expect(PendingOps::has($abs))->toBeTrue();   // ⟳ syncing immediately
    Queue::assertPushed(FreeOnlineJob::class);
});
