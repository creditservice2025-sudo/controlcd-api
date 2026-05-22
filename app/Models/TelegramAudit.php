<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAudit extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
