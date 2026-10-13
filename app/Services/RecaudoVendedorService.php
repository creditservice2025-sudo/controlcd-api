<?php

namespace App\Services;

use App\Helpers\TimezoneHelper;
use App\Models\Seller;
use App\Models\SellerCollectionScale;
use App\Support\FormatoMoneda;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recaudo de un vendedor y su avance hacia la meta (barra del cobrador).
 *
 * CRITERIO ÚNICO del recaudo de un día: el MISMO con el que la liquidación
 * calcula su total_collected (LiquidationService::getDailyTotals):
 *   pagos no eliminados, de créditos del vendedor, por business_date (fecha
 *   de negocio en la zona del vendedor; nunca created_at ni UTC).
 * No filtra por estado porque la liquidación tampoco lo hace; los pagos
 * "No pagado" tienen siempre monto 0 (medido), así que no suman.
 *
 * Días CERRADOS (liquidación aprobada) usan el total_collected de la
 * liquidación: es el número oficial. Días ABIERTOS suman los pagos con el
 * criterio de arriba y se marcan como parciales.
 *
 * El tono y la proyección NO se calculan acá: salen de
 * CollectionScaleService::evaluate, el mismo que pinta el Resumen General.
 * Así la barra del cobrador y el reporte del administrador no pueden decir
 * cosas distintas para el mismo rango.
 *
 * Solo LEE: no modifica pagos, liquidaciones ni cierres.
 */
class RecaudoVendedorService
{
    /** Segundos que se reutiliza el avance; se invalida al registrar/anular un pago. */
    public const CACHE_TTL = 60;

    public function __construct(private CollectionScaleService $scales)
    {
    }

    public static function cacheKey(int $sellerId): string
    {
        return "progreso_recaudo:{$sellerId}";
    }

    // ---- Recaudo ------------------------------------------------------------

