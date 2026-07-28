<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCredit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_credits';

    protected $fillable = [
        'id',
        'company_id',
        'client_id',
        'amount',
        'interest_rate',
        'total_installments',
        'payment_frequency',
        'first_installment_date',
        'status',
        'business_date',
        'metadata',
        'currency',
        'country_code',
        'route_name',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'business_date' => 'date',
        'first_installment_date' => 'date',
        'metadata' => 'array',
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
