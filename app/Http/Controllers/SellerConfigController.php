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
}
