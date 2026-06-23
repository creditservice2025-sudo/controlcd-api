<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'value',
        'description',
        'user_id',
        'created_by',
        'category_id',
        'status',
        'created_at',
        'latitude',
        'longitude',
        'client_timezone',
        'business_timestamp',
        'business_date',
        'business_timezone',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'created_at' => 'datetime',
        'business_timestamp' => 'string',
        'business_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que registró/realizó el gasto (puede ser un admin operando por
     * cuenta del vendedor). Distinto de user() que es el vendedor dueño.
     * Nombre del método != columna `created_by` para evitar colisión al
     * serializar (se expone como `created_by_user`).
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
    public function images(): HasMany
    {
        return $this->hasMany(ExpenseImage::class);
    }
}
