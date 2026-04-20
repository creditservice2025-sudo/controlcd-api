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
}
