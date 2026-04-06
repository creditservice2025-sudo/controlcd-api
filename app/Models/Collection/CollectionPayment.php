<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionPayment extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_payments';

    // IDs are manual bigint in this project
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'company_id',
        'credit_id',
        'installment_number', // For compatibility with existing migration
        'amount_paid',
        'payment_date',
        'recorded_at',
        'receipt_number',
        'payment_method',
        'notes',
        'voucher_path',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'payment_date' => 'date',
        'recorded_at' => 'datetime',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }
}
