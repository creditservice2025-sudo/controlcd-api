<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionDailyRecordService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionDailyRecordController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionDailyRecordService $service)
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

        return $this->service->create($request);
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->destroy($request, $id);
    }

    /**
     * Tendencia de movimientos día por día en un rango (para reporte Excel).
     */
    public function trend(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $tz = $request->query('timezone') ?: 'America/Bogota';
        $today = \Carbon\Carbon::now($tz)->toDateString();
        $from = $request->query('from', \Carbon\Carbon::now($tz)->startOfMonth()->toDateString());
        $to = $request->query('to', $today);
        $countryCode = $request->query('country_code');

        return $this->service->getTrend($companyId, $from, $to, $tz, $countryCode);
    }
}
