<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CollectionCapitalAddition extends Model
{
    // SoftDeletes va aca a proposito, y no un ->whereNull('deleted_at') en cada
    // consulta: las adiciones se leen desde seis servicios (cierre de caja,
    // dashboard, registros diarios, clientes, creditos). El scope global las
    // excluye en todos de una vez; olvidarse de uno solo desbalancearia la caja.
    // El unico que las quiere ver es el historial, y ahi se pide withTrashed().
    use SoftDeletes;

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
        'deleted_by',
        'deletion_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'business_date' => 'date',
        'deleted_at' => 'datetime',
    ];

    public function credit()
    {
        return $this->belongsTo(CollectionCredit::class, 'credit_id', 'id');
    }
}
