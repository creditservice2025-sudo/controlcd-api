<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guarantor extends Model
{

    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;
    protected $fillable = [
        'name',
        'dni',
        'address',
        'phone',
        'email'
    ];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'credits', 'guarantor_id', 'client_id');
    }
    
}
