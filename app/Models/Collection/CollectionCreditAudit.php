<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCreditAudit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_credit_audits';

    protected $fillable = [
        'company_id',
        'credit_id',
        'action',
        'user_id',
        'ip_address',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];
}
