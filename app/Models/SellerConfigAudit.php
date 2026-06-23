<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerConfigAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'seller_config_id',
        'seller_id',
        'user_id',
        'user_name',
        'user_email',
        'event',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sellerConfig()
    {
        return $this->belongsTo(SellerConfig::class);
    }
}
