<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionDailyRecord;
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

        $q = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd]);

        if ($countryCode) $q->where('country_code', strtoupper($countryCode));
        if ($type && in_array($type, CollectionDailyRecord::TYPES)) $q->where('type', $type);

        $records = $q->orderBy('recorded_at', 'desc')->get();

        $totals = [
            'ingreso' => (float) $records->where('type', 'ingreso')->sum('amount'),
            'gasto' => (float) $records->where('type', 'gasto')->sum('amount'),
            'transferencia' => (float) $records->where('type', 'transferencia')->sum('amount'),
        ];
        $totals['net'] = $totals['ingreso'] - $totals['gasto'];

        return $this->successResponse([
            'records' => $records,
            'summary' => $totals,
            'date' => $date,
            'timezone' => $tz,
        ]);
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
