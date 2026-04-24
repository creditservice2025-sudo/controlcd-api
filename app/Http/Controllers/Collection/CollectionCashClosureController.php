<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionCashClosureService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionCashClosureController extends Controller
{
    use ApiResponse;
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionCashClosureService $service)
    {
    }

    /**
     * Resumen del dia + cierre activo (si existe).
     */
    public function show(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $date = $request->query('date', now()->toDateString());
        $tz = $request->query('timezone', 'America/Bogota');
        $countryCode = $request->query('country_code');

        return $this->service->getDaySummary($companyId, $date, $tz, $countryCode);
    }

    /**
     * Lista historial de cierres.
     */
    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->listClosures(
            $companyId,
            $request->query('from'),
            $request->query('to'),
            (int) $request->query('per_page', 30)
        );
    }

    /**
     * Cierra la caja del dia.
     */
    public function store(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'closure_date' => 'nullable|date',
            'country_code' => 'nullable|string|size:2',
            'currency' => 'nullable|string|max:10',
            'efectivo_contado' => 'required|numeric|min:0',
            'transferencias_recibidas' => 'nullable|numeric|min:0',
            'notas' => 'nullable|string|max:1000',
            'timezone' => 'nullable|string|max:80',
        ]);

        return $this->service->closeDay($companyId, $validated);
    }

    /**
     * Reabre un cierre (admin).
     */
    public function reopen(Request $request, int $closureId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $user = $request->user();
        if (!in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('Solo administradores pueden reabrir un cierre', 403);
        }

        $reason = $request->input('reason');
        return $this->service->reopen($companyId, $closureId, $reason);
    }
}
