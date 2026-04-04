<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCredit extends Model
{
    protected $connection = 'pgsql_collection';
    protected $table = 'collection_credits';

    protected $fillable = [
        'id',
        'company_id',
        'client_id',
        'amount',
        'interest_rate',
        'total_installments',
        'status',
        'business_date'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'business_date' => 'date'
    ];

    public function client()
    {
        return $this->belongsTo(CollectionClient::class, 'client_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(CollectionPayment::class, 'credit_id', 'id');
    }
}
