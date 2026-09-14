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

    // Cierre manual (store) y reapertura (reopen) eliminados: el corte de caja
    // ahora es 100% automático a las 23:59:59 hora local de la empresa
    // (comando collection:check-pending-closures). El día cortado es inmutable.

    /**
     * Lista cierres en estado auto_pending (pendientes de validación admin).
     */
    public function pendingValidation(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->listPendingValidation($companyId);
    }

    /**
     * Valida un cierre auto_pending capturando efectivo y transferencias reales.
     */
    public function validateClosure(Request $request, int $closureId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'efectivo_contado' => 'required|numeric|min:0',
            'transferencias_recibidas' => 'nullable|numeric|min:0',
            'notas' => 'nullable|string|max:1000',
        ]);

        return $this->service->validateClosure($companyId, $closureId, $validated);
    }
}
