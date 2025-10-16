<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Liquidation extends Model
{
    protected $fillable = [
        'date',
        'seller_id',
        'collection_target',
        'initial_cash',
        'base_delivered',
        'total_collected',
        'total_expenses',
        'total_income',
        'new_credits',
        'real_to_deliver',
        'shortage',
        'surplus',
        'cash_delivered',
        'path',
        'status',
        'end_date',
        'renewal_disbursed_total',
        'total_pending_absorbed',
        'irrecoverable_credits_amount',
        'created_at'
    ];

    protected $casts = [
        'date' => 'date',
        'collection_target' => 'decimal:2',
        'initial_cash' => 'decimal:2',
        'base_delivered' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'total_income' => 'decimal:2',
        'new_credits' => 'decimal:2',
        'real_to_deliver' => 'decimal:2',
        'shortage' => 'decimal:2',
        'surplus' => 'decimal:2',
        'cash_delivered' => 'decimal:2',
        'end_date' => 'datetime',
        'created_at' => 'datetime',
        'path' => 'string',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    // Relación con el vendedor
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function audits()
{
    return $this->hasMany(LiquidationAudit::class, 'liquidation_id');
}


}