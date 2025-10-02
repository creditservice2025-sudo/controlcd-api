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
        'renewed_to_id',
        'renewed_from_id',
        'first_quota_date',
        'previous_pending_amount',
        'excluded_days',
        'micro_insurance_percentage',
        'micro_insurance_amount',
        'created_at',
        'unified_to_id',
        'unification_reason',


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
        return $this->hasMany(Installment::class, 'credit_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    public function paymentsToday()
    {
        return $this->hasMany(Payment::class)
            ->whereDate('payment_date', now()->format('Y-m-d'));
    }
    public function pendingAmount()
    {
        $totalCredit = ($this->credit_value ?? 0)
            + ($this->total_interest ?? 0)
            + ($this->micro_insurance_amount ?? 0);

        $totalPaid = $this->payments()->sum('amount');
        return max(0, $totalCredit - $totalPaid);
    }
}
