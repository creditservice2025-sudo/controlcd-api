<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionClient extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_clients';

    protected $fillable = [
        'id',
        'company_id',
        'dni',
        'name',
        'phone',
        'address',
        'country_code',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];
}
