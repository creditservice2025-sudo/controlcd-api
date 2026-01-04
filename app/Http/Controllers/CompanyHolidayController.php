<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyHolidaySeller;
use App\Models\Holiday;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CompanyHolidayController extends Controller
{
    use ApiResponse;

    /**
     * Obtener todos los feriados configurados para una empresa
     * GET /api/companies/{companyId}/holidays
     */
    public function index($companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $holidays = CompanyHolidaySeller::where('company_id', $companyId)
                ->with(['holiday', 'seller.user'])
                ->get()
                ->groupBy('holiday_id')
                ->map(function ($assignments) {
                    $holiday = $assignments->first()->holiday;
                    $sellers = $assignments->map(function ($assignment) {
                        return $assignment->seller_id ? [
                            'id' => $assignment->seller->id,
                            'name' => $assignment->seller->user->name ?? 'N/A',
                        ] : null;
                    })->filter()->values();

                    return [
                        'holiday_id' => $holiday->id,
                        'date' => $holiday->date,
                        'description' => $holiday->description,
                        'country_id' => $holiday->country_id,
                        'all_sellers' => $assignments->first()->seller_id === null,
                        'sellers' => $sellers,
                    ];
                })
                ->values();

            return $this->successResponse([
                'success' => true,
                'message' => 'Feriados configurados obtenidos exitosamente',
                'data' => $holidays
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Guardar/actualizar configuración de feriados para una empresa
     * POST /api/companies/{companyId}/holidays
     * 
     * Body esperado:
     * {
     *   "holidays": [
     *     {
     *       "holiday_id": 1,
     *       "mode": "all" // o "specific"
     *       "seller_ids": [1, 2, 3] // solo si mode === "specific"
     *     }
     *   ]
     * }
     */
    public function store(Request $request, $companyId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'holidays' => 'required|array',
                'holidays.*.holiday_id' => 'required|exists:holidays,id',
                'holidays.*.mode' => 'required|in:all,specific',
                'holidays.*.seller_ids' => 'nullable|array',
                'holidays.*.seller_ids.*' => 'exists:sellers,id',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->first(), 422);
            }

            $company = Company::findOrFail($companyId);

            DB::beginTransaction();

            // Eliminar configuraciones anteriores
            CompanyHolidaySeller::where('company_id', $companyId)->delete();

            // Insertar nuevas configuraciones
            foreach ($request->holidays as $holidayConfig) {
                $holidayId = $holidayConfig['holiday_id'];
                $mode = $holidayConfig['mode'];

                if ($mode === 'all') {
                    // Todos los vendedores (seller_id = null)
                    CompanyHolidaySeller::create([
                        'company_id' => $companyId,
                        'holiday_id' => $holidayId,
                        'seller_id' => null,
                    ]);
                } else {
                    // Vendedores específicos
                    $sellerIds = $holidayConfig['seller_ids'] ?? [];
                    
                    foreach ($sellerIds as $sellerId) {
                        // Verificar que el vendedor pertenece a la empresa
                        $seller = $company->sellers()->find($sellerId);
                        if ($seller) {
                            CompanyHolidaySeller::create([
                                'company_id' => $companyId,
                                'holiday_id' => $holidayId,
                                'seller_id' => $sellerId,
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Configuración de feriados guardada exitosamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Eliminar toda la configuración de feriados de una empresa
     * DELETE /api/companies/{companyId}/holidays
     */
    public function destroy($companyId)
    {
        try {
            CompanyHolidaySeller::where('company_id', $companyId)->delete();

            return $this->successResponse([
                'success' => true,
                'message' => 'Configuración de feriados eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Obtener vendedores asignados a un feriado específico de una empresa
     * GET /api/companies/{companyId}/holidays/{holidayId}/sellers
     */
    public function getSellersForHoliday($companyId, $holidayId)
    {
        try {
            $assignments = CompanyHolidaySeller::where('company_id', $companyId)
                ->where('holiday_id', $holidayId)
                ->with('seller.user')
                ->get();

            $allSellers = $assignments->first() && $assignments->first()->seller_id === null;

            $sellers = $assignments->where('seller_id', '!=', null)->map(function ($assignment) {
                return [
                    'id' => $assignment->seller->id,
                    'name' => $assignment->seller->user->name ?? 'N/A',
                ];
            })->values();

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'all_sellers' => $allSellers,
                    'sellers' => $sellers,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
