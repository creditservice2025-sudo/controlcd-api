<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiquidationAudit extends Model
{
    protected $fillable = [
        'liquidation_id',
        'user_id',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}