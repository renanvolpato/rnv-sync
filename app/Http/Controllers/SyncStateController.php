<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Files\PendingOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Tiny localhost-only status endpoint for the system-tray indicator.
 * No auth/session (the panel binds to 127.0.0.1 and this exposes no
 * secrets) and dirt-cheap so the tray can poll it every few seconds.
 *
 * Besides the boolean state it returns a short human-readable list of
 * what's in flight so the tray menu can show it (OneDrive-style):
 *  - per-file pin/free operations (PendingOps) → file names
 *  - folder change-syncs queued/running → folder names
 */
class SyncStateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $items = [];

        // Per-file operations the user triggered (Keep local / online).
        foreach (PendingOps::all() as $absPath) {
            $items[] = ['kind' => 'file', 'name' => basename((string) $absPath)];
        }
        $pending = count($items);

        // Folders with a SyncChangesJob queued or running (read our own
        // job payloads — localhost admin endpoint, no secrets).
        $folderIds = [];
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $data = json_decode((string) $payload, true);
            $name = $data['displayName'] ?? '';
            if (! str_contains($name, 'SyncChangesJob')) {
                continue;
            }
            // The job's command is a serialized object holding syncFolderId.
            if (preg_match('/syncFolderId.*?i:(\d+)/s', (string) ($data['data']['command'] ?? ''), $m)) {
                $folderIds[(int) $m[1]] = true;
            }
        }
        if ($folderIds !== []) {
            foreach (SyncFolder::whereIn('id', array_keys($folderIds))->pluck('remote_path') as $path) {
                $items[] = ['kind' => 'folder', 'name' => $path];
            }
        }

        $syncing = $items !== []
            || SyncHistory::where('status', 'running')->exists();

        return response()->json([
            'syncing' => $syncing,
            'pending' => $pending,
            'items' => array_slice($items, 0, 20),
            'count' => count($items),
        ]);
    }
}
