<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionLedger extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_ledger';
    public $incrementing = false;
    public $timestamps = false; // We use created_at manually/via DB default

    protected $fillable = [
        'id',
        'company_id',
        'wallet_id',
        'type',
        'action_type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(CollectionWallet::class, 'wallet_id', 'id');
    }
}
