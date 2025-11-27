<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileVersionController extends Controller
{
    public function check(Request $request)
    {
        $request->validate([
            'platform' => 'required|in:android,ios',
            'current_version' => 'required|string',
            'environment' => 'required|in:production,staging,testing,development'
        ]);

        $config = DB::table('app_versions')
            ->where('platform', $request->platform)
            ->where('environment', $request->environment)
            ->first();

        if (!$config) {
            return response()->json([
                'update_required' => false,
                'force_update' => false,
                'latest_version' => $request->current_version,
                'store_url' => null,
                'release_notes' => null
            ]);
        }

        $updateRequired = version_compare($request->current_version, $config->min_version, '<');

        // Si la versión es menor a la mínima, forzar actualización
        // Si la versión es menor a la última pero mayor a la mínima, sugerir actualización (opcional)
        $isLatest = version_compare($request->current_version, $config->latest_version, '>=');

        return response()->json([
            'update_required' => $updateRequired || !$isLatest,
            'force_update' => $updateRequired || $config->force_update,
            'latest_version' => $config->latest_version,
            'min_version' => $config->min_version,
            'store_url' => $config->store_url,
            'release_notes' => $config->release_notes
        ]);
    }
}
