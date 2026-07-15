<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionLedger;
use App\Models\Collection\CollectionPayment;
use App\Models\Company;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Registros diarios. No toca wallets ni ledger.
 * Es una bitácora manual paralela: ingreso | gasto | transferencia | ajuste.
 */
class CollectionDailyRecordService
{
    use ApiResponse;

    public function __construct(
        private readonly CollectionCashClosureService $closureSvc,
        private readonly CollectionWalletService $walletSvc,
    ) {
    }

    /**
     * Zona horaria (IANA) de la empresa. Deuda & Abono opera SIEMPRE en la hora
     * local de la empresa (companies.timezone), NO en la del navegador: así el
     * día del movimiento y el listado son consistentes (y coinciden con el
     * corte de caja automático). Fallback: America/Bogota.
     */
    private function companyTz(int $companyId): string
    {
        return Company::find($companyId)?->timezone ?: 'America/Bogota';
    }

    /**
     * Tendencia de movimientos día por día en un rango. Devuelve por cada
     * día: cobros, ingresos manuales, gastos aprobados, egresos manuales,
     * transferencias salientes, adiciones de capital y balance neto.
     *
     * Usado por el reporte Excel "tendencia mensual".
     */
    public function getTrend(int $companyId, string $from, string $to, string $tz, ?string $countryCode = null)
    {
        // Siempre en zona horaria de la empresa (ignora el tz recibido).
        $tz = $this->companyTz($companyId);
        $startUtc = Carbon::parse($from . ' 00:00:00', $tz)->utc();
        $endUtc = Carbon::parse($to . ' 23:59:59', $tz)->utc();

        // 1) Daily records agrupados por día y tipo
        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$startUtc, $endUtc]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drRows = $drQ
            ->selectRaw("to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d, type, SUM(amount) as total", [$tz])
            ->groupBy('d', 'type')
            ->get();

