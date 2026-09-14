<?php

namespace App\Console\Commands;

use App\Helpers\TimezoneHelper;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de la fecha contable (business_date) para históricos de Collection.
 *
 * Contexto: hasta ahora el día contable de daily_records y expenses se derivaba
 * en tiempo de consulta con "recorded_at AT TIME ZONE tz", lo cual es incorrecto
 * (recorded_at es TIMESTAMP sin zona). Este comando congela business_date en los
 * registros existentes:
 *
 *   - daily_records: recorded_at está en UTC. Se convierte a la zona del PAÍS
 *     del movimiento (country_code → IANA). Si el país es desconocido/nulo, se
 *     usa la zona de la empresa (fallback America/Bogota).
 *   - expenses: recorded_at está en la zona de la app (America/Lima, por el
 *     Carbon::now() sin zona del create). Se convierte a la zona de la EMPRESA
 *     (los gastos no tienen país).
 *
 * Seguridad y escala:
 *   - Se usan PARÁMETROS LIGADOS (no se interpola la zona en el SQL).
 *   - El UPDATE se hace por LOTES acotados (keyset por id) para no tomar un lock
 *     largo ni inflar la tabla en producción de gran volumen.
 *   - Es idempotente: solo toca filas con business_date IS NULL. Se puede
 *     reejecutar sin efectos secundarios.
 *
 * Uso:
 *   php artisan collection:backfill-business-dates                # aplica
 *   php artisan collection:backfill-business-dates --dry-run      # solo cuenta
 *   php artisan collection:backfill-business-dates --chunk=2000   # tamaño de lote
 */
class CollectionBackfillBusinessDates extends Command
{
    protected $signature = 'collection:backfill-business-dates
        {--dry-run : Solo mostrar cuántas filas se actualizarían, sin escribir}
        {--chunk=5000 : Tamaño de lote por UPDATE (para no tomar locks largos)}';

    protected $description = 'Backfill de business_date (fecha contable) en collection_daily_records y collection_expenses';

    private const CONN = 'collection_pgsql';

    // Zona en la que quedaron guardados los recorded_at históricos de cada tabla.
    private const SRC_TZ_DAILY_RECORDS = 'UTC';        // se guarda con ->utc()
    private const SRC_TZ_EXPENSES = 'America/Lima';    // Carbon::now() (app tz)

    private const FALLBACK_TZ = 'America/Bogota';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $this->info($dry ? '== DRY-RUN (no se escribe) ==' : "== Aplicando backfill (lote={$chunk}) ==");

        $totalDr = $this->backfillDailyRecords($dry, $chunk);
        $totalExp = $this->backfillExpenses($dry, $chunk);

        $this->newLine();
        $this->info("daily_records actualizados: {$totalDr}");
        $this->info("expenses actualizados: {$totalExp}");

        $this->reportRemainingNulls();

