<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dia de negocio (fecha contable) en el ledger de la caja centralizada.
 *
 * El ledger guardaba solo `created_at`, que es un instante en la zona de la
 * app. El corte diario de caja necesita saber a que JORNADA pertenece cada
 * movimiento, y esa jornada depende de la zona del pais del wallet, no de la
 * del servidor: un reintegro por anulacion a las 20:30 en Lima cae el dia
 * siguiente si se agrupa por `created_at` en UTC, y descuadra dos dias --le
 * falta al de hoy y le sobra al de manana--.
 *
 * Es el mismo anclaje que ya tienen `collection_daily_records` y
 * `collection_expenses`; el ledger habia quedado afuera.
 *
 * BACKFILL: las filas existentes se rellenan convirtiendo `created_at` a la
 * zona del pais de su wallet. La columna queda NULLABLE a proposito, para que
 * una fila sin fecha se distinga de una con fecha mal calculada, y para que el
 * despliegue no falle si alguna fila no se puede resolver.
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection/2026_09_01_000000_add_business_date_to_collection_ledger.php
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    /** Zona por pais. Debe coincidir con TimezoneHelper::COUNTRY_CODE_TIMEZONES. */
    private const TZ = [
        'CO' => 'America/Bogota',
        'PE' => 'America/Lima',
        'VE' => 'America/Caracas',
        'EC' => 'America/Guayaquil',
        'BO' => 'America/La_Paz',
        'CL' => 'America/Santiago',
        'AR' => 'America/Argentina/Buenos_Aires',
        'MX' => 'America/Mexico_City',
        'BR' => 'America/Sao_Paulo',
        'PA' => 'America/Panama',
        'DO' => 'America/Santo_Domingo',
        'UY' => 'America/Montevideo',
        'US' => 'America/New_York',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        $conn->statement('ALTER TABLE collection_ledger ADD COLUMN IF NOT EXISTS business_date DATE');

        // El corte agrupa por empresa y jornada; ese es el indice que sirve.
        $conn->statement(
            'CREATE INDEX IF NOT EXISTS idx_col_ledger_company_business_date
             ON collection_ledger (company_id, business_date)'
        );

        // Backfill: la zona sale del pais del wallet del movimiento.
        // `created_at` esta en la zona de la app (config app.timezone), asi que
        // se declara esa zona de origen antes de convertir; usar UTC aca fue el
        // error clasico que corria la fecha una hora en la madrugada.
        $appTz = config('app.timezone') ?: 'UTC';

        foreach (self::TZ as $code => $tz) {
            $conn->statement(
                "UPDATE collection_ledger l
                    SET business_date = ((l.created_at AT TIME ZONE ?) AT TIME ZONE ?)::date
                   FROM collection_wallets w
                  WHERE w.id = l.wallet_id
                    AND w.country_code = ?
                    AND l.business_date IS NULL",
                [$appTz, $tz, $code]
            );
        }

        // Resto sin wallet resoluble: la zona de la app es lo mejor disponible.
        $conn->statement(
            'UPDATE collection_ledger SET business_date = created_at::date WHERE business_date IS NULL'
        );
    }

    public function down(): void
    {
        $conn = DB::connection($this->connection);
        $conn->statement('DROP INDEX IF EXISTS idx_col_ledger_company_business_date');
        $conn->statement('ALTER TABLE collection_ledger DROP COLUMN IF EXISTS business_date');
    }
};
