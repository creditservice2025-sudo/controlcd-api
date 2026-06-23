<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CollectionPayment extends Model
{
    use SoftDeletes;
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_payments';

    public $incrementing = true;
    public $timestamps = false;
    const DELETED_AT = 'deleted_at';

    protected $fillable = [
        'company_id',
        'credit_id',
        'installment_number', // For compatibility with existing migration
        'amount_paid',
        'payment_date',
        'recorded_at',
        'receipt_number',
        'payment_method',
        'notes',
        'interest_paid',
        'principal_paid',
        'voucher_path',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'principal_paid' => 'decimal:2',
        'payment_date' => 'date',
        'recorded_at' => 'datetime',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }

    public function client()
    {
        return $this->hasOneThrough(
            CollectionClient::class,
            CollectionCredit::class,
            'id', // Foreign key on credits table... (credits.id)
            'id', // Foreign key on clients table... (clients.id)
            'credit_id', // Local key on payments table... (payments.credit_id)
            'client_id'  // Local key on credits table... (credits.client_id)
        );
    }
}
