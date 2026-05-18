<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySubscription extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /** Estados en los que la empresa SÍ puede operar. */
    public const OPERABLE_STATUSES = [
        self::STATUS_TRIAL,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE, // gracia
    ];

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'billing_cycle',
        'amount',
        'currency',
        'start_date',
        'end_date',
        'trial_ends_at',
        'cancelled_at',
        'suspended_at',
        'auto_renew',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'trial_ends_at' => 'date',
        'cancelled_at' => 'datetime',
        'suspended_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'subscription_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(SubscriptionAudit::class, 'subscription_id');
    }

    public function isOperable(): bool
    {
        return in_array($this->status, self::OPERABLE_STATUSES, true);
    }
}
