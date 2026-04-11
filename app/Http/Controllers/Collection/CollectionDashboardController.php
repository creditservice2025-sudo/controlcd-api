<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionDashboardService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionDashboardController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionDashboardService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->getSummary($request);
    }
}
