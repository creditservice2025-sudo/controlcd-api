<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Route extends Model
{

    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = ['name', 'sector', 'status'];

    public function userRoutes()
    {
        return $this->hasMany(UserRoute::class);
    }
    public function credits()
    {
        return $this->hasMany(Credit::class, 'route_id');
    }

}
