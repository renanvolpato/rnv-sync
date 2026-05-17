<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Account;
use App\Models\Setting;
use App\Models\SyncFolder;

/**
 * Export / import RNV Sync configuration (SPEC F5.9).
 *
 * Tokens are machine-local and intentionally excluded — imported
 * accounts come back as `disconnected` and must be re-authenticated.
 */
class ConfigService
{
    /**
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'settings' => Setting::query()
                ->whereNotIn('key', ['sync_paused'])
                ->where('key', 'not like', 'sync_paused_account_%')
                ->pluck('value', 'key')
                ->all(),
            'accounts' => Account::with('syncFolders')->get()->map(fn (Account $a) => [
                'name' => $a->name,
                'provider' => $a->provider,
                'email' => $a->email,
                'drive_type' => $a->drive_type,
                'folders' => $a->syncFolders->map(fn (SyncFolder $f) => [
                    'remote_path' => $f->remote_path,
                    'sync_mode' => $f->sync_mode,
                    'transfers' => $f->transfers,
                    'checkers' => $f->checkers,
                    'chunk_size' => $f->chunk_size,
                    'is_active' => $f->is_active,
                ])->all(),
            ])->all(),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->export(), JSON_PRETTY_PRINT);
    }

    /**
     * Import a previously exported config. Returns the number of
     * accounts created.
     */
    public function import(string $json): int
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('Invalid config file.');
        }

        foreach ($data['settings'] ?? [] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        }

        $created = 0;
        foreach ($data['accounts'] ?? [] as $acc) {
            $account = Account::create([
                'name' => $acc['name'] ?? 'Imported',
                'provider' => $acc['provider'] ?? Account::PROVIDER_PERSONAL,
                'email' => $acc['email'] ?? null,
                'drive_type' => $acc['drive_type'] ?? null,
                'remote_name' => 'imported_'.uniqid(),
                'status' => Account::STATUS_DISCONNECTED, // needs re-auth
            ]);
            $created++;

            foreach ($acc['folders'] ?? [] as $f) {
                SyncFolder::create([
                    'account_id' => $account->id,
                    'remote_path' => $f['remote_path'] ?? '/',
                    'local_path' => '',
                    'sync_mode' => $f['sync_mode'] ?? 'bisync',
                    'transfers' => $f['transfers'] ?? null,
                    'checkers' => $f['checkers'] ?? null,
                    'chunk_size' => $f['chunk_size'] ?? null,
                    'is_active' => false,
                ]);
            }
        }

        return $created;
    }
}
