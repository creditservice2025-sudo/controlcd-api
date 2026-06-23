<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCapitalAddition extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_capital_additions';

    protected $fillable = [
        'id',
        'company_id',
        'credit_id',
        'amount',
        'business_date',
        'payment_method',
        'reference_number',
        'bank_name',
        'voucher_photo',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'business_date' => 'date',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }
}
