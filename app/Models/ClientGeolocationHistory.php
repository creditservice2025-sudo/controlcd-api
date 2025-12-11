<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGeolocationHistory extends Model
{
    protected $fillable = [
        'client_id',
        'latitude',
        'longitude',
        'accuracy',
        'address',
        'action_type',
        'action_id',
        'description',
        'recorded_at',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
