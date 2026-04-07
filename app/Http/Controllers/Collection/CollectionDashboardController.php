<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionDashboardService;
use Illuminate\Http\Request;

class CollectionDashboardController extends Controller
{
    protected $service;

    public function __construct(CollectionDashboardService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        return $this->service->getSummary($request);
    }
}
