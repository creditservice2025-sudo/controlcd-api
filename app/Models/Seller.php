<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Seller extends Model
{

    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'city_id',
        'seller_id',
        'company_id',
        'status'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid();
            }
        });
    }

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

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
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

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function config()
    {
        return $this->hasOne(SellerConfig::class);
    }
}
