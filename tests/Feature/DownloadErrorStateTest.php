<?php

use App\Exceptions\RcloneException;
use App\Jobs\DownloadPathJob;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Models\Account;
use App\Models\User;
use App\Services\Files\LocalFiles;
use App\Services\Files\PathErrors;
use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rnv-err-'.uniqid();
    app(SettingsRepository::class)->set(SettingsRepository::KEY_MOUNT_BASE, $this->base);
    @unlink(PendingOps::file());
    @unlink(PathErrors::file());
    $this->account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);
});

afterEach(function () {
    File::deleteDirectory($this->base);
    @unlink(PendingOps::file());
    @unlink(PathErrors::file());
});

it('a failed download surfaces an error state, not a silent revert', function () {
    $files = app(LocalFiles::class);
    $abs = $files->localPathFor($this->account, 'doc.txt');
    PendingOps::mark($abs);

    expect($files->status($this->account, 'doc.txt'))->toBe('syncing');

    // Simulate the job exhausting retries and failing.
    (new DownloadPathJob($this->account->id, 'doc.txt'))
        ->failed(new RuntimeException('rclone: token expired'));

    expect(PendingOps::has($abs))->toBeFalse()
        ->and($files->status($this->account, 'doc.txt'))->toBe('error')
        ->and($files->errorFor($this->account, 'doc.txt'))->toContain('token expired');
});

it('retrying clears the error and goes back to syncing', function () {
    Queue::fake(); // don't run the job inline
    $this->actingAs(User::factory()->create());
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([['Name' => 'doc.txt', 'IsDir' => false, 'Size' => 4]]), '')
    );

    $files = app(LocalFiles::class);
    $abs = $files->localPathFor($this->account, 'doc.txt');
    PathErrors::mark($abs, 'previous failure');

    expect($files->status($this->account, 'doc.txt'))->toBe('error');

    Livewire::test(FileBrowser::class, ['account' => $this->account])
        ->call('download', 'doc.txt', false, 4);

    expect(PathErrors::has($abs))->toBeFalse()
        ->and(PendingOps::has($abs))->toBeTrue()
        ->and($files->status($this->account, 'doc.txt'))->toBe('syncing');
});

it('does not clear pending on a thrown download (keeps ⟳ across retries)', function () {
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andThrow(new RcloneException('boom'));

    $files = app(LocalFiles::class);
    $abs = $files->localPathFor($this->account, 'x.txt');
    PendingOps::mark($abs);

    try {
        (new DownloadPathJob($this->account->id, 'x.txt'))->handle($files);
    } catch (Throwable) {
        // expected — re-queued by the queue with backoff
    }

    expect(PendingOps::has($abs))->toBeTrue(); // still ⟳, not reverted
});
