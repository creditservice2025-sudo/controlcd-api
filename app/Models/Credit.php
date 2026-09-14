<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Credit extends Model
{

    use HasFactory, Notifiable, SoftDeletes;
    protected $connection = 'mysql';

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
        // Día/hora de negocio congelados (anclados a la zona del vendedor).
        'business_timestamp',
        'business_date',
        'business_timezone',
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

    /**
     * Saldo REAL por pagar, derivado de la cadena
     * credits -> installments -> payments -> payment_installments.
     *
     * No usa credits.remaining_amount. Auditoria del 2026-08-19 sobre 129.735
     * creditos vivos: la columna declara $291.988.479 de cartera vigente contra
     * $709.060.717 reales. Son 3.254 creditos desincronizados y el patron
     * dominante es remaining_amount = 0 con cuotas vivas y cero pagos (la
     * columna nunca se inicializo), o sea creditos intactos que el sistema
     * muestra como saldados.
     *
     * Tampoco usa total_amount - SUM(pagos): esa via ignora el dinero recibido
     * que quedo sin aplicar a ninguna cuota (unapplied_amount), 988 creditos
     * por $1.530.981.
     *
     * Fuente: cuotas vivas menos el dinero recibido aun sin aplicar. Reconcilia
     * con total_amount - SUM(pagos) dentro de $2.922 sobre $709M (21 creditos
     * de 23.215); el residuo son los creditos donde total_amount no coincide
     * con SUM(quota_amount).
     *
     * Usa las relaciones ya cargadas cuando existen, para no disparar N+1
     * dentro de los loops de reportes.
     */
    public function outstandingAmount(): float
    {
        $deudaCuotas = $this->relationLoaded('installments')
            ? (float) $this->installments->sum(fn ($i) => (float) $i->quota_amount - (float) $i->paid_amount)
            : (float) $this->installments()->sum(\Illuminate\Support\Facades\DB::raw('quota_amount - paid_amount'));

        $sinAplicar = $this->relationLoaded('payments')
            ? (float) $this->payments->sum('unapplied_amount')
            : (float) $this->payments()->sum('unapplied_amount');

        return round(max(0, $deudaCuotas - $sinAplicar), 2);
    }

    /**
     * Valor total del credito segun las cuotas realmente emitidas. Cae a la
     * formula capital + interes solo si no hay cuotas vivas (35 creditos en la
     * auditoria).
     */
    public function totalFromInstallments(): float
    {
        $suma = $this->relationLoaded('installments')
            ? (float) $this->installments->sum('quota_amount')
            : (float) $this->installments()->sum('quota_amount');

        if ($suma > 0) {
            return round($suma, 2);
        }

        $capital = (float) ($this->credit_value ?? 0);

        return round($capital * (1 + ((float) ($this->total_interest ?? 0) / 100)), 2);
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
        $deudaCuotas = (float) $this->installments()
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(quota_amount - paid_amount), 0) as pending')
            ->value('pending');

        // El dinero recibido que todavía no completó ninguna cuota también es
        // deuda saldada: el cliente ya lo pagó. Sin restarlo, un abono que no
        // alcanza a cubrir la cuota entraba a caja pero no bajaba el saldo, y
        // al cliente se le seguía cobrando lo que ya había puesto.
        //
        // Se nota sobre todo en el import: reapplyPayments() no aplica de a
        // pedazos (hace `break` si el stack no cubre la cuota completa), así
        // que un `pagos_realizados` menor a una cuota quedaba entero en
        // unapplied_amount y el crédito persistía el total sin descontarlo.
        //
        // Misma fórmula que Credit::outstandingAmount(), que es la que usan
        // los reportes: así la columna y los reportes no pueden divergir.
        $sinAplicar = (float) $this->payments()
            ->whereNull('deleted_at')
            ->sum('unapplied_amount');

        $remaining = max(0, round($deudaCuotas - $sinAplicar, 2));
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
