<?php

namespace App\Http\Controllers;

use App\Services\Settings\ConfigService;
use Symfony\Component\HttpFoundation\Response;

/** Config export download (SPEC F5.9). */
class ConfigController extends Controller
{
    public function export(ConfigService $config): Response
    {
        return response($config->toJson(), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="rnv-sync-config.json"',
        ]);
    }
}
