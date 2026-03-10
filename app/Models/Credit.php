<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Credit extends Model
{

    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'client_id',
        'phone',
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
        'updated_at',
        'is_advance_payment',
        'unification_reason',
        'renewal_blocked',
        'has_been_modified',
        'modification_count',
        'last_modified_at',
        'last_modified_by',
        'imported_at',
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

    public function images()
    {
        return $this->hasMany(Image::class, 'credit_id');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(Credit::class, 'renewed_from_id');
    }

    public function renewedTo()
    {
        return $this->hasOne(Credit::class, 'renewed_to_id');
    }

    public function paymentsToday()
    {
        return $this->hasMany(Payment::class)
            ->whereDate('payment_date', now()->format('Y-m-d'));
    }
    public function pendingAmount()
    {
        if ($this->total_amount > 0) {
            $totalCredit = $this->total_amount;
        } else {
            $totalCredit = ($this->credit_value ?? 0)
                * (1 + ($this->total_interest ?? 0) / 100);
        }

        $totalPaid = $this->payments()->where('status', '!=', 'Anulado')->sum('amount');
        return max(0, $totalCredit - $totalPaid);
    }
}