        return self::SUCCESS;
    }

    /**
     * daily_records: business_date en zona del país (country_code) o, si no hay
     * país conocido, en zona de la empresa. Origen UTC.
     */
    private function backfillDailyRecords(bool $dry, int $chunk): int
    {
        // Pares (company_id, country_code) pendientes.
        $pairs = DB::connection(self::CONN)
            ->table('collection_daily_records')
            ->select('company_id', 'country_code')
            ->whereNull('business_date')
            ->whereNotNull('recorded_at')
            ->distinct()
            ->get();

        $total = 0;
        foreach ($pairs as $pair) {
            $tz = TimezoneHelper::timezoneForCountryCode($pair->country_code)
                ?: $this->companyTz((int) $pair->company_id);

            $countExpr = fn () => DB::connection(self::CONN)
                ->table('collection_daily_records')
                ->whereNull('business_date')
                ->whereNotNull('recorded_at')
                ->where('company_id', $pair->company_id)
                ->when($pair->country_code === null,
                    fn ($q) => $q->whereNull('country_code'),
                    fn ($q) => $q->where('country_code', $pair->country_code))
                ->count();

            $pending = $countExpr();
            if ($pending === 0) continue;

            $label = 'daily_records company=' . $pair->company_id
                . ' country=' . ($pair->country_code ?? 'NULL') . " tz={$tz}";
            $this->line("  {$label} → {$pending}");

            if ($dry) {
                $total += $pending;
                continue;
            }

            // UPDATE por lotes: se limita a un subconjunto de ids en cada pasada
            // para no bloquear la tabla completa. business_date = fecha local en
            // $tz del instante UTC guardado. $tz va como parámetro ligado.
            $sql = "UPDATE collection_daily_records
                    SET business_date = ((recorded_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date
                    WHERE id IN (
                        SELECT id FROM collection_daily_records
                        WHERE business_date IS NULL AND recorded_at IS NOT NULL
                          AND company_id = ?
                          AND " . ($pair->country_code === null ? 'country_code IS NULL' : 'country_code = ?') . "
                        LIMIT {$chunk}
                    )";
            $bindings = $pair->country_code === null
                ? [$tz, $pair->company_id]
                : [$tz, $pair->company_id, $pair->country_code];

            $total += $this->runChunked($sql, $bindings, $label);
        }

        return $total;
    }

    /**
     * expenses: business_date en zona de la empresa. Origen America/Lima.
     */
    private function backfillExpenses(bool $dry, int $chunk): int
    {
        $companyIds = DB::connection(self::CONN)
            ->table('collection_expenses')
            ->whereNull('business_date')
            ->distinct()
            ->pluck('company_id');

        $total = 0;
        foreach ($companyIds as $companyId) {
            $tz = $this->companyTz((int) $companyId);

            $pending = DB::connection(self::CONN)
                ->table('collection_expenses')
                ->whereNull('business_date')
                ->where('company_id', $companyId)
                ->count();
            if ($pending === 0) continue;

            $label = "expenses company={$companyId} tz={$tz}";
            $this->line("  {$label} → {$pending}");

            if ($dry) {
                $total += $pending;
                continue;
            }

            // Usa recorded_at o, si es NULL, created_at (ambos en zona app/Lima).
            $sql = "UPDATE collection_expenses
                    SET business_date = ((COALESCE(recorded_at, created_at) AT TIME ZONE ?) AT TIME ZONE ?)::date
                    WHERE id IN (
                        SELECT id FROM collection_expenses
                        WHERE business_date IS NULL AND company_id = ?
                        LIMIT {$chunk}
                    )";
            $bindings = [self::SRC_TZ_EXPENSES, $tz, $companyId];

            $total += $this->runChunked($sql, $bindings, $label);
        }

        return $total;
    }

    /**
     * Ejecuta un UPDATE acotado por LIMIT en un bucle hasta que no queden filas.
     * Cada pasada es una transacción corta → sin locks largos en tablas grandes.
     */
    private function runChunked(string $sql, array $bindings, string $label): int
    {
        $affectedTotal = 0;
        do {
            $affected = DB::connection(self::CONN)->update($sql, $bindings);
            $affectedTotal += $affected;
            if ($affected > 0) {
                $this->line("    · lote {$label}: {$affected} (acum {$affectedTotal})");
            }
        } while ($affected > 0);

        return $affectedTotal;
    }

    private function reportRemainingNulls(): void
    {
        $dr = DB::connection(self::CONN)->table('collection_daily_records')->whereNull('business_date')->count();
        $exp = DB::connection(self::CONN)->table('collection_expenses')->whereNull('business_date')->count();
        if ($dr > 0 || $exp > 0) {
            $this->warn("Quedan con business_date NULL → daily_records: {$dr}, expenses: {$exp} (revisar: recorded_at nulo o país sin mapear)");
        } else {
            $this->info('Sin business_date NULL pendientes.');
        }
    }

    private function companyTz(int $companyId): string
    {
        static $cache = [];
        if (!array_key_exists($companyId, $cache)) {
            $cache[$companyId] = Company::find($companyId)?->timezone ?: self::FALLBACK_TZ;
        }
        return $cache[$companyId];
    }
}
