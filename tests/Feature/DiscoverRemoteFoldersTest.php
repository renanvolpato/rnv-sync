<?php

use App\Jobs\MaterializePlaceholdersJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\Queue;

it('adds new top-level cloud folders, skips known ones, removed ones and the Vault', function () {
    Queue::fake();
    $account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);

    // already synced → must not duplicate
    SyncFolder::factory()->create([
        'account_id' => $account->id, 'remote_path' => 'Anexos', 'is_active' => true,
    ]);
    // user removed it earlier → must NOT be resurrected
    SyncFolder::factory()->create([
        'account_id' => $account->id, 'remote_path' => 'Removida', 'is_active' => false,
    ]);

    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, (string) json_encode([
            ['Name' => 'Anexos', 'IsDir' => true],        // known active → skip
            ['Name' => 'Removida', 'IsDir' => true],      // user-removed → skip
            ['Name' => 'Cofre Pessoal', 'IsDir' => true], // Personal Vault → skip
            ['Name' => 'Documentos', 'IsDir' => true],    // NEW → adopt
        ]), '')
    );
    // Placeholders are now mirrored INLINE (decoupled from the transfer
    // queue), so the new folder appears immediately for exactly one folder.
    $this->mock(LocalFiles::class)
        ->shouldReceive('materializeCloudPlaceholders')->once()->andReturn(0);

    $this->artisan('rnvsync:discover-remote-folders')->assertSuccessful();

    expect(SyncFolder::where('remote_path', 'Documentos')->where('is_active', true)->exists())->toBeTrue()
        ->and(SyncFolder::where('remote_path', 'Removida')->where('is_active', true)->exists())->toBeFalse()
        ->and(SyncFolder::where('remote_path', 'Anexos')->count())->toBe(1)            // no dupe
        ->and(SyncFolder::where('remote_path', 'Cofre Pessoal')->exists())->toBeFalse(); // Vault skipped

    Queue::assertPushed(SyncChangesJob::class, 1);              // only the new folder's transfer
    Queue::assertNotPushed(MaterializePlaceholdersJob::class);  // materialise is inline now
});

it('does nothing when the remote listing fails (never guesses)', function () {
    Queue::fake();
    $account = Account::factory()->create(['remote_name' => 'od1']);

    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $this->mock(RcloneRunner::class)->shouldReceive('run')
        ->andReturn(new RcloneResult(1, '', 'network down'));

    $this->artisan('rnvsync:discover-remote-folders')->assertSuccessful();

    expect(SyncFolder::where('account_id', $account->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});
