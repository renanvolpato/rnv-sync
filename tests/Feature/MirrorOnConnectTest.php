<?php

use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Sync\RemoteFolderMirror;
use Illuminate\Support\Facades\Queue;

it('mirrors EVERY top-level cloud folder as online placeholders, no selection needed', function () {
    Queue::fake();
    $account = Account::factory()->create(['name' => 'OneDrive', 'remote_name' => 'od1']);

    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, (string) json_encode([
            ['Name' => 'Documentos', 'IsDir' => true],
            ['Name' => 'Imagens', 'IsDir' => true],
            ['Name' => 'Trabalho', 'IsDir' => true],
            ['Name' => 'Cofre Pessoal', 'IsDir' => true], // Vault → must be skipped
        ]), '')
    );
    // Each adopted folder is materialised inline as ☁ placeholders.
    $this->mock(LocalFiles::class)
        ->shouldReceive('materializeCloudPlaceholders')->times(3)->andReturn(0);

    // The whole-drive mirror that runs on account connect.
    app(RemoteFolderMirror::class)->discover($account);

    // All real folders are now tracked, active and on-demand (online by default).
    $folders = SyncFolder::where('account_id', $account->id)->get();
    expect($folders)->toHaveCount(3)
        ->and($folders->every(fn ($f) => $f->is_active && $f->sync_mode === 'on_demand'))->toBeTrue()
        ->and($folders->pluck('remote_path')->all())->toEqualCanonicalizing(['Documentos', 'Imagens', 'Trabalho'])
        ->and(SyncFolder::where('remote_path', 'Cofre Pessoal')->exists())->toBeFalse();

    // A change-sync is queued per folder to carry data both ways thereafter.
    Queue::assertPushed(SyncChangesJob::class, 3);
});

it('never resurrects a folder the user explicitly removed', function () {
    Queue::fake();
    $account = Account::factory()->create(['remote_name' => 'od1']);

    // User had removed "Antiga" before → its inactive row must keep it out.
    SyncFolder::factory()->create([
        'account_id' => $account->id, 'remote_path' => 'Antiga', 'is_active' => false,
    ]);

    $this->mock(RcloneConfigGenerator::class)->shouldReceive('regenerate');
    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, (string) json_encode([
            ['Name' => 'Antiga', 'IsDir' => true],  // removed earlier → skip
            ['Name' => 'Nova', 'IsDir' => true],    // genuinely new → adopt
        ]), '')
    );
    $this->mock(LocalFiles::class)
        ->shouldReceive('materializeCloudPlaceholders')->once()->andReturn(0);

    app(RemoteFolderMirror::class)->discover($account);

    expect(SyncFolder::where('remote_path', 'Antiga')->where('is_active', true)->exists())->toBeFalse()
        ->and(SyncFolder::where('remote_path', 'Nova')->where('is_active', true)->exists())->toBeTrue();
});
