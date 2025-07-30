<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Seller extends Model
{

    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'user_id',
        'city_id',
        'status'
    ];

    public function userRoutes()
    {
        return $this->hasMany(UserRoute::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function credits()
    {
        return $this->hasMany(Credit::class, 'seller_id');
    }

    public function images()
    {
        return $this->hasMany(Image::class);
    }

    public function expenses() {
        return $this->hasMany(Expense::class);
    }
}
