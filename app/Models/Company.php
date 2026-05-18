<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;


class Company extends Model
{

    use HasFactory, Notifiable, SoftDeletes;
    protected $fillable = [
        'user_id',
        'code',
        'ruc',
        'name',
        'phone',
        'email',
        'logo_path'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sellers()
    {
        return $this->hasMany(Seller::class);
    }

    public function credits()
    {
        return $this->hasManyThrough(Credit::class, Seller::class);
    }

    /**
     * Todas las suscripciones de la empresa (histórico). Usar
     * activeSubscription() para la actual.
     */
    public function subscriptions()
    {
        return $this->hasMany(CompanySubscription::class);
    }

    /**
     * Suscripción "vigente" (trial, active, past_due o suspended).
     * Suspendida sigue siendo "actual" pero la empresa no puede operar.
     * Cancelled/expired NO entran: la empresa quedó sin plan.
     */
    public function activeSubscription()
    {
        return $this->hasOne(CompanySubscription::class)
            ->whereIn('status', [
                CompanySubscription::STATUS_TRIAL,
                CompanySubscription::STATUS_ACTIVE,
                CompanySubscription::STATUS_PAST_DUE,
                CompanySubscription::STATUS_SUSPENDED,
            ])
            ->latestOfMany();
    }
}
