<?php

namespace App\Models;

use App\Exceptions\LiquidationIntegrityException;
use App\Services\LoginService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * Interruptor de los guards de integridad. Solo lo baja la herramienta de
     * reparación sancionada (liquidations:regenerate-day --revert), que es el
     * único flujo autorizado a dar de baja un día ya generado.
     */
    private static bool $integrityGuardsEnabled = true;

    /**
     * Ejecuta $fn con los guards de integridad desactivados. Uso EXCLUSIVO de
     * los comandos de reparación: cualquier otro llamador que necesite esto
     * está, por definición, rompiendo la cadena de caja.
     */
    public static function withoutIntegrityGuards(callable $fn): mixed
    {
        self::$integrityGuardsEnabled = false;

        try {
            return $fn();
        } finally {
            self::$integrityGuardsEnabled = true;
        }
    }

    /**
     * ¿Están puestos los cerrojos? Lo consulta LiquidationService para saber si
     * puede recalcular un día ya aprobado (solo dentro de una reparación
     * sancionada). Un único interruptor para todo el modo reparación.
     */
    public static function integrityGuardsEnabled(): bool
    {
        return self::$integrityGuardsEnabled;
    }

    /**
     * Un día APROBADO está sellado: es la liquidación que el cobrador firmó y
     * contra la que entregó el efectivo. Sus montos no se recalculan más.
     */
    public function isSealed(): bool
    {
        return $this->status === 'approved';
    }

    protected static function booted()
    {
        static::observe(\App\Observers\LiquidationAuditObserver::class);

        // ── Guard 1: no abrir un día DETRÁS de un día ya aprobado ──────────
        //
        // Una liquidación aprobada sella la cadena hasta esa fecha: el saldo
        // inicial de cada día posterior ya se calculó a partir de ella. Meter
        // un día nuevo más atrás deja una caja que nadie va a cerrar (nace
        // 'En curso' y el cron solo mira "hoy") y que bloquea las consultas de
        // todo el rango posterior. Es exactamente lo que pasó con el
        // 2025-11-11 del vendedor 19: quedó abierto bajo 242 días aprobados.
        //
        // Los flujos que lo disparaban sin querer son los de BORRADO de
        // movimientos (IncomeService, CreditService, InstallmentService), que
        // llaman a getOrCreateLiquidation con una fecha pasada "para anclar la
        // auditoría". Ahora fallan ruidosamente en vez de crear el día huérfano.
        static::creating(function (self $liquidation) {
            if (!self::$integrityGuardsEnabled || !$liquidation->seller_id || !$liquidation->date) {
                return;
            }

            $dia = Carbon::parse($liquidation->date)->toDateString();

            $selladaPosterior = static::query()
                ->where('seller_id', $liquidation->seller_id)
                ->whereDate('date', '>', $dia)
                ->where('status', 'approved')
                ->orderBy('date')
                ->first();

            if ($selladaPosterior) {
                throw new LiquidationIntegrityException(
                    "No se puede abrir la caja del {$dia}: el día "
                    . Carbon::parse($selladaPosterior->date)->toDateString()
                    . ' ya fue aprobado y sella la cadena. Para corregir un día pasado se usa'
                    . ' la reapertura de caja o el comando liquidations:regenerate-day.'
                );
            }
        });

        // ── Guard 2: no borrar un día con plata adentro ────────────────────
        //
        // El saldo inicial de un día se toma del último día NO borrado. Si se
        // borra un día que tuvo cobros/gastos/créditos, esos montos no quedan
        // en ninguna caja: desaparecen de la cadena. Así se fueron ~$110.288
        // en 34 días del histórico (el viejo "reabrir caja" hacía delete()).
        static::deleting(function (self $liquidation) {
            if (!self::$integrityGuardsEnabled) {
                return;
            }

            // El borrado en duro se lleva puesta la auditoría del día:
            // liquidation_audits.liquidation_id es ON DELETE CASCADE.
            if (method_exists($liquidation, 'isForceDeleting') && $liquidation->isForceDeleting()) {
                throw new LiquidationIntegrityException(
                    'Una liquidación no se borra en duro: arrastraría su propia auditoría.'
                );
            }

            if ($liquidation->hasMovements()) {
                throw new LiquidationIntegrityException(
                    'No se puede dar de baja la caja del '
                    . Carbon::parse($liquidation->date)->toDateString()
                    . ': tiene movimientos registrados y su importe quedaría fuera de la cadena.'
                    . ' Si hay que reabrirla, se usa la reapertura de caja (conserva la fila).'
                );
            }
        });

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

    /**
     * ¿El día tiene plata adentro?
     *
     * Mira las MISMAS fuentes y con el MISMO criterio de fecha que la fórmula
     * canónica (calculateLiquidationMetrics): business_date para pagos,
     * ingresos y gastos; COALESCE(imported_at, created_at) para créditos. Si se
     * usara otro criterio, un día podría pasar por vacío acá y aportar plata
     * allá —que es justo el tipo de desfase que se está corrigiendo.
     *
     * Se usa para decidir si un día puede darse de baja: si tiene movimientos,
     * borrarlo saca ese importe de la cadena de caja.
     */
    public function hasMovements(): bool
    {
        if (!$this->seller_id || !$this->date) {
            return false;
        }

        $dia = Carbon::parse($this->date)->toDateString();

        $hayCobros = DB::table('payments')
            ->join('credits', 'credits.id', '=', 'payments.credit_id')
            ->where('credits.seller_id', $this->seller_id)
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $dia)
            ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
            ->exists();

        if ($hayCobros) {
            return true;
        }

        $hayCreditos = DB::table('credits')
            ->where('seller_id', $this->seller_id)
            ->whereNull('deleted_at')
            ->whereRaw('DATE(COALESCE(imported_at, created_at)) = ?', [$dia])
            ->exists();

        if ($hayCreditos) {
            return true;
        }

        // Ingresos y gastos cuelgan del USUARIO del vendedor, no del vendedor.
        // withTrashed: un cobrador dado de baja sigue teniendo días con plata.
        $userId = Seller::withTrashed()->whereKey($this->seller_id)->value('user_id');

        if (!$userId) {
            return false;
        }

        foreach (['incomes', 'expenses'] as $tabla) {
            $hay = DB::table($tabla)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->where('business_date', $dia)
                ->exists();

            if ($hay) {
                return true;
            }
        }

        return false;
    }
}
