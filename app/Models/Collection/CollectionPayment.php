<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionPayment extends Model
{
    protected $connection = 'pgsql_collection';
    protected $table = 'collection_payments';

    protected $fillable = [
        'id',
        'company_id',
        'credit_id',
        'installment_number',
        'amount_paid',
        'payment_date',
        'recorded_at',
        'receipt_number'
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'payment_date' => 'date',
        'recorded_at' => 'datetime'
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }
}
