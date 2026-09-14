<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCompanyConfig;
use App\Models\Company;
use App\Services\Collection\CollectionTelegramNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reportes diarios de cobranza por Telegram (módulo Deuda & Abono).
 *
 * Son DOS envíos por día, no uno. La razón es que las dos preguntas que se
 * quieren responder no se contestan a la misma hora:
 *
 *   - APERTURA (primera hora): a quién hay que cobrarle hoy. Es la agenda del
 *     cobrador, y sirve ANTES de salir a la calle. Se le suma el resultado de
 *     AYER, porque a las 7am lo de hoy todavía no existe.
 *   - CIERRE (fin de jornada): qué se cobró, quién cumplió y quién no. Recién
 *     acá el dato de "pagaron hoy" tiene contenido.
 *
 * Un solo mensaje a primera hora con "pagaron hoy" adentro daría 0 todos los
 * días; uno solo al cierre llegaría tarde para organizar la ruta.
 *
 * Se agenda `everyMinute` y cada corrida decide si le toca a cada empresa,
 * comparando contra la hora LOCAL de esa empresa (`companies.timezone`). Es el
 * mismo patrón del corte de caja automático: un `dailyAt()` fijo mandaría el
 * reporte a la hora equivocada en países con husos distintos.
 *
 * Idempotente: marca en caché por empresa + día + momento, así los 60 disparos
 * de la hora no producen 60 mensajes.
 *
 * Uso manual:
 *   php artisan collection:telegram-daily-report --company=4 --moment=apertura --force
 *   php artisan collection:telegram-daily-report --company=4 --moment=cierre --dry-run --force
 */
class CollectionTelegramDailyReport extends Command
{
    protected $signature = 'collection:telegram-daily-report
        {--company= : Solo esta empresa (por id)}
        {--moment= : apertura | cierre. Por omisión evalúa los dos}
        {--date= : Día a reportar (YYYY-MM-DD). Por defecto, hoy en la zona de la empresa}
        {--force : Ignora la hora configurada y la marca de "ya enviado"}
        {--dry-run : Arma el mensaje y lo imprime, sin enviarlo}';

    protected $description = 'Envía por Telegram los reportes de cobranza de Collection (apertura: a quién cobrar hoy; cierre: qué se cobró)';

    /** Horas locales por defecto si la empresa no configuró otras. */
    private const DEFAULT_HOUR_OPEN = 7;
    private const DEFAULT_HOUR_CLOSE = 20;

    /** Máximo de clientes listados por bloque; el resto se resume. */
    private const MAX_ROWS = 15;

    public function handle(CollectionTelegramNotifier $notifier): int
    {
        $companyId = $this->option('company');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->when($companyId, fn ($q) => $q->where('id', (int) $companyId))
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('Ninguna empresa con telegram_chat_id configurado.');
            return self::SUCCESS;
        }

        $moments = $this->option('moment')
            ? [$this->option('moment')]
            : ['apertura', 'cierre'];

