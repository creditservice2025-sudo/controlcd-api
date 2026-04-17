<?php

namespace App\Http\Controllers;

use App\Models\TelegramLog;
use Illuminate\Http\Request;

class TelegramLogController extends Controller
{
    public function index()
    {
        if (auth()->user()->role_id !== 1) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return TelegramLog::orderBy('created_at', 'desc')->paginate(20);
    }
}
