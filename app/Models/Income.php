<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Income extends Model
{
    use SoftDeletes;
    protected $table = 'income';
    protected $fillable = [
        'value',
        'description',
        'user_id',
    ];

    protected $casts = [
        'value' => 'decimal:2'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
