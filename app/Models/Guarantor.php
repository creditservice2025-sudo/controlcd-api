<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guarantor extends Model
{

    use HasFactory, Notifiable, SoftDeletes;
    protected $fillable = [
        'name',
        'dni',
        'address',
        'phone',

    ];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'credits', 'guarantor_id', 'client_id');
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }
}
