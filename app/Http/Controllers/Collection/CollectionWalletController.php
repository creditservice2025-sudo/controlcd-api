<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionWalletService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionWalletController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionWalletService $service)
    {
        $this->service = $service;
    }

    public function getBalances(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

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

        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $payload = array_merge($request->all(), ['company_id' => $companyId]);

        return $this->service->injectCapital($payload);
    }

    public function indexLedger(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $filters = $request->only(['action_type', 'country_code', 'type', 'per_page']);

        return response()->json($this->service->getLedgerMovements($companyId, $filters));
    }
}
