<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el estado de las cuotas por COMPONENTES.
 *
 * Por que existe. El motor de pagos decidia el estado con un ternario:
 * saldada -> 'pagado', cualquier otra cosa -> 'parcial'. Nunca devolvia
 * 'pendiente'. En el credito de interes mensual la cuota lleva solo interes
 * (principal_amount = 0) pero el abono a capital se imputa igual a ella, asi
 * que cualquier abono a capital dejaba la cuota marcada PARCIAL con el interes
 * intacto: la pantalla mostraba ese rotulo al lado de "Falta $1.108,30 de
 * interes", que era el interes entero.
 *
 * La regla correcta, ya aplicada en CollectionPaymentService:
 *   - pagado    : interes saldado Y capital DE LA CUOTA saldado
 *   - parcial   : se cobro algo de lo que la cuota debe, pero no todo
 *   - pendiente : no se cobro nada de lo que la cuota debe
 *
 * Del capital abonado solo cuenta lo que corresponde a la cuota: el resto baja
 * el credito, no la cuota.
 *
 * Uso:
 *   php artisan collection:fix-installment-status --dry-run
 *   php artisan collection:fix-installment-status
 *   php artisan collection:fix-installment-status --credit=11
 */
class CollectionFixInstallmentStatus extends Command
{
    protected $signature = 'collection:fix-installment-status
        {--dry-run : Solo mostrar que cuotas cambiarian de estado}
        {--credit=* : Limitar a estos IDs de credito}';

    protected $description = 'Corrige el estado de las cuotas (pendiente/parcial/pagado) segun lo realmente cobrado';

    private const CONNECTION = 'collection_pgsql';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $creditIds = array_filter((array) $this->option('credit'));

        $cuotas = CollectionInstallment::query()
            ->whereNull('deleted_at')
            ->when($creditIds, fn ($q) => $q->whereIn('credit_id', $creditIds))
            ->orderBy('credit_id')
            ->orderBy('installment_number')
            ->get();

        $filas = [];
        $cambios = [];

        foreach ($cuotas as $c) {
            $correcto = $this->estadoCorrecto($c);
            if (strtolower((string) $c->status) === $correcto) {
                continue;
            }

            $cambios[] = [$c, $correcto];
            $filas[] = [
                'CR-' . $c->credit_id,
                '#' . $c->installment_number,
                $c->status,
                $correcto,
                number_format((float) $c->interest_amount, 2),
                number_format((float) ($c->interest_paid ?? 0), 2),
                number_format((float) ($c->principal_paid ?? 0), 2),
            ];
        }

        if (empty($cambios)) {
            $this->info('Todas las cuotas tienen el estado correcto. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[SIMULACION] ' : '') . 'Cuotas a corregir: ' . count($cambios));
        $this->table(
            ['Credito', 'Cuota', 'Estado actual', 'Correcto', 'Interes', 'Int. cobrado', 'Cap. abonado'],
            $filas
        );

        if ($dryRun) {
            $this->comment('Simulacion: no se escribio nada.');
            return self::SUCCESS;
        }

        DB::connection(self::CONNECTION)->transaction(function () use ($cambios) {
            foreach ($cambios as [$cuota, $correcto]) {
                $cuota->update(['status' => $correcto]);
            }
        });

        $this->info('Listo: ' . count($cambios) . ' cuota(s) corregida(s).');

        return self::SUCCESS;
    }

    /** Misma cuenta que el motor de pagos, para que no vuelvan a divergir. */
    private function estadoCorrecto(CollectionInstallment $c): string
    {
        $interesPagado = round((float) ($c->interest_paid ?? 0), 2);
        $capitalPagado = round((float) ($c->principal_paid ?? 0), 2);
        $interes = round((float) $c->interest_amount, 2);
        $capitalCuota = round((float) $c->principal_amount, 2);

        // SIN COBROS NO HAY CUOTA PAGADA, pase lo que pase con los importes.
        //
        // Hay cuotas con interes y capital en 0 —creditos con tasa 0 o cuotas mal
        // generadas— que por la sola comparacion darian 'pagado': 0 >= 0. Marcar
        // como pagada una cuota que nadie pago es mentira, y ademas cambia el
        // comportamiento del sistema: el credito solo avanza de periodo cuando no
        // le queda ninguna cuota abierta, asi que darlas por pagadas le haria
        // generar la cuota siguiente. Eso es otro problema y no se toca desde aca.
        if ($interesPagado <= 0 && $capitalPagado <= 0) {
            return 'pendiente';
        }

        if ($interesPagado >= $interes && $capitalPagado >= $capitalCuota) {
            return 'pagado';
        }

        $capitalDeLaCuota = min($capitalPagado, $capitalCuota);

        return ($interesPagado > 0 || $capitalDeLaCuota > 0) ? 'parcial' : 'pendiente';
    }
}