        // 2) Cobros (payments)
        $payQ = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$startUtc, $endUtc]);
        if ($countryCode) {
            $payQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $payRows = $payQ
            ->selectRaw("to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d, SUM(amount_paid) as total", [$tz])
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        // 3) Gastos aprobados (expenses)
        $expRows = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->where('status', 'approved')
            ->whereBetween('recorded_at', [$startUtc, $endUtc])
            ->selectRaw("to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d, SUM(amount) as total", [$tz])
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        // 4) Adiciones de capital
        $adicQ = CollectionCapitalAddition::where('company_id', $companyId)
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) {
            $adicQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $adicRows = $adicQ
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, SUM(amount) as total")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        // Construir un mapa día → buckets
        $bucket = [];
        $ensure = function (string $d) use (&$bucket) {
            if (!isset($bucket[$d])) {
                $bucket[$d] = [
                    'date' => $d,
                    'cobros' => 0.0,
                    'ingresos' => 0.0,
                    'gastos' => 0.0,
                    'egresos' => 0.0,
                    'transferencias' => 0.0,
                    'adiciones' => 0.0,
                    'neto' => 0.0,
                ];
            }
            return $d;
        };

        foreach ($drRows as $r) {
            $d = $ensure($r->d);
            $amount = (float) $r->total;
            if ($r->type === 'ingreso') $bucket[$d]['ingresos'] += $amount;
            elseif ($r->type === 'gasto') $bucket[$d]['egresos'] += $amount;
            elseif ($r->type === 'transferencia') $bucket[$d]['transferencias'] += $amount;
        }
        foreach ($payRows as $d => $r) {
            $ensure($d);
            $bucket[$d]['cobros'] = (float) $r->total;
        }
        foreach ($expRows as $d => $r) {
            $ensure($d);
            $bucket[$d]['gastos'] = (float) $r->total;
        }
        foreach ($adicRows as $d => $r) {
            $ensure($d);
            $bucket[$d]['adiciones'] = (float) $r->total;
        }

        // Calcular neto y ordenar por fecha asc
        foreach ($bucket as $d => &$row) {
            $row['neto'] = round(
                $row['cobros'] + $row['ingresos']
                - $row['gastos'] - $row['egresos']
                - $row['transferencias'] - $row['adiciones'],
                2
            );
        }
        unset($row);
        ksort($bucket);

        // Totales del rango
        $totals = [
            'cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
            'egresos' => 0.0, 'transferencias' => 0.0,
            'adiciones' => 0.0, 'neto' => 0.0,
        ];
        foreach ($bucket as $row) {
            foreach ($totals as $k => $_) $totals[$k] += $row[$k];
        }
        foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

        return $this->successResponse([
            'from' => $from,
            'to' => $to,
            'timezone' => $tz,
            'country_code' => $countryCode,
            'days' => array_values($bucket),
            'totals' => $totals,
        ]);
    }

    public function index($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $tz = $this->companyTz($companyId);
        $date = $request->query('date', Carbon::now($tz)->toDateString());
        $countryCode = $request->query('country_code');
        $type = $request->query('type');

        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();

        // Si el tipo filtrado es 'capital_addition', no cargamos daily records.
        $dailyRecords = collect();
        if ($type !== 'capital_addition') {
            $q = CollectionDailyRecord::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereBetween('recorded_at', [$dayStart, $dayEnd]);

            if ($countryCode) $q->where('country_code', strtoupper($countryCode));
            if ($type && in_array($type, CollectionDailyRecord::TYPES)) $q->where('type', $type);

            $dailyRecords = $q->orderBy('recorded_at', 'desc')->get();

            // Adjuntar el nombre de quien creó cada movimiento para trazabilidad.
            // Los usuarios viven en MySQL y los registros en PostgreSQL, por eso
            // se resuelven en un query aparte (batch por user_id).
            $userIds = $dailyRecords->pluck('user_id')->filter()->unique()->values()->all();
            if (!empty($userIds)) {
                $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');
                $dailyRecords->each(function ($r) use ($names) {
                    $r->created_by_name = $names[$r->user_id] ?? null;
                });
            }
        }

        // Adiciones de capital como filas virtuales (type='capital_addition').
        $capitalVirtual = collect();
        if (!$type || $type === 'capital_addition') {
            $capitalVirtual = $this->buildVirtualCapitalAdditions($companyId, $date, $countryCode);
        }

        // Combinar y ordenar por timestamp descendente.
        $all = $dailyRecords->concat($capitalVirtual)->sortByDesc(function ($r) {
            return is_object($r) && isset($r->recorded_at)
                ? Carbon::parse($r->recorded_at)->timestamp
                : (is_array($r) ? strtotime($r['recorded_at'] ?? 'now') : 0);
        })->values();

        $totals = [
            'ingreso' => (float) $dailyRecords->where('type', 'ingreso')->sum('amount'),
            'gasto' => (float) $dailyRecords->where('type', 'gasto')->sum('amount'),
            'transferencia' => (float) $dailyRecords->where('type', 'transferencia')->sum('amount'),
            'capital_addition' => (float) $capitalVirtual->sum(fn($r) => (float) ($r['amount'] ?? 0)),
        ];
        // Balance del día = ingresos − (gastos + transferencias salientes + adiciones de capital).
        // Las transferencias salen de la wallet (action=transfer_out) y las adiciones
        // también son salidas hacia el cliente, por eso restan al balance del día.
        $totals['net'] = $totals['ingreso']
            - $totals['gasto']
            - $totals['transferencia']
            - $totals['capital_addition'];

        return $this->successResponse([
            'records' => $all,
            'summary' => $totals,
            'date' => $date,
            'timezone' => $tz,
        ]);
    }

    /**
     * Construye filas virtuales de tipo 'capital_addition' para el dia dado,
     * compatibles con la forma de CollectionDailyRecord que consume la UI.
     */
    private function buildVirtualCapitalAdditions(int $companyId, string $date, ?string $countryCode): \Illuminate\Support\Collection
    {
        $rows = CollectionCapitalAddition::query()
            ->where('company_id', $companyId)
            ->where('business_date', $date)
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) return collect();

        $creditIds = $rows->pluck('credit_id')->filter()->unique()->values()->all();
        $credits = CollectionCredit::query()
            ->whereIn('id', $creditIds)
            ->where('company_id', $companyId)
            ->get(['id', 'client_id', 'amount'])
            ->keyBy('id');

        $clientIds = $credits->pluck('client_id')->filter()->unique()->values()->all();
        $clients = CollectionClient::query()
            ->whereIn('id', $clientIds)
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'country_code'])
            ->keyBy('id');

        // Si se filtra por country_code, aplicarlo al cliente vinculado.
        if ($countryCode) {
            $cc = strtoupper($countryCode);
            $rows = $rows->filter(function ($add) use ($credits, $clients, $cc) {
                $credit = $credits->get($add->credit_id);
                if (!$credit) return false;
                $client = $clients->get($credit->client_id);
                if (!$client) return false;
                return strtoupper((string) $client->country_code) === $cc;
            })->values();
        }

        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->all();
        $users = !empty($userIds)
            ? User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all()
            : [];

        return $rows->map(function ($add) use ($credits, $clients, $users) {
            $credit = $credits->get($add->credit_id);
            $client = $credit ? $clients->get($credit->client_id) : null;
            $clientName = $client->name ?? null;
            $countryCode = $client->country_code ?? null;

            $meta = [
                'credit_id' => $add->credit_id,
                'client_name' => $clientName,
                'reference_number' => $add->reference_number,
                'bank_name' => $add->bank_name,
                'payment_method' => $add->payment_method,
                'voucher_photo' => $add->voucher_photo,
                'virtual' => true,
            ];

            return [
                'id' => 'ca_' . $add->id,
                'company_id' => $add->company_id,
                'user_id' => $add->created_by,
                'user_name' => $users[$add->created_by] ?? null,
                'type' => 'capital_addition',
                'category' => $clientName
                    ? ('Crédito #' . $add->credit_id . ' · ' . $clientName)
                    : ('Crédito #' . $add->credit_id),
                'amount' => (float) $add->amount,
                'currency' => 'COP',
                'country_code' => $countryCode,
                'description' => $add->notes,
                'recorded_at' => optional($add->created_at)->toISOString()
                    ?? ($add->business_date . 'T00:00:00.000Z'),
                'latitude' => null,
                'longitude' => null,
                'metadata' => $meta,
                'deleted_at' => null,
            ];
        })->values();
    }

    public function create($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $validated = $request->validate([
            'type' => 'required|string|in:' . implode(',', CollectionDailyRecord::TYPES),
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'country_code' => 'nullable|string|size:2',
            'category' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            // Hasta 3 evidencias por registro
            'evidence' => 'nullable|array|max:3',
            'evidence.*' => 'image|max:5120',
            'recorded_at' => 'nullable|date',
            'transfer_from' => 'nullable|string|max:100',
            'transfer_to' => 'nullable|string|max:100',
        ]);

        // Bloquear si el día del registro tiene cierre de caja activo.
        // La fecha/hora elegida se interpreta en la zona horaria de la EMPRESA
        // (no la del navegador): el recorded_at llega como reloj de pared local.
        $tz = $this->companyTz($companyId);
        $recordedAt = !empty($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'], $tz)
            : Carbon::now($tz);
        $recordDate = $recordedAt->copy()->setTimezone($tz)->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $recordDate)) {
            return $this->errorResponse(
                'No se pueden registrar movimientos: la caja del día ' . $recordDate . ' está cerrada. Reabre el cierre primero.',
                409
            );
        }

        $metadata = [];
        $evidencePaths = [];
        if ($request->hasFile('evidence')) {
            $files = $request->file('evidence');
            if (!is_array($files)) $files = [$files];
            foreach (array_slice($files, 0, 3) as $file) {
                $path = $file->store(
                    "collection/daily-records/evidence/{$companyId}",
                    'public'
                );
                $evidencePaths[] = $path;
            }
        }
        if (!empty($evidencePaths)) $metadata['evidence_paths'] = $evidencePaths;
        // Para transferencias el origen es SIEMPRE la wallet del módulo.
        // El payload solo necesita destino. Forzamos transfer_from = "Wallet".
        if ($validated['type'] === 'transferencia') {
            $metadata['transfer_from'] = 'Wallet del módulo';
            if (!empty($validated['transfer_to'])) {
                $metadata['transfer_to'] = $validated['transfer_to'];
            }
        } else {
            if (!empty($validated['transfer_from'])) $metadata['transfer_from'] = $validated['transfer_from'];
            if (!empty($validated['transfer_to'])) $metadata['transfer_to'] = $validated['transfer_to'];
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($validated, $companyId, $metadata, $recordedAt) {
            $currency = strtoupper($validated['currency']);
            $countryCode = isset($validated['country_code']) ? strtoupper($validated['country_code']) : null;

            $record = CollectionDailyRecord::create([
                'company_id' => $companyId,
                'user_id' => Auth::id(),
                'type' => $validated['type'],
                'category' => $validated['category'] ?? null,
                'amount' => $validated['amount'],
                'currency' => $currency,
                'country_code' => $countryCode,
                'description' => $validated['description'] ?? null,
                // Se guarda en UTC (convención del módulo, igual que pagos): el
                // reloj de pared se parseó en zona de la empresa y aquí se
                // convierte al instante UTC correspondiente.
                'recorded_at' => $recordedAt->copy()->utc(),
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'metadata' => $metadata,
            ]);

            // Si es transferencia: descontar de la wallet del módulo y dejar
            // un movimiento en el ledger (action=transfer_out) referenciando
            // el daily_record. La wallet se elige por currency+country_code.
            if ($validated['type'] === 'transferencia') {
                $destino = $metadata['transfer_to'] ?? 'cuenta destino';
                $this->walletSvc->recordMovement([
                    'company_id' => $companyId,
                    'currency' => $currency,
                    'country_code' => $countryCode ?? 'CO',
                    'amount' => $validated['amount'],
                    'type' => 'debit',
                    'action_type' => 'transfer_out',
                    'reference_type' => 'daily_record',
                    'reference_id' => $record->id,
                    'description' => "Transferencia a {$destino}"
                        . (!empty($validated['description']) ? ' — ' . $validated['description'] : ''),
                ]);
            }

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Registro creado correctamente',
                'data' => $record,
            ]);
        });
    }

    public function destroy($request, int $id)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $record = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($id);

        if (!$record) return $this->errorResponse('Registro no encontrado', 404);

        // Bloquear si el día del registro está cerrado.
        $tz = $this->companyTz($companyId);
        $recordDate = optional($record->recorded_at)->setTimezone($tz)->toDateString();
        if ($recordDate && $this->closureSvc->isDayClosed($companyId, $recordDate)) {
            return $this->errorResponse(
                'No se puede eliminar: el día ' . $recordDate . ' tiene la caja cerrada. Reabre el cierre primero.',
                409
            );
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($record) {
            // Si era transferencia, revertir el movimiento en la wallet
            // (re-acreditando el monto que se debitó al crearla).
            if ($record->type === 'transferencia') {
                $meta = is_array($record->metadata) ? $record->metadata : [];
                $destino = $meta['transfer_to'] ?? 'cuenta destino';
                $this->walletSvc->recordMovement([
                    'company_id' => $record->company_id,
                    'currency' => strtoupper($record->currency ?: 'COP'),
                    'country_code' => strtoupper($record->country_code ?: 'CO'),
                    'amount' => (float) $record->amount,
                    'type' => 'credit',
                    'action_type' => 'transfer_out_reversal',
                    'reference_type' => 'daily_record',
                    'reference_id' => $record->id,
                    'description' => "Reversión transferencia a {$destino}",
                ]);
            }

            $record->delete();
            return $this->successResponse(['success' => true, 'message' => 'Registro eliminado']);
        });
    }

    private function resolveCompanyId($requestedId): int
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        $companyId = $user ? ($user->company->id ?? $user->seller->company_id ?? null) : null;
        // Fail-closed: sin empresa resoluble cortamos con 422 (no null), para
        // que Collection nunca consulte con WHERE company_id IS NULL.
        abort_if($companyId === null, 422, 'No se pudo resolver la empresa para la operación de Collection.');
        return (int) $companyId;
    }
}
