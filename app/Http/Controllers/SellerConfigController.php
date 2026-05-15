<?php
namespace App\Http\Controllers;

use App\Http\Requests\Seller\SellerConfigRequest;
use Illuminate\Http\Request;
use App\Services\SellerConfigService;

class SellerConfigController extends Controller
{
    protected $service;

    public function __construct(SellerConfigService $service)
    {
        $this->service = $service;
    }

    public function show($sellerId)
    {
        $config = $this->service->getBySeller($sellerId);
        return response()->json($config);
    }

    public function update(SellerConfigRequest $request, $sellerId)
    {
        $config = $this->service->createOrUpdate($sellerId, $request->validated());
        return response()->json($config);
    }

    /**
     * Historial de cambios de configuración de seguridad para un vendedor.
     * Devuelve los últimos cambios con usuario y diff por campo, paginado.
     */
    public function history(Request $request, $sellerId)
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $audits = \App\Models\SellerConfigAudit::where('seller_id', $sellerId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
        return response()->json($audits);
    }
}
