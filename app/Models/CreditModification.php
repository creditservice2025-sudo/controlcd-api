<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditModification extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_id',
        'user_id',
        'modification_type',
        'old_value',
        'new_value',
        'affected_installments',
        'notes',
        'ip_address',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'affected_installments' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the credit that was modified
     */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class);
    }

    /**
     * Get the user who made the modification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
