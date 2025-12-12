<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Image extends Model
{

    use SoftDeletes;

    protected $fillable = [
        'path',
        'type',
        'description',
        'client_id',
        'latitude',
        'longitude',
        'accuracy',
        'address',
        'location_timestamp'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
