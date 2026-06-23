<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionClientAudit extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_client_audits';

    protected $fillable = [
        'company_id',
        'client_id',
        'action',
        'user_id',
        'ip_address',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];
}
