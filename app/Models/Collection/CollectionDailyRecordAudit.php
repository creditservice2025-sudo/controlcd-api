<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de ediciones de registros diarios (Collection / Deuda & Abono).
 * Un registro por cada corrección: monto anterior/actual + delta, campos
 * cambiados, observación obligatoria y quién la hizo. Append-only.
 */
class CollectionDailyRecordAudit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_daily_record_audits';

    // Solo created_at (no updated_at): es un log inmutable.
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'daily_record_id',
        'user_id',
        'action',
        'old_amount',
        'new_amount',
        'delta',
        'old_category',
        'new_category',
        'old_description',
        'new_description',
        'old_evidence',
        'new_evidence',
        'extra',
        'observation',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'delta' => 'decimal:2',
        'old_evidence' => 'array',
        'new_evidence' => 'array',
        'extra' => 'array',
        'created_at' => 'datetime',
    ];
}
