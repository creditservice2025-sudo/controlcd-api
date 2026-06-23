<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'weekly_price',
        'biweekly_price',
        'monthly_price',
        'annual_price',
        'currency',
        'trial_days',
        'grace_days',
        'max_sellers',
        'max_users',
        'max_credits_per_month',
        'max_active_credits',
        'max_clients',
        'features',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'weekly_price' => 'decimal:2',
        'biweekly_price' => 'decimal:2',
        'monthly_price' => 'decimal:2',
        'annual_price' => 'decimal:2',
        'trial_days' => 'integer',
        'grace_days' => 'integer',
        'max_sellers' => 'integer',
        'max_users' => 'integer',
        'max_credits_per_month' => 'integer',
        'max_active_credits' => 'integer',
        'max_clients' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    /**
     * Precio aplicable según el ciclo. Devuelve null si no se ofrece
     * (ej: plan "annual-only" no tiene monthly_price).
     */
    public function priceFor(string $cycle): ?float
    {
        return match ($cycle) {
            'weekly'   => $this->weekly_price !== null ? (float) $this->weekly_price : null,
            'biweekly' => $this->biweekly_price !== null ? (float) $this->biweekly_price : null,
            'monthly'  => $this->monthly_price !== null ? (float) $this->monthly_price : null,
            'annual'   => $this->annual_price !== null ? (float) $this->annual_price : null,
            default    => null,
        };
    }
}
