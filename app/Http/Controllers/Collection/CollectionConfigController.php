<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionConfigService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionConfigController extends Controller
{
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionConfigService $configService)
    {
    }

    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $config = $this->configService->getConfig($companyId);
        $available = $this->configService->getAvailableCurrencies();

        return response()->json([
            'config' => $config,
            'available_currencies' => $available,
        ]);
    }

    public function updateCurrencies(Request $request)
    {
        // Solo admin (role 1 o 2)
        $user = Auth::user();
        if (!in_array((int) $user->role_id, [1, 2])) {
            return response()->json(['message' => 'Solo administradores pueden modificar la configuracion'], 403);
        }

        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'currencies' => 'required|array|min:1',
            'currencies.*.currency' => 'required|string|max:10',
            'currencies.*.country_code' => 'required|string|max:5',
            'default_currency' => 'required|string|max:10',
            'default_country_code' => 'required|string|max:5',
        ]);

        return $this->configService->updateCurrencies(
            $companyId,
            $request->input('currencies'),
            $request->input('default_currency'),
            $request->input('default_country_code')
        );
    }
}
