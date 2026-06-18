<?php

namespace App\Models;

use App\Services\LoginService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Liquidation extends Model
{

    use SoftDeletes;

    /**
     * Estados que representan "caja cerrada" (el cobrador ya cerró su día,
     * espera aprobación o ya fue aprobada). Cualquier transición a uno de
     * estos estados dispara la invalidación de sesiones del cobrador en
     * todos sus dispositivos vía la cache key `liquidation_closed:cobrador:{id}`.
     * 'En curso' queda fuera: caja abierta, operando normalmente.
     */
    private const CLOSED_STATUSES = ['pending', 'auto', 'approved'];

    protected static function booted()
    {
        // Observer: cuando una liquidación queda en estado cerrado, marcar
        // la cache key del cobrador. Catch-all que cubre tanto creación
        // directa con status 'pending' (storeLiquidation) como transiciones
        // 'En curso' → 'pending' vía updateLiquidation, approve(), etc.
        //
        // Idempotente: Cache::put sobre la misma key solo extiende el TTL.
        // Fail-safe: cualquier excepción se loguea pero NO interrumpe el
        // save (la liquidación se persiste igual; solo perdemos el lock).
        static::saved(function (self $liquidation) {
            if (!in_array($liquidation->status, self::CLOSED_STATUSES, true)) {
                return;
            }

            try {
                $seller = $liquidation->seller()->with('user')->first();
                $sellerUser = $seller?->user;
                if ($sellerUser && (int) ($sellerUser->role_id ?? 0) === 5) {
                    Cache::put(
                        LoginService::liquidationClosedKey((int) $sellerUser->id),
                        now()->toIso8601String(),
                        now()->addHours(24)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[liquidation.closed] observer failed to apply lock', [
                    'liquidation_id' => $liquidation->id ?? null,
                    'status'         => $liquidation->status ?? null,
                    'error'          => $e->getMessage(),
                ]);
            }
        });
    }

    protected $fillable = [
        'date',
        'seller_id',
        'currency', // ✅ Nuevo: Moneda del país
        'collection_target',
        'initial_cash',
        'base_delivered',
        'total_collected',
        'total_expenses',
        'total_income',
        'new_credits',
        'real_to_deliver',
        'shortage',
        'surplus',
        'cash_delivered',
        'path',
        'status',
        'poliza',
        'end_date',
        'renewal_disbursed_total',
        'total_pending_absorbed',
        'irrecoverable_credits_amount',
        'clients_paid_count',
        'clients_without_credit_count',
        'new_clients_count',
        'active_clients_with_credit_count',
        'clients_liquidated_count',
        'clients_full_payment_count',
        'clients_partial_payment_count',
        'clients_liquidated_and_renewed_count',
        'created_at',
        'observation', // ✅ Nuevo
        'capture_path', // ✅ Nuevo
        // Trazabilidad de quién realizó el cierre (vendedor rol 5 o supervisor rol 6)
        'closed_by',
        'closed_by_role',
        'closed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'collection_target' => 'decimal:2',
        'initial_cash' => 'decimal:2',
        'base_delivered' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'total_income' => 'decimal:2',
        'new_credits' => 'decimal:2',
        'real_to_deliver' => 'decimal:2',
        'shortage' => 'decimal:2',
        'surplus' => 'decimal:2',
        'cash_delivered' => 'decimal:2',
        'clients_paid_count' => 'integer',
        'clients_without_credit_count' => 'integer',
        'new_clients_count' => 'integer',
        'active_clients_with_credit_count' => 'integer',
        'clients_liquidated_count' => 'integer',
        'clients_full_payment_count' => 'integer',
        'clients_partial_payment_count' => 'integer',
        'clients_liquidated_and_renewed_count' => 'integer',
        'end_date' => 'datetime',
        'created_at' => 'datetime',
        'closed_at' => 'datetime',
        'path' => 'string',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    // Relación con el vendedor
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function audits()
    {
        return $this->hasMany(LiquidationAudit::class, 'liquidation_id');
    }

    // Usuario (vendedor o supervisor) que realizó el cierre de la liquidación.
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
