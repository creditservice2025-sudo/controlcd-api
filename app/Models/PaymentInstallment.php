<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class PaymentInstallment extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'payment_id',
        'installment_id',
        'applied_amount',
        'deleted_by'
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the installment that owns the payment installment.
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
