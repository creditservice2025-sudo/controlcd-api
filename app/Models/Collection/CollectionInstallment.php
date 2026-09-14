<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionInstallment extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_installments';

    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'credit_id',
        'installment_number',
        'due_date',
        'amount',
        'paid_amount',
        'status',
        'payment_method',
        'notes',
        'voucher_path',
        'last_payment_at',
        'recorded_at',
        'deleted_at',
        'deleted_by',
        'deleted_ip',
        'principal_amount',
        'interest_amount',
        // Capital sobre el que se devengó el interés de ESTA cuota. Se escribe al
        // generarla y no se toca después: es la explicación del monto.
        'principal_base',
        'principal_paid',
        'interest_paid',
        'history',
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'principal_base' => 'decimal:2',
        'principal_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'last_payment_at' => 'datetime',
        'recorded_at' => 'datetime',
        'deleted_at' => 'datetime',
        'history' => 'array',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(CollectionPayment::class, 'installment_number', 'installment_number');
    }
}
