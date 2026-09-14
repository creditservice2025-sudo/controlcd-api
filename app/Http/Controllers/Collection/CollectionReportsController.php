<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionReportsService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionReportsController extends Controller
{
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionReportsService $service)
    {
    }

    public function cajaDiaria(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return response()->json($this->service->cajaDiaria(
            $companyId,
            $request->input('date'),
            $request->input('currency')
        ));
    }

    public function morosidad(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return response()->json($this->service->morosidad(
            $companyId,
            (int) $request->input('min_days', 0)
        ));
    }

    public function recaudo(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        return response()->json($this->service->recaudo(
            $companyId,
            $request->input('from_date'),
            $request->input('to_date')
        ));
    }

    public function gastos(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        return response()->json($this->service->gastos(
            $companyId,
            $request->input('from_date'),
            $request->input('to_date')
        ));
    }

    public function cartera(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return response()->json($this->service->cartera($companyId));
    }

    public function estadoCuenta(Request $request, int $clientId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $data = $this->service->estadoCuenta($companyId, $clientId);
        if (!$data) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }

        return response()->json($data);
    }
}
