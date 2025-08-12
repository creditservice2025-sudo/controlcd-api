<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Payment extends Model
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'credit_id',
        'installment_id',
        'payment_date',
        'amount',
        'status',
        'payment_method',
        'payment_reference',
        'latitude',
        'longitude',
    ];

    public function installment()
    {
        return $this->belongsTo(Installment::class);
    }

    public function credit()
    {
        return $this->belongsTo(Credit::class);
    }


    public function installments(): HasMany
    {
        return $this->hasMany(PaymentInstallment::class);
    }

    public function scopeBeyondDistance(Builder $query, $latitude, $longitude, $distance = 10)
    {
        $earthRadius = 6371000; 

        return $query->whereRaw("
        ST_Distance_Sphere(
            POINT(?, ?),
            POINT(latitude, longitude)
        ) > ?
    ", [$longitude, $latitude, $distance * $earthRadius]);
    }

    public function image(): HasMany
    {
        return $this->hasMany(PaymentImage::class);
    }
}