        foreach ($companies as $company) {
            $tz = $company->timezone ?: 'America/Bogota';
            $now = Carbon::now($tz);
            $date = $this->option('date') ?: $now->toDateString();

            foreach ($moments as $moment) {
                if (!in_array($moment, ['apertura', 'cierre'], true)) {
                    $this->error("Momento inválido: {$moment} (usar apertura o cierre)");
                    return self::FAILURE;
                }

                $hour = $this->reportHourFor((int) $company->id, $moment);

                // Ventana de 1 hora local. Con --force se ignora, para poder
                // dispararlo a mano desde el servidor.
                if (!$force && $now->hour !== $hour) {
                    continue;
                }

                $mark = "collection:tg-report:{$company->id}:{$date}:{$moment}";
                if (!$force && Cache::get($mark)) {
                    continue;
                }

                $message = $moment === 'apertura'
                    ? $this->buildOpeningMessage((int) $company->id, $company->name ?? 'Empresa', $date, $tz)
                    : $this->buildClosingMessage((int) $company->id, $company->name ?? 'Empresa', $date, $tz);

                if ($dryRun) {
                    $this->line("--- empresa {$company->id} · {$moment} · {$tz} ---");
                    $this->line($message);
                    $this->line('');
                    continue;
                }

                $sent = $notifier->sendToCompany(
                    (int) $company->id,
                    $message,
                    "collection_report_{$moment}"
                );

                if ($sent) {
                    // La marca se pone SOLO si se envió: si Telegram falló, el
                    // siguiente minuto de la ventana lo reintenta.
                    Cache::put($mark, $now->toIso8601String(), now()->addDays(2));
                    $this->info("Empresa {$company->id}: {$moment} de {$date} enviado.");
                } else {
                    $this->warn("Empresa {$company->id}: no se pudo enviar {$moment} (revisar chat_id / token).");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Hora local de envío por momento. Se lee de
     * `collection_company_configs.settings`; si no está, cae al default.
     */
    private function reportHourFor(int $companyId, string $moment): int
    {
        $config = CollectionCompanyConfig::where('company_id', $companyId)->first();
        $settings = is_array($config?->settings) ? $config->settings : [];

        $key = $moment === 'apertura'
            ? 'telegram_report_hour_open'
            : 'telegram_report_hour_close';
        $default = $moment === 'apertura'
            ? self::DEFAULT_HOUR_OPEN
            : self::DEFAULT_HOUR_CLOSE;

        $hour = $settings[$key] ?? null;

        return is_numeric($hour) && $hour >= 0 && $hour <= 23 ? (int) $hour : $default;
    }

    /**
     * APERTURA (primera hora): la agenda del día + el resultado de ayer.
     *
     * A esta hora no tiene sentido preguntar por lo cobrado hoy: son las 7am.
     * Lo que sí falta saber al empezar la jornada es cómo cerró ayer.
     */
    private function buildOpeningMessage(int $companyId, string $companyName, string $date, string $tz): string
    {
        $ayer = Carbon::parse($date, $tz)->subDay()->toDateString();

        $porCobrar = $this->dueToday($companyId, $date);
        $cobradoAyer = $this->paidOn($companyId, $ayer, $tz);
        $vencianAyer = $this->dueToday($companyId, $ayer);

        $lines = [];
        $lines[] = '📋 *Reporte de Cobranza Diario*';
        $lines[] = "☀️ Apertura · {$companyName}";
        $lines[] = '_' . Carbon::parse($date)->format('d/m/Y') . '_';
        $lines[] = '';

        $lines[] = '*Hay que cobrarle a ' . count($porCobrar) . ' cliente(s) hoy*';
        if (!count($porCobrar)) {
            $lines[] = '_Sin cuotas con vencimiento hoy._';
        } else {
            $lines[] = 'Total esperado: *' . $this->money(array_sum(array_column($porCobrar, 'pendiente'))) . '*';
            $lines[] = '';
            $lines = array_merge($lines, $this->rowsFor($porCobrar, 'pendiente'));
        }

        // Cierre de ayer, para arrancar el día sabiendo cómo venimos.
        $lines[] = '';
        $lines[] = '*Ayer* (' . Carbon::parse($ayer)->format('d/m') . ')';
        if (!count($cobradoAyer)) {
            $lines[] = '_Sin pagos registrados._';
        } else {
            $lines[] = 'Cobrado: *' . $this->money(array_sum(array_column($cobradoAyer, 'monto')))
                . '* de ' . count($cobradoAyer) . ' cliente(s).';
        }
        if (count($vencianAyer)) {
            $pendientes = $this->stillOwing($vencianAyer, $cobradoAyer);
            $lines[] = 'Cumplimiento: *' . (count($vencianAyer) - count($pendientes)) . '/'
                . count($vencianAyer) . '* de los que vencían.';
            if (count($pendientes)) {
                $lines[] = 'Quedaron sin pagar: ' . implode(', ', array_slice(
                    array_column($pendientes, 'client'), 0, 8
                )) . (count($pendientes) > 8 ? ' y ' . (count($pendientes) - 8) . ' más' : '') . '.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * CIERRE (fin de jornada): qué se cobró hoy y quién quedó debiendo.
     */
    private function buildClosingMessage(int $companyId, string $companyName, string $date, string $tz): string
    {
        $porCobrar = $this->dueToday($companyId, $date);
        $cobrado = $this->paidOn($companyId, $date, $tz);

        $lines = [];
        $lines[] = '📋 *Reporte de Cobranza Diario*';
        $lines[] = "🌙 Cierre · {$companyName}";
        $lines[] = '_' . Carbon::parse($date)->format('d/m/Y') . '_';
        $lines[] = '';

        $lines[] = '*Cobrado hoy: ' . $this->money(array_sum(array_column($cobrado, 'monto'))) . '*';
        if (!count($cobrado)) {
            $lines[] = '_Sin pagos registrados._';
        } else {
            $lines[] = count($cobrado) . ' cliente(s) pagaron.';
            $lines[] = '';
            $lines = array_merge($lines, $this->rowsFor($cobrado, 'monto'));
        }

        if (count($porCobrar)) {
            // `dueToday` ya excluye las cuotas saldadas: lo que sigue acá es lo
            // que quedó realmente sin cobrar al cierre.
            $pendientes = $this->stillOwing($porCobrar, $cobrado);
            $lines[] = '';
            $lines[] = 'Cumplimiento: *' . (count($porCobrar) - count($pendientes)) . '/'
                . count($porCobrar) . '* de los que vencían hoy.';

            if (count($pendientes)) {
                $lines[] = '';
                $lines[] = '*Quedaron sin pagar: ' . count($pendientes) . '*';
                $lines = array_merge($lines, $this->rowsFor($pendientes, 'pendiente'));
            }
        }

        return implode("\n", $lines);
    }

    /** Clientes que debían y no aparecen entre los que pagaron. */
    private function stillOwing(array $debian, array $pagaron): array
    {
        $idsPagaron = array_column($pagaron, 'client_id');

        return array_values(array_filter(
            $debian,
            fn ($r) => !in_array($r['client_id'], $idsPagaron, true)
        ));
    }

    /** Líneas "• cliente · ruta — monto", recortadas a MAX_ROWS. */
    private function rowsFor(array $rows, string $amountKey): array
    {
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $r) {
            $ruta = $r['route_name'] ? " · {$r['route_name']}" : '';
            $out[] = "• {$r['client']}{$ruta} — " . $this->money($r[$amountKey]);
        }
        if (count($rows) > self::MAX_ROWS) {
            $out[] = '_… y ' . (count($rows) - self::MAX_ROWS) . ' más._';
        }

        return $out;
    }

    /**
     * Clientes con cuota que vence en $date y todavía debe algo.
     *
     * Se agrupa por cliente: si un cliente tiene dos créditos venciendo el mismo
     * día, es UNA visita del cobrador, no dos.
     */
    private function dueToday(int $companyId, string $date): array
    {
        $rows = DB::connection('collection_pgsql')
            ->table('collection_installments as i')
            ->join('collection_credits as c', function ($j) {
                $j->on('i.credit_id', '=', 'c.id')->on('i.company_id', '=', 'c.company_id');
            })
            ->join('collection_clients as cl', 'c.client_id', '=', 'cl.id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereDate('i.due_date', $date)
            ->whereIn('i.status', ['pendiente', 'parcial'])
            // Agrupado por CLIENTE, no por crédito ni por ruta: si un cliente
            // tiene dos créditos venciendo el mismo día es una sola visita del
            // cobrador. Las rutas se concatenan (normalmente es una sola).
            ->groupBy('cl.id', 'cl.name')
            ->selectRaw("cl.id as client_id, cl.name as client,
                         string_agg(DISTINCT c.route_name, ', ') as route_name,
                         SUM(GREATEST(COALESCE(i.amount,0) - COALESCE(i.paid_amount,0), 0)) as pendiente")
            ->orderByDesc('pendiente')
            ->get();

        return $rows->map(fn ($r) => [
            'client_id' => (int) $r->client_id,
            'client' => $r->client ?: 'Sin nombre',
            'route_name' => $r->route_name,
            'pendiente' => (float) $r->pendiente,
        ])->all();
    }

    /**
     * Pagos registrados en $date, agrupados por cliente.
     *
     * `recorded_at` se guarda en UTC, así que el rango del día se calcula en la
     * zona de la empresa y se convierte: comparar la fecha cruda mandaría los
     * pagos de la noche al día siguiente.
     */
    private function paidOn(int $companyId, string $date, string $tz): array
    {
        $from = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $to = Carbon::parse($date . ' 23:59:59', $tz)->utc();

        $rows = DB::connection('collection_pgsql')
            ->table('collection_payments as p')
            ->join('collection_credits as c', function ($j) {
                $j->on('p.credit_id', '=', 'c.id')->on('p.company_id', '=', 'c.company_id');
            })
            ->join('collection_clients as cl', 'c.client_id', '=', 'cl.id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->whereBetween('p.recorded_at', [$from, $to])
            ->groupBy('cl.id', 'cl.name')
            ->selectRaw("cl.id as client_id, cl.name as client,
                         string_agg(DISTINCT c.route_name, ', ') as route_name,
                         SUM(COALESCE(p.amount_paid,0)) as monto")
            ->orderByDesc('monto')
            ->get();

        return $rows->map(fn ($r) => [
            'client_id' => (int) $r->client_id,
            'client' => $r->client ?: 'Sin nombre',
            'route_name' => $r->route_name,
            'monto' => (float) $r->monto,
        ])->all();
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
