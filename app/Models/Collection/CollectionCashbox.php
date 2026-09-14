<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Caja (cuenta) del módulo Collection — contenedor de la bitácora de
 * registros diarios. Multi-caja por empresa: "Caja principal", "Empleados",
 * "Autos", etc. El saldo se DERIVA de collection_daily_records (no se persiste
 * como verdad); `opening_balance` es solo el saldo inicial.
 */
class CollectionCashbox extends Model
{
    use SoftDeletes;

    protected $connection = 'collection_pgsql';
    protected $table = 'collection_cashboxes';

    protected $fillable = [
        'company_id',
        'name',
        'icon',
        'color',
        'currency',
        'country_code',
        'opening_balance',
        'is_default',
        'active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_default' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function records()
    {
        return $this->hasMany(CollectionDailyRecord::class, 'cashbox_id');
    }
}
