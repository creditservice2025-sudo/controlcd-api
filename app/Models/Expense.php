<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'value',
        'description',
        'user_id',
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
        'business_timestamp' => 'datetime',
        'business_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
