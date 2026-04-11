<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionExpenseService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionExpenseController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionExpenseService $service)
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
}
