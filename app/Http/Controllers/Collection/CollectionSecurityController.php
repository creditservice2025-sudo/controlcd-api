<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionSecurityService;
use Illuminate\Http\Request;

class CollectionSecurityController extends Controller
{
    protected $service;

    public function __construct(CollectionSecurityService $service)
    {
        $this->service = $service;
    }

    public function requestDeletionToken(Request $request)
    {
        return $this->service->generateDeletionToken($request->all());
    }

    public function getPendingTokens(Request $request)
    {
        return $this->service->getPendingTokens($request);
    }
}
