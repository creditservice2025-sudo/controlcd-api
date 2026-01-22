<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Income extends Model
{
    use SoftDeletes;
    protected $table = 'incomes';
    protected $fillable = [
        'value',
        'description',
        'user_id',
        'created_at',
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
    public function images(): HasMany
    {
        return $this->hasMany(IncomeImage::class);
    }
}
