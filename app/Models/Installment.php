<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installment extends Model
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'credit_id',
        'quota_number',
        'due_date',
        'quota_amount',
        'status'
    ];

    public function credit()
    {
        return $this->belongsTo(Credit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentInstallment::class);
    }
}
