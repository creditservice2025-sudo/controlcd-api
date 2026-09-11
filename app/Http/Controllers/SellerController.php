<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Seller\SellerRequest;
use App\Services\SellerService;
use App\Http\Middleware\RouteAuthMiddleware;

class SellerController extends Controller
{

    use ApiResponse;

    protected $sellerService;

    public function __construct(SellerService $sellerService)
    {
        $this->sellerService = $sellerService;
        /*  $this->middleware('permission:ver_vendedores')->only('index');
         $this->middleware('permission:crear_vendedores')->only('create');
         $this->middleware('permission:editar_vendedores')->only('update');
         $this->middleware('permission:eliminar_vendedores')->only('delete'); */
    }

    public function create(SellerRequest $request)
    {
        try {
            return $this->sellerService->create($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(SellerRequest $request, $sellerId)
    {
        \App\Support\Tenant::assertSellerInScope($sellerId);
        try {
            if (!is_numeric($sellerId)) {
                $seller = \App\Models\Seller::where('uuid', $sellerId)->firstOrFail();
                $sellerId = $seller->id;
            }
            return $this->sellerService->update($sellerId, $request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function listActiveRoutes(Request $request)
    {
        try {
            $hasLiquidation = $request->get('hasLiquidation');
            $search = $request->get('search');
            $countryId = $request->get('country_id');
            $cityId = $request->get('city_id');
            $sellerId = $request->get('seller_id');
            $companyId = $request->get('company_id');
            return $this->sellerService->listActiveRoutes($hasLiquidation, $search, $countryId, $cityId, $sellerId, $companyId, $request);
        } catch (\Exception $e) {
            \Log::error('Error listing active routes: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($routeId)
    {
        \App\Support\Tenant::assertSellerInScope($routeId);
        try {
            if (!is_numeric($routeId)) {
                $seller = \App\Models\Seller::where('uuid', $routeId)->firstOrFail();
                $routeId = $seller->id;
            }
            return $this->sellerService->delete($routeId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            $countryId = $request->input('country_id');
            $cityId = $request->input('city_id');
            $companyId = $request->input('company_id');
            return $this->sellerService->getRoutes($page, $perPage, $search, $countryId, $cityId, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getRoutesSelect()
    {
        try {
            return $this->sellerService->getRoutesSelect();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleStatus(Request $request, $routeId)
    {
        \App\Support\Tenant::assertSellerInScope($routeId);
        if (!is_numeric($routeId)) {
            $seller = \App\Models\Seller::where('uuid', $routeId)->firstOrFail();
            $routeId = $seller->id;
        }
        $status = $request->input('status');
        return $this->sellerService->toggleStatus($routeId, $status);
    }

    /**
     * Get seller cash info (current and previous cash)
     */
    public function getCashInfo(Request $request, $sellerId)
    {
        \App\Support\Tenant::assertSellerInScope($sellerId);
        try {
            return $this->sellerService->getCashInfo($sellerId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Guarda SOLO el teléfono del vendedor.
     *
     * Existe aparte de update() para que cargarlo no exija abrir la ficha
     * completa ni pasar por SellerRequest, que valida todo el alta: desde el
     * reporte se agrega el número en el momento en que hace falta, sin salir de
     * la pantalla ni arrastrar el resto del formulario.
     *
     * Se guarda el número INTERNACIONAL completo: prefijo de país + línea, solo
     * dígitos. Antes se guardaba únicamente la parte local y el prefijo se
     * agregaba al mostrar, tomándolo del país de la ruta; el resultado es que un
     * vendedor con línea de otro país no se podía registrar —se elegía el
     * prefijo, el envío salía bien, y al recargar volvía al de la ruta—.
     *
     * El código de país es parte del número, no de la ruta donde trabaja quien
     * lo usa.
     */
    public function updatePhone(Request $request, $sellerId)
    {
        \App\Support\Tenant::assertSellerInScope($sellerId);

        $validated = $request->validate([
            // Sin '+' ni espacios ni guiones: el enlace de wa.me los rechaza, y
            // limpiarlos en el front dejaría guardado algo distinto de lo que se
            // ve en pantalla. El piso sube a 8 porque ahora incluye el prefijo.
            'phone' => ['required', 'string', 'regex:/^[0-9]{8,15}$/'],
        ], [
            'phone.regex' => 'El número con prefijo de país debe tener entre 8 y 15 dígitos, sin espacios ni símbolos.',
        ]);

        try {
            $seller = \App\Models\Seller::whereNull('deleted_at')->find($sellerId);
            if (!$seller || !$seller->user_id) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }

            \App\Models\User::where('id', $seller->user_id)
                ->update(['phone' => $validated['phone']]);

            return response()->json([
                'success' => true,
                'message' => 'Teléfono guardado',
                'data' => ['seller_id' => (int) $sellerId, 'phone' => $validated['phone']],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get seller liquidations
     */
    public function getLiquidations(Request $request, $sellerId)
    {
        \App\Support\Tenant::assertSellerInScope($sellerId);
        try {
            $limit = $request->get('limit', 10);
            return $this->sellerService->getLiquidations($sellerId, $limit);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Resumen de cartera del vendedor (activa + irrecuperable). Calculado
     * desde credits + payments (no usa credits.remaining_amount).
     */
    public function getPortfolioSummary(Request $request, $sellerId)
    {
        \App\Support\Tenant::assertSellerInScope($sellerId);
        try {
            return $this->sellerService->getPortfolioSummary($sellerId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
