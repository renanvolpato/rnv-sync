<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneRunner;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Tiny localhost-only status endpoint for the system-tray indicator.
 * No auth/session (the panel binds to 127.0.0.1 and this exposes no
 * secrets) and dirt-cheap so the tray can poll it every couple seconds.
 *
 * It returns a live, OneDrive-style picture of what's moving right now:
 *  - the actual files rclone is transferring this instant, with % and
 *    direction (↑ upload / ↓ download), read from rclone's remote-control
 *    server (core/stats) — files drop off as they finish and the next in
 *    the queue takes their place;
 *  - per-file pin/free operations the user triggered (PendingOps);
 *  - as a fallback (a sync is queued but rclone hasn't started yet) the
 *    folder names, so the menu is never blank mid-sync.
 */
class SyncStateController extends Controller
{
    public function __invoke(SyncService $sync): JsonResponse
    {
        $items = [];
        $transfer = null;

        // 1) Live, per-file rclone transfers — the real upload/download
        //    queue. Present only while a transfer is actually running.
        $stats = $this->liveStats();
        if ($stats !== null) {
            foreach ($stats['transferring'] ?? [] as $t) {
                $dst = (string) ($t['dstFs'] ?? '');
                $items[] = [
                    'kind' => 'file',
                    'name' => basename((string) ($t['name'] ?? '?')),
                    // Writing to a local path (/...) is a download; writing
                    // to a "remote:" destination is an upload.
                    'dir' => ($dst !== '' && ! str_starts_with($dst, '/')) ? 'up' : 'down',
                    'pct' => isset($t['percentage']) ? (int) $t['percentage'] : null,
                ];
            }

            $total = (int) ($stats['totalTransfers'] ?? 0);
            if ($total > 0 || $items !== []) {
                $transfer = [
                    'done' => (int) ($stats['transfers'] ?? 0),
                    'total' => $total,
                    'speed' => (int) ($stats['speed'] ?? 0),
                ];
            }
        }

        // 2) Per-file operations the user triggered (Keep local / online).
        $pending = 0;
        $seen = array_flip(array_column($items, 'name'));
        foreach (PendingOps::all() as $absPath) {
            $pending++;
            $name = basename((string) $absPath);
            if (isset($seen[$name])) {
                continue; // already shown as a live transfer
            }
            $seen[$name] = true;
            $items[] = [
                'kind' => is_dir((string) $absPath) ? 'folder' : 'file',
                'name' => $name,
            ];
        }

        // 3) Fallback: nothing concrete is in flight yet but a folder sync
        //    is queued/running → show the folder names so the menu isn't
        //    empty during the brief "checking" phase before bytes move.
        if ($items === []) {
            $folderIds = [];
            foreach (DB::table('jobs')->pluck('payload') as $payload) {
                $data = json_decode((string) $payload, true);
                if (! str_contains($data['displayName'] ?? '', 'SyncChangesJob')) {
                    continue;
                }
                if (preg_match('/syncFolderId.*?i:(\d+)/s', (string) ($data['data']['command'] ?? ''), $m)) {
                    $folderIds[(int) $m[1]] = true;
                }
            }
            if ($folderIds !== []) {
                foreach (SyncFolder::whereIn('id', array_keys($folderIds))->pluck('remote_path') as $path) {
                    $items[] = ['kind' => 'folder', 'name' => $path];
                }
            }
        }

        $syncing = $items !== []
            || SyncHistory::where('status', 'running')->exists();

        return response()->json([
            'syncing' => $syncing,
            'paused' => $sync->isPaused(),
            'pending' => $pending,
            'items' => array_slice($items, 0, 20),
            'count' => count($items),
            'transfer' => $transfer, // null unless rclone is moving bytes
        ]);
    }

    /**
     * Read rclone's live transfer stats from the rc server advertised by
     * the running transfer, or null when nothing is transferring. Strictly
     * best-effort: a refused connection (idle) or any error → null.
     *
     * @return array<string,mixed>|null
     */
    private function liveStats(): ?array
    {
        $file = RcloneRunner::rcStateFile();
        if (! is_file($file)) {
            return null;
        }

        $state = json_decode((string) @file_get_contents($file), true);
        $port = (int) ($state['port'] ?? 0);
        if ($port <= 0) {
            return null;
        }

        // Guard a stale file from a crashed transfer whose port may have
        // been recycled (max transfer timeout is 3600s).
        if (isset($state['started_at']) && time() - (int) $state['started_at'] > 4000) {
            return null;
        }

        try {
            // rclone's rc server rejects an empty body with 400; send a
            // valid empty JSON object (= "no params, give me global stats").
            $resp = Http::timeout(2)
                ->withBody('{}', 'application/json')
                ->post("http://127.0.0.1:{$port}/core/stats");

            return $resp->ok() ? $resp->json() : null;
        } catch (\Throwable) {
            return null; // idle (connection refused) or transient — no stats
        }
    }
}
