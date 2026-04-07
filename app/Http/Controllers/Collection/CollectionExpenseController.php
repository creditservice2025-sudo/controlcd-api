<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionExpenseService;
use Illuminate\Http\Request;

class CollectionExpenseController extends Controller
{
    protected $service;

    public function __construct(CollectionExpenseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        return $this->service->index($request);
    }

    public function store(Request $request)
    {
        return $this->service->create($request);
    }
}
