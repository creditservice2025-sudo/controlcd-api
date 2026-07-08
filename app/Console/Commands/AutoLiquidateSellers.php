<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutoLiquidateSellers extends Command
{
    protected $signature = 'liquidation:auto-daily {--date= : Fecha específica para liquidar (YYYY-MM-DD)}';
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
            $this->warn("No se pudieron calcular métricas para vendedor {$seller->id} en $date");
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
