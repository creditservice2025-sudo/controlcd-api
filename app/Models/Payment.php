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
        // Precisión (metros) y origen ('gps' | 'network') de la ubicación.
        'gps_accuracy',
        'gps_source',
        'client_created_at',
        'client_timezone',
        'business_timestamp',
        'business_date',
        'business_timezone',
        'created_at',
        'updated_at',
        'unapplied_amount',
        // Trazabilidad: quién registró / eliminó el pago y dónde se registró.
        'created_by',
        'deleted_by',
        'address',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'business_date' => 'date:Y-m-d',
        'business_timestamp' => 'string',
        'client_created_at' => 'string',
        'client_timezone' => 'string',
        'business_timezone' => 'string',
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

    /**
     * Usuario que REGISTRÓ el pago. Se setea del lado servidor con Auth::id()
     * al crear (no desde el request, para que no se pueda falsificar).
     */
    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuario que ELIMINÓ el pago (pareja de deleted_at).
     */
    public function deletedByUser()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
