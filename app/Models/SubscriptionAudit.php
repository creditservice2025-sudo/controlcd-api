<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'event',
        'changes',
        'user_id',
        'user_name',
        'user_email',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'subscription_id');
    }
}
