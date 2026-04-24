<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Servicio de Registros diarios. No toca wallets ni ledger.
 * Es una bitácora manual paralela: ingreso | gasto | transferencia | ajuste.
 */
class CollectionDailyRecordService
{
    use ApiResponse;

    public function index($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $tz = $request->query('timezone') ?: 'America/Bogota';
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
        $totals['net'] = $totals['ingreso'] - $totals['gasto'];

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
        if (!empty($validated['transfer_from'])) $metadata['transfer_from'] = $validated['transfer_from'];
        if (!empty($validated['transfer_to'])) $metadata['transfer_to'] = $validated['transfer_to'];

        $record = CollectionDailyRecord::create([
            'company_id' => $companyId,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'category' => $validated['category'] ?? null,
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'description' => $validated['description'] ?? null,
            'recorded_at' => $validated['recorded_at'] ?? Carbon::now(),
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'metadata' => $metadata,
        ]);

        return $this->successCreatedResponse([
            'success' => true,
            'message' => 'Registro creado correctamente',
            'data' => $record,
        ]);
    }

    public function destroy($request, int $id)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $record = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($id);

        if (!$record) return $this->errorResponse('Registro no encontrado', 404);

        $record->delete();
        return $this->successResponse(['success' => true, 'message' => 'Registro eliminado']);
    }

    private function resolveCompanyId($requestedId)
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        if (!$user) return null;
        return $user->company->id ?? $user->seller->company_id ?? null;
    }
}
