<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomeImage extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'income_id',
        'user_id',
        'path'
    ];

    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
