<?php

use App\Jobs\DownloadPathJob;
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
    $this->base = sys_get_temp_dir().'/rnv-pend-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    @unlink(PendingOps::file());
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);
});

afterEach(function () {
    File::deleteDirectory($this->base);
    @unlink(PendingOps::file());
});

it('marks, reports and clears a pending path', function () {
    $p = '/tmp/x/y';
    expect(PendingOps::has($p))->toBeFalse();
    PendingOps::mark($p);
    expect(PendingOps::has($p))->toBeTrue()
        ->and(PendingOps::all())->toContain($p);
    PendingOps::clear($p);
    expect(PendingOps::has($p))->toBeFalse();
});

it('LocalFiles reports "syncing" while an op is pending', function () {
    $files = app(LocalFiles::class);
    $abs = $files->localPathFor($this->account, 'Docs/a.txt');

    PendingOps::mark($abs);
    expect($files->status($this->account, 'Docs/a.txt'))->toBe('syncing');

    PendingOps::clear($abs);
    expect($files->status($this->account, 'Docs/a.txt'))->toBe('cloud');
});

it('download marks pending immediately and the job clears it', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([['Name' => 'a.txt', 'IsDir' => false, 'Size' => 5]]), '')
    );

    $abs = app(LocalFiles::class)->localPathFor($this->account, 'a.txt');

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('download', 'a.txt', false, 5);

    expect(PendingOps::has($abs))->toBeTrue();          // shows "syncing" now
    Queue::assertPushed(DownloadPathJob::class);

    (new DownloadPathJob($this->account->id, 'a.txt'))->handle(app(LocalFiles::class));
    expect(PendingOps::has($abs))->toBeFalse();         // cleared when done
});
