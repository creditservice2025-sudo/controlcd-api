<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionCashboxService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

/**
 * Cajas (cuentas) del módulo Collection — multi-caja de la bitácora de
 * registros diarios. Cada caja lleva su propio saldo y movimientos.
 */
class CollectionCashboxController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionCashboxService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->index($request);
    }

    public function store(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->store($request);
    }

    public function update(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->update($request, $id);
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->destroy($request, $id);
    }

    /**
     * Traza de altas, renombrados y bajas de cajas de la empresa.
     */
    public function history(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->history($request);
    }

    /**
     * Extracto diario de una caja (saldo anterior, movimientos, saldo final).
     * Lo consume el reporte Excel para que sus cifras sean de la caja elegida
     * y no del país entero.
     */
    public function statement(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->statement($request, $id);
    }

    /**
     * Habilitar / inhabilitar una caja (con motivo y traza de quién y cuándo).
     */
    public function setActive(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->setActive($request, $id);
    }
}
