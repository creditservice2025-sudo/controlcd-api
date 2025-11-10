<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Payment extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

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
        'created_at',
        'updated_at'
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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



    public function image()
    {
        return $this->hasOne(PaymentImage::class, 'payment_id');
    }
}
