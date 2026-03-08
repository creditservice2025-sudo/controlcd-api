<?php

namespace App\Http\Controllers;

use App\Models\TelegramLog;
use Illuminate\Http\Request;

class TelegramLogController extends Controller
{
    public function index()
    {
        return TelegramLog::orderBy('created_at', 'desc')->paginate(20);
    }
}
