<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Credit extends Model
{

    use HasFactory, Notifiable, SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($credit) {
            // Force default to ["Domingo"] if empty, null, or explicitly empty array string
            if (empty($credit->excluded_days) || $credit->excluded_days === '[]' || $credit->excluded_days === 'null') {
                $credit->excluded_days = json_encode(['Domingo']);
            }
        });
    }

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
        // Trazabilidad de creación: quién y con qué rol creó el crédito.
        'created_by',
        'created_by_role',
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

    /**
     * Usuario que creó el crédito (Auth::id() al momento del alta).
     */
    public function createdByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
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

    /**
     * Recalcula remaining_amount y status del crédito desde la verdad
     * objetiva (suma pendiente de installments). Reemplaza el cálculo
     * delta `remaining_amount -= amount` que existía disperso en
     * PaymentService::create / delete / reapplyPayments y que se
     * desincronizaba con cualquier path no contemplado.
     *
     * Llamar después de cualquier operación que cambie installment.paid_amount
     * o installment.status (crear pago, eliminar pago, reaplicar abonos,
     * eliminar movimiento de pago).
     *
     * Reglas:
     *  - remaining_amount = SUM(quota_amount - paid_amount) sobre cuotas no
     *    soft-deleted. Mínimo 0.
     *  - Si TODAS las cuotas están en 'Pagado' Y remaining ≈ 0 → status
     *    pasa a 'Liquidado'. Si estaba 'Liquidado' y aparece deuda otra vez
     *    (ej: alguien eliminó un pago), revierte a 'Vigente'.
     *  - No toca status si está en 'Renovado', 'Cartera Irrecuperable',
     *    'Inactivo' o 'Unificado' — esos son terminales/administrativos.
     */
    public function recalculateRemainingAndStatus(): void
    {
        $remaining = (float) $this->installments()
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(quota_amount - paid_amount), 0) as pending')
            ->value('pending');

        $remaining = max(0, round($remaining, 2));
        $this->remaining_amount = $remaining;

        // Si el status ya es terminal/administrativo, solo actualizamos el
        // monto y salimos. No queremos resucitar un crédito Renovado o
        // moverle el status a uno marcado como Cartera Irrecuperable.
        $terminalStatuses = ['Renovado', 'Cartera Irrecuperable', 'Inactivo', 'Unificado'];
        if (in_array($this->status, $terminalStatuses, true)) {
            $this->save();
            return;
        }

        $hasUnpaid = $this->installments()
            ->where('status', '<>', 'Pagado')
            ->exists();

        if (!$hasUnpaid && $remaining <= 0.001) {
            if ($this->status !== 'Liquidado') {
                $this->status = 'Liquidado';
            }
        } elseif ($this->status === 'Liquidado') {
            // Reverso: aparecieron cuotas pendientes (ej: se eliminó un pago).
            $this->status = 'Vigente';
        }

        $this->save();
    }
}
