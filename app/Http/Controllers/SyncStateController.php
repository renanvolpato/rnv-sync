<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncHistory;
use App\Services\Files\PendingOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Tiny localhost-only status endpoint for the system-tray indicator.
 * No auth/session (the panel binds to 127.0.0.1 and this exposes no
 * secrets) and dirt-cheap so the tray can poll it every few seconds.
 */
class SyncStateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $pending = count(PendingOps::all());

        $syncing = $pending > 0
            || SyncHistory::where('status', 'running')->exists()
            || DB::table('jobs')->count() > 0;

        return response()->json([
            'syncing' => $syncing,
            'pending' => $pending,
        ]);
    }
}
