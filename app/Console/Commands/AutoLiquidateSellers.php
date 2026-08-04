<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutoLiquidateSellers extends Command
{
    protected $signature = 'liquidation:auto-daily
        {--date= : Fecha específica para liquidar (YYYY-MM-DD)}
        {--no-sweep : Omite el barrido de días pasados que quedaron abiertos}
        {--sweep-limit=100 : Máximo de días atrasados a cerrar por corrida}';
    protected $description = 'Genera liquidación diaria automática para todos los vendedores si no existe o está en curso';

    public function handle()
    {
        $sellers = Seller::whereHas('config', function ($q) {
            $q->where('auto_closures_collectors', true);
        })->get();
        $count = 0;
        $dateParam = $this->option('date');

        foreach ($sellers as $seller) {
            try {
                $businessTimezone = \App\Helpers\TimezoneHelper::getSellerTimezone($seller);
                $now = Carbon::now($businessTimezone);
                
                // Determinamos la fecha de cierre objetivo.
                $targetDate = $dateParam;

                // Cierre SOLO del día en curso (hoy), en la ventana 23:55-23:59 de
                // la zona horaria del país del vendedor. Se quitó la ventana
                // 00:00-00:29 que cerraba AYER: el cierre es del mismo día, nunca
                // retroactivo al día siguiente.
                if (!$targetDate) {
                    if ($now->hour == 23 && $now->minute >= 55) {
                        $targetDate = $now->toDateString();
                    }
                }

                // Si no estamos en ventana de cierre y no se pasó fecha manual, saltamos
                if (!$targetDate) {
                    continue;
                }

                // Verifica si ya existe liquidación para esa fecha objetivo
                $existingLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $targetDate)
                    ->first();

                // Día no laborable de la ruta (descanso semanal / feriado): no se
                // ABRE un día que la ruta no opera — sigue evitando las 'auto'
                // espurias de domingo/feriado.
                //
                // Pero si la fila YA existe (la creó un movimiento real, una
                // reapertura de caja o un cierre del admin) hay que cerrarla
                // igual. Saltearla sin mirar era exactamente lo que la dejaba
                // 'En curso' para siempre: el cron no la tocaba y el vigilante
                // tampoco la reportaba.
                if (!$existingLiquidation && \App\Services\BusinessCalendar::isNonWorkingDate($seller, $targetDate)) {
                    continue;
                }

                // Si NO existe, o si existe pero está "En curso", la procesamos
                if (!$existingLiquidation || $existingLiquidation->status === 'En curso') {
                    $this->processLiquidation($seller, $targetDate, $businessTimezone, $existingLiquidation);
                    $count++;
                }

                // Cierre automático de sesiones abiertas si estamos liquidando el día
                $this->closeOpenSessions($seller, $now, $businessTimezone);

            } catch (\Exception $e) {
                \Log::error("Error auto-liquidating seller {$seller->id}: " . $e->getMessage());
                $this->error("Error con vendedor {$seller->id}: " . $e->getMessage());
            }
        }

        $this->info("Proceso completado. {$count} liquidaciones procesadas.");

        if (!$this->option('no-sweep')) {
            $swept = $this->sweepStaleOpenDays((int) $this->option('sweep-limit'));
            if ($swept > 0) {
                $this->info("Barrido: {$swept} día(s) atrasado(s) cerrado(s).");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Barrido de fin de día: ninguna caja sobrevive a su propia fecha.
     *
     * El cierre de la ventana 23:55-23:59 de arriba solo alcanza a los vendedores
     * con auto_closures_collectors = true y solo dentro de esos 5 minutos. Todo lo
     * que se cae de ahí quedaba 'En curso' indefinidamente:
     *   - las rutas sin auto-cierre configurado (nadie las cerraba nunca);
     *   - los días no laborables cuya fila ya existía;
     *   - una corrida que se pasó de la ventana o murió a mitad de camino.
     *
     * Este barrido cierra CUALQUIER liquidación 'En curso' cuya fecha ya quedó
     * atrás en la zona del propio vendedor. Es idempotente y corre a cualquier
     * hora: si una corrida falla, la del minuto siguiente lo resuelve.
     *
     * NO toca el día en curso ni fechas futuras, así que una caja reabierta hoy
     * sigue operable (la reapertura solo se permite dentro del mismo día).
     */
    private function sweepStaleOpenDays(int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        // withTrashed: 97 de los días abiertos pertenecen a vendedores dados de
        // baja. La relación normal los devuelve null y el barrido los saltearía
        // en silencio — justo los que más tiempo llevan colgados. Un cobrador que
        // ya no está no es motivo para dejar su caja abierta.
        $openDays = Liquidation::where('status', 'En curso')
            ->with(['seller' => fn ($q) => $q->withTrashed()->with('city.country')])
            ->orderBy('date')
            ->get();

        $closed = 0;
        $pending = 0;

        foreach ($openDays as $liquidation) {
            $seller = $liquidation->seller;
            if (!$seller) {
                continue;
            }

            $timezone = \App\Helpers\TimezoneHelper::getSellerTimezone($seller);
            $today = Carbon::now($timezone)->toDateString();
            $liquidationDate = Carbon::parse($liquidation->date)->toDateString();

            // El día de hoy (y cualquier futuro) se respeta: la caja está abierta
            // a propósito.
            if ($liquidationDate >= $today) {
                continue;
            }

            if ($closed >= $limit) {
                $pending++;
                continue;
            }

            try {
                $this->processLiquidation($seller, $liquidationDate, $timezone, $liquidation);
                $closed++;
            } catch (\Throwable $e) {
                \Log::error('[liquidation.sweep] no se pudo cerrar un día atrasado', [
                    'liquidation_id' => $liquidation->id,
                    'seller_id'      => $seller->id,
                    'date'           => $liquidationDate,
                    'error'          => $e->getMessage(),
                ]);
                $this->error("Barrido: falló el cierre de liq #{$liquidation->id} ({$liquidationDate}).");
            }
        }

        // Nada de topes silenciosos: si quedaron días sin cerrar por el límite,
        // se dice. La próxima corrida sigue donde quedó ésta.
        if ($pending > 0) {
            $this->warn("Barrido: quedaron {$pending} día(s) atrasado(s) sin cerrar por el límite de {$limit}. La próxima corrida continúa.");
            \Log::warning('[liquidation.sweep] barrido truncado por límite', [
                'limite'    => $limit,
                'cerrados'  => $closed,
                'restantes' => $pending,
            ]);
        }

        return $closed;
    }

    private function processLiquidation($seller, $date, $timezone, $existingLiquidation = null)
    {
        $liquidationService = app(\App\Services\LiquidationService::class);
        
        // Si no existe, la creamos primero como borrador (Apertura rápida)
        if (!$existingLiquidation) {
            $existingLiquidation = $liquidationService->getOrCreateLiquidation($seller->id, $date, $timezone);
        }

        // Usamos el servicio para calcular las métricas finales correctamente
        $metrics = $liquidationService->calculateLiquidationMetrics($seller->id, $date, null, $timezone);

        if (empty($metrics)) {
            // Salida silenciosa: la fila se queda 'En curso' y nadie se entera.
            // Se loguea con contexto para que el barrido y el vigilante tengan
            // de dónde agarrarse cuando un día no cierra.
            $this->warn("No se pudieron calcular métricas para vendedor {$seller->id} en $date");
            \Log::warning('[liquidation.auto-daily] métricas vacías, el día queda abierto', [
                'seller_id' => $seller->id,
                'date'      => $date,
                'timezone'  => $timezone,
            ]);
            return;
        }

        // Actualizamos el registro con los datos finales y cambiamos status a 'auto'
        $existingLiquidation->update([
            'status' => 'auto',
            'initial_cash' => $metrics['initial_cash'] ?? 0,
            'total_collected' => $metrics['total_collected'] ?? 0,
            'total_expenses' => $metrics['total_expenses'] ?? 0,
            'total_income' => $metrics['total_income'] ?? 0,
            'new_credits' => $metrics['new_credits'] ?? 0,
            'real_to_deliver' => $metrics['real_to_deliver'] ?? 0,
            'poliza' => $metrics['poliza'] ?? 0,
            'renewal_disbursed_total' => $metrics['renewal_disbursed_total'] ?? 0,
            'total_pending_absorbed' => $metrics['total_pending_absorbed'] ?? 0,
            'irrecoverable_credits_amount' => $metrics['irrecoverable_credits_amount'] ?? 0,
            'shortage' => $metrics['shortage'] ?? 0,
            'surplus' => $metrics['surplus'] ?? 0,
            'cash_delivered' => 0, // En auto-cierre asumimos que no hay entrega física aún
        ]);

        $this->info("Liquidación automática cerrada para vendedor {$seller->id} Fecha: {$date}");
    }

    private function closeOpenSessions($seller, $now, $timezone)
    {
        // Buscamos sesiones abiertas (sin logout) para el usuario de este vendedor
        $openSessions = \App\Models\SessionLog::where('user_id', $seller->user_id)
            ->whereNull('logout_at')
            ->get();

        foreach ($openSessions as $session) {
            // Si la sesión empezó antes de "ahora", la cerramos
            // Usamos la hora actual o las 23:59:59 del día de la liquidación si es pasado
            $session->update([
                'logout_at' => $now->toDateTimeString()
            ]);
            \Log::info("Sesión #{$session->id} cerrada automáticamente para el vendedor {$seller->id}");
        }
    }
}
