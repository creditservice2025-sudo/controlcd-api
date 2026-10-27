<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento del historial de una meta de recaudo: created, updated o deleted.
 * `changes` = {campo: {old, new}}.
 */
class SellerCollectionScaleAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scale_id',
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
