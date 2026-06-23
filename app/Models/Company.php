<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;


class Company extends Model
{
    protected $connection = 'mysql';

    use HasFactory, Notifiable, SoftDeletes;
    protected $fillable = [
        'user_id',
        'code',
        'ruc',
        'name',
        'phone',
        'email',
        'logo_path',
        'telegram_feature_enabled',
        'telegram_enabled',
        'telegram_chat_id',
        'telegram_link_token',
        'telegram_link_expires_at',
        'telegram_notify_new_client',
        'telegram_notify_new_credit',
        'telegram_notify_new_expense',
        'telegram_notify_deleted_expense',
        'telegram_notify_deleted_credit',
    ];

    protected $casts = [
        'telegram_feature_enabled' => 'boolean',
        'telegram_enabled' => 'boolean',
        'telegram_notify_new_client' => 'boolean',
        'telegram_notify_new_credit' => 'boolean',
        'telegram_notify_new_expense' => 'boolean',
        'telegram_notify_deleted_expense' => 'boolean',
        'telegram_notify_deleted_credit' => 'boolean',
        'telegram_link_expires_at' => 'datetime',
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
