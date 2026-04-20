<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registro diario independiente (ingreso | gasto | transferencia).
 * No afecta wallet ni ledger: es una bitácora manual paralela al módulo.
 */
class CollectionDailyRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'collection_pgsql';
    protected $table = 'collection_daily_records';

    public const TYPE_INGRESO = 'ingreso';
    public const TYPE_GASTO = 'gasto';
    public const TYPE_TRANSFERENCIA = 'transferencia';

    public const TYPES = [
        self::TYPE_INGRESO,
        self::TYPE_GASTO,
        self::TYPE_TRANSFERENCIA,
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'category',
        'amount',
        'currency',
        'country_code',
        'description',
        'recorded_at',
        'latitude',
        'longitude',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'metadata' => 'json',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
