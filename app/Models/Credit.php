<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Credit extends Model
{

    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'client_id',
        'guarantor_id',
        'seller_id',
        'start_date',
        'end_date',
        'credit_value',
        'number_installments',
        'payment_frequency',
        'status',
        'total_interest',
        'total_amount',
        'remaining_amount',
        'first_quota_date',
        'excluded_days',
        'micro_insurance_percentage',
        'micro_insurance_amount',

    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function guarantor()
    {
        return $this->belongsTo(Guarantor::class, 'guarantor_id');
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function installments()
    {
        return $this->hasMany(Installment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
