<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'credit_id',
        'installment_id',
        'payment_date',
        'amount',
        'status',
        'payment_method',
        'payment_reference'
    ];

    public function installment()
    {
        return $this->belongsTo(Installment::class);
    }

    public function credit()
    {
        return $this->belongsTo(Credit::class);
    }


    public function installments(): HasMany
    {
        return $this->hasMany(PaymentInstallment::class);
    }
}
