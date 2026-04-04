<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionClient extends Model
{
    protected $connection = 'pgsql_collection';
    protected $table = 'collection_clients';

    protected $fillable = [
        'id',
        'company_id',
        'dni',
        'name',
        'phone',
        'address',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];
}
