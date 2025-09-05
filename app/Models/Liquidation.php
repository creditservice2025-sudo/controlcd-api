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
        'new_credits',
        'real_to_deliver',
        'shortage',
        'surplus',
        'cash_delivered',
        'status',
        'created_at'
    ];

    protected $casts = [
        'date' => 'date',
        'collection_target' => 'decimal:2',
        'initial_cash' => 'decimal:2',
        'base_delivered' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'new_credits' => 'decimal:2',
        'real_to_deliver' => 'decimal:2',
        'shortage' => 'decimal:2',
        'surplus' => 'decimal:2',
        'cash_delivered' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // Relación con el vendedor
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }


}