    /**
     * Pagos del vendedor por fecha de negocio, con el criterio de la
     * liquidación.
     *
     * @return array<string, float> 'Y-m-d' => monto
     */
    public function recaudoPagosPorDia(int $sellerId, string $desde, string $hasta): array
    {
        return DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->whereNull('payments.deleted_at')
            ->where('credits.seller_id', $sellerId)
            ->whereBetween('payments.business_date', [$desde, $hasta])
            ->groupBy('payments.business_date')
            ->select('payments.business_date', DB::raw('SUM(payments.amount) as total'))
            ->pluck('total', 'business_date')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /** Recaudo de UN día con el criterio de la liquidación. */
    public function recaudoPagosDelDia(int $sellerId, string $fecha): float
    {
        return $this->recaudoPagosPorDia($sellerId, $fecha, $fecha)[$fecha] ?? 0.0;
    }

    /**
     * Recaudo de un rango: días cerrados con la liquidación aprobada, días
     * abiertos con los pagos.
     *
     * @return array{total: float, dias_trabajados: int, parcial: bool}
     */
    public function recaudoDelPeriodo(int $sellerId, string $desde, string $hasta): array
    {
        $aprobadas = DB::table('liquidations')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->where('date', '>=', $desde)
            ->where('date', '<', Carbon::parse($hasta)->addDay()->format('Y-m-d'))
            ->get(['date', 'total_collected'])
            ->mapWithKeys(fn ($l) => [substr((string) $l->date, 0, 10) => (float) $l->total_collected])
            ->all();

        $pagos = $this->recaudoPagosPorDia($sellerId, $desde, $hasta);

        $total = 0.0;
        $dias = 0;
        $parcial = false;
        $d = Carbon::parse($desde);
        $fin = Carbon::parse($hasta);
        while ($d->lte($fin)) {
            $f = $d->format('Y-m-d');
            if (array_key_exists($f, $aprobadas)) {
                $total += $aprobadas[$f];
                $dias++;
            } elseif (($pagos[$f] ?? 0) > 0) {
                // Día todavía sin liquidación aprobada: el número puede
                // cambiar hasta el cierre.
                $total += $pagos[$f];
                $dias++;
                $parcial = true;
            }
            $d->addDay();
        }

        return ['total' => round($total, 2), 'dias_trabajados' => $dias, 'parcial' => $parcial];
    }

    // ---- Avance del vendedor ------------------------------------------------

    /**
     * Avance del vendedor hacia su meta en el período EN CURSO (hoy, esta
     * semana o este mes, según el tipo de su meta).
     *
     * El interruptor de la empresa se valida SIEMPRE, fuera de la caché: si el
     * Super-Admin lo apaga, deja de verse en la siguiente consulta.
     */
    public function progreso(Seller $seller): array
    {
        // Se lee de la base en cada consulta (no de una relación ya cargada):
        // si el Super-Admin lo apaga, deja de verse en ese mismo momento.
        $habilitado = (bool) DB::table('companies')->where('id', $seller->company_id)->value('show_collection_progress');
        if (!$habilitado) {
            return ['available' => false, 'reason' => 'disabled'];
        }

        return Cache::remember(self::cacheKey($seller->id), self::CACHE_TTL, fn () => $this->calcularProgreso($seller));
    }

    private function calcularProgreso(Seller $seller): array
    {
        $seller->loadMissing('city.country');
        $currency = strtoupper((string) optional(optional($seller->city)->country)->currency);

        $scale = $this->scales->scaleForSeller(
            $this->scales->scalesFor([$seller->company_id]),
            $seller->id,
            $seller->company_id,
            $currency
        );

        if (!$scale) {
            return ['available' => false, 'reason' => 'no_goal'];
        }
        // "Total del período consultado" no tiene un período natural (depende
        // del rango que elija quien mira el reporte): no hay barra que mostrar.
        if ($scale->period_type === 'range') {
            return ['available' => false, 'reason' => 'unsupported_type'];
        }

        $tz = TimezoneHelper::getSellerTimezone($seller);
        $hoy = Carbon::now($tz)->startOfDay();
        [$desde, $hasta] = $this->periodoEnCurso($scale->period_type, $hoy);

        $transcurridos = $desde->diffInDays($hoy) + 1;
        $totales = $desde->diffInDays($hasta) + 1;

        $r = $this->recaudoDelPeriodo($seller->id, $desde->format('Y-m-d'), $hoy->format('Y-m-d'));

        // El MISMO evaluate que el Resumen General con el rango inicio→hoy:
        // diario = promedio por día trabajado; semanal/mensual = ritmo, que
        // es la proyección de cierre del período.
        $eval = $this->scales->evaluate($scale, $r['total'], $r['dias_trabajados'], $transcurridos);

        return $this->armarRespuesta($scale, $r, $eval, [
            'type' => $scale->period_type,
            'label' => CollectionScaleService::PERIOD_LABELS[$scale->period_type],
            'from' => $desde->format('Y-m-d'),
            'to' => $hasta->format('Y-m-d'),
            'today' => $hoy->format('Y-m-d'),
            'elapsed_days' => $transcurridos,
            'total_days' => $totales,
        ]);
    }

    /**
     * Inicio y fin del período en curso. La semana empieza el lunes (ISO),
     * como en el resto del sistema.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodoEnCurso(string $tipo, Carbon $hoy): array
    {
        return match ($tipo) {
            'weekly' => [$hoy->copy()->startOfWeek(Carbon::MONDAY), $hoy->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            'monthly' => [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()->startOfDay()],
            default => [$hoy->copy(), $hoy->copy()],
        };
    }

    private function armarRespuesta(SellerCollectionScale $scale, array $r, array $eval, array $periodo): array
    {
        $summary = $this->scales->summary($scale);
        $recaudado = $r['total'];
        $cortes = ['rojo' => $scale->cut_red, 'amarillo' => $scale->cut_yellow, 'azul' => $scale->cut_blue];

        // Siguiente rango a superar con lo recaudado HASTA AHORA.
        $siguiente = null;
        foreach ($cortes as $tono => $corte) {
            if ($recaudado <= $corte) {
                $siguiente = [
                    'tono' => $tono,
                    'corte' => $corte,
                    'falta' => round($corte - $recaudado, 2),
                ];
                break;
            }
        }

        $m = fn ($n) => FormatoMoneda::formatear($n, $scale->currency);

        return [
            'available' => true,
            'currency' => $scale->currency,
            'currency_symbol' => FormatoMoneda::simbolo($scale->currency),
            'currency_decimals' => FormatoMoneda::decimales($scale->currency),
            'scope' => $summary['scope'],
            'period' => $periodo,
            'collected' => $recaudado,
            'collected_label' => $m($recaudado),
            'worked_days' => $r['dias_trabajados'],
            // Hay días todavía sin liquidación aprobada: puede cambiar al cierre.
            'partial' => $r['parcial'],
            // Tono de lo recaudado hasta ahora contra los cortes de la meta.
            'current_tone' => $this->scales->toneFor($scale, $recaudado),
            // Lo que compara el Resumen General: promedio diario o ritmo de
            // cierre (proyección) del período. Mismo número que el reporte.
            'projected' => $eval['value'],
            'projected_label' => $m($eval['value']),
            'projected_tone' => $eval['tone'],
            'cuts' => $cortes,
            'next' => $siguiente ? $siguiente + ['falta_label' => $m($siguiente['falta'])] : null,
            'goal' => [
                'corte' => $scale->cut_blue,
                'corte_label' => $m($scale->cut_blue),
                'falta' => round(max(0, $scale->cut_blue - $recaudado), 2),
                'falta_label' => $m(max(0, $scale->cut_blue - $recaudado)),
                'alcanzada' => $recaudado > $scale->cut_blue,
            ],
            'palette' => $summary['palette'],
            'legend' => $summary['legend'],
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
