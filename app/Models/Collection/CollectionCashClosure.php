<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * Cierre de caja diario del modulo Collection.
 */
class CollectionCashClosure extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_cash_closures';

    public const STATUS_CLOSED = 'closed';
    public const STATUS_REOPENED = 'reopened';
    public const STATUS_AUTO_PENDING = 'auto_pending';

    protected $fillable = [
        'id',
        'company_id',
        'closure_date',
        'country_code',
        'currency',
        'user_id',
        'total_cobros',
        'total_adiciones',
        'total_ingresos_manuales',
        'total_gastos',
        'total_egresos_manuales',
        'total_transferencias',
        'esperado',
        'efectivo_contado',
        'transferencias_recibidas',
        'total_declarado',
        'faltante_sobrante',
        'notas',
        'status',
        'closed_at',
        'reopened_at',
        'reopened_by',
        'validated_by',
        'validated_at',
    ];

    protected $casts = [
        'closure_date' => 'date',
        'total_cobros' => 'decimal:2',
        'total_adiciones' => 'decimal:2',
        'total_ingresos_manuales' => 'decimal:2',
        'total_gastos' => 'decimal:2',
        'total_egresos_manuales' => 'decimal:2',
        'total_transferencias' => 'decimal:2',
        'esperado' => 'decimal:2',
        'efectivo_contado' => 'decimal:2',
        'transferencias_recibidas' => 'decimal:2',
        'total_declarado' => 'decimal:2',
        'faltante_sobrante' => 'decimal:2',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
