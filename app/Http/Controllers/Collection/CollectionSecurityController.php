<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionSecurityService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionSecurityController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionSecurityService $service)
    {
        $this->service = $service;
    }

    public function requestDeletionToken(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->generateDeletionToken($request->all());
    }

    public function getPendingTokens(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->getPendingTokens($request);
    }
}
