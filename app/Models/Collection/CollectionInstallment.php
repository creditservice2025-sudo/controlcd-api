<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionInstallment extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_installments';

    // IDs are handled manually in this partitioned architecture
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
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
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'last_payment_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(CollectionPayment::class, 'installment_number', 'installment_number')
            ->where('credit_id', $this->credit_id)
            ->where('company_id', $this->company_id);
    }
}
