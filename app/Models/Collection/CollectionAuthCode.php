<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionAuthCode extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_auth_codes';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'request_id',
        'code',
        'action_type',
        'metadata',
        'status',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
