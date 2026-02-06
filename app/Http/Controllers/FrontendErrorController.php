<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FrontendErrorController extends Controller
{
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user ? $user->id : 'guest';
            
            $logData = [
                'user_id' => $userId,
                'url' => $request->input('url'),
                'message' => $request->input('message'),
                'stack' => $request->input('stack'),
                'component' => $request->input('component'),
                'additional_info' => $request->input('additional_info'),
                'user_agent' => $request->header('User-Agent'),
                'ip' => $request->ip(),
            ];

            Log::channel('daily')->error('FRONTEND_ERROR: ' . json_encode($logData));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            // Fail silently to avoid infinite loops
            return response()->json(['success' => false], 500);
        }
    }
}
