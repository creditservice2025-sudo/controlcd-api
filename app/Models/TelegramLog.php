<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramLog extends Model
{
    protected $fillable = ['company_id', 'chat_id', 'message', 'type', 'status'];
}
