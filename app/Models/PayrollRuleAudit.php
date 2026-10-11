<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento del historial de una regla de comisión: created, updated o deleted.
 * `changes` = {campo: {old, new}}; null en las ediciones antiguas sin detalle.
 */
class PayrollRuleAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payroll_rule_id',
        'company_id',
        'user_id',
        'user_name',
        'event',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];
}
