<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionWalletController extends Controller
{
    protected $service;

    public function __construct(CollectionWalletService $service)
    {
        $this->service = $service;
    }

    public function getBalances(Request $request)
    {
        $companyId = $request->company_id ?? Auth::user()->company->id ?? Auth::user()->seller->company_id ?? null;
        if (!$companyId) return response()->json(['message' => 'Empresa no encontrada'], 422);

        return response()->json($this->service->getBalances($companyId));
    }

    public function inject(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'country_code' => 'required|string',
            'description' => 'required|string'
        ]);

        $companyId = $request->company_id ?? Auth::user()->company->id ?? Auth::user()->seller->company_id ?? null;
        if (!$companyId) return response()->json(['message' => 'Empresa no encontrada'], 422);

        $payload = array_merge($request->all(), ['company_id' => $companyId]);
        
        return $this->service->injectCapital($payload);
    }

    public function indexLedger(Request $request)
    {
        $companyId = $request->company_id ?? Auth::user()->company->id ?? Auth::user()->seller->company_id ?? null;
        if (!$companyId) return response()->json(['message' => 'Empresa no encontrada'], 422);

        $filters = $request->only(['action_type', 'country_code', 'type', 'per_page']);
        
        return response()->json($this->service->getLedgerMovements($companyId, $filters));
    }
}
