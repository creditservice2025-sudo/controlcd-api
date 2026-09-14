<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCredit;
use App\Services\Collection\CollectionCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Devengo del interes mensual: crea la cuota del periodo el DIA DEL CORTE.
 *
 * Por que existe. La cuota de interes de un credito abierto nacia en el momento
 * en que se terminaba de cobrar la anterior. Eso adelantaba el calendario: al
 * cliente que paga antes del vencimiento le aparecia la cuota del mes siguiente
 * semanas antes de que empezara su periodo, y —lo caro— con el interes congelado
 * sobre el capital de ese dia. Si despues abonaba capital, el mes siguiente
 * seguia calculado sobre el saldo viejo.
 *
 * Ahora `generateNextOpenEndedInstallment` no crea nada hasta que llega la fecha
 * de corte, y el interes sale del capital que hay ESE dia. Este comando es quien
 * lo dispara para los creditos que nadie abre ni cobra.
 *
 * No duplica nada: el metodo del servicio se planta solo si el credito no es de
 * interes mensual abierto, si queda alguna cuota abierta, si el capital ya esta
 * saldado o si la fecha de corte todavia no llego. Correrlo de mas es inocuo.
 *
 * Uso:
 *   php artisan collection:accrue-interest --dry-run
 *   php artisan collection:accrue-interest
 *   php artisan collection:accrue-interest --credit=79
 */
class CollectionAccrueInterest extends Command
{
    protected $signature = 'collection:accrue-interest
        {--dry-run : Solo mostrar que creditos devengarian, sin escribir}
        {--credit=* : Limitar a estos IDs de credito}';

    protected $description = 'Genera la cuota de interes del periodo en su fecha de corte (credito abierto)';

    private const CONNECTION = 'collection_pgsql';

    public function handle(CollectionCreditService $creditService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $creditIds = array_filter((array) $this->option('credit'));

        // Se preselecciona en SQL para no recorrer la cartera entera cada hora.
        // El margen de un dia cubre las zonas horarias: quien decide de verdad
        // es el servicio, que compara contra la fecha local del pais del credito.
        $limite = Carbon::now()->addDay()->toDateString();

        $candidatos = CollectionCredit::query()
            ->where('status', 'active')
            ->when($creditIds, fn ($q) => $q->whereIn('id', $creditIds))
            // Sin cuotas abiertas: mientras haya uno pendiente el periodo no avanza.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('collection_installments as i')
                  ->whereColumn('i.credit_id', 'collection_credits.id')
                  ->whereColumn('i.company_id', 'collection_credits.company_id')
                  ->whereNull('i.deleted_at')
                  ->whereIn(DB::raw('LOWER(i.status)'), ['pendiente', 'parcial']);
            })
            // Y con la fecha de corte ya alcanzada (con el margen de zona horaria).
            ->whereExists(function ($q) use ($limite) {
                $q->select(DB::raw(1))
                  ->from('collection_installments as i')
                  ->whereColumn('i.credit_id', 'collection_credits.id')
                  ->whereColumn('i.company_id', 'collection_credits.company_id')
                  ->where('i.due_date', '<=', $limite);
            })
            ->orderBy('id')
            ->get();

        if ($candidatos->isEmpty()) {
            $this->info('No hay creditos por devengar.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[SIMULACION] ' : '') . "Creditos candidatos: {$candidatos->count()}");

        $generadas = 0;
        $filas = [];

        foreach ($candidatos as $credit) {
            $antes = $this->ultimaCuota($credit);

            if ($dryRun) {
                $filas[] = [
                    $credit->id,
                    $antes ? '#' . $antes->installment_number : '-',
                    $antes ? (string) $antes->due_date : '-',
                    'se evaluaria',
                ];
                continue;
            }

            $creditService->generateNextOpenEndedInstallment($credit);

            $despues = $this->ultimaCuota($credit);
            $creo = $despues && (!$antes || $despues->installment_number > $antes->installment_number);

            if ($creo) {
                $generadas++;
                $filas[] = [
                    $credit->id,
                    '#' . $despues->installment_number,
                    (string) $despues->due_date,
                    number_format((float) $despues->interest_amount, 2)
                        . ' sobre capital ' . number_format((float) $despues->principal_base, 2),
                ];
            }
        }

        if ($filas) {
            $this->table(['Credito', 'Cuota', 'Vence', 'Interes'], $filas);
        }

        if ($dryRun) {
            $this->comment('Simulacion: no se escribio nada.');
            return self::SUCCESS;
        }

        $this->info("Listo: {$generadas} cuota(s) de interes generada(s).");

        return self::SUCCESS;
    }

    private function ultimaCuota(CollectionCredit $credit): ?object
    {
        return DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->orderByDesc('installment_number')
            ->first();
    }
}
