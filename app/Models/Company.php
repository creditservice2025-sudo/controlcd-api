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
        'logo_path',
        'plan_id',
        'status',
        'plan_type',
        'plan_start_date',
        'plan_end_date',
        'whatsapp_verified',
        'last_verification_code',
        'verification_code_expires_at',
    ];

    protected $casts = [
        'whatsapp_verified' => 'boolean',
        'plan_start_date' => 'date',
        'plan_end_date' => 'date',
        'verification_code_expires_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

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
}
