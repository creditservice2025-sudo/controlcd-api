<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Models\IncomeImage;
use App\Traits\ApiResponse;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IncomeService
{
    use ApiResponse;

    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'value' => 'required|numeric|min:0',
                'description' => 'required|string',
                'user_id' => 'nullable|numeric',
                'image' => 'nullable|image|max:2048',
                'created_at' => 'nullable|date',
            ]);

            $user = Auth::user();
            $isAdmin = in_array($user->role_id, [1, 2]);

            $userId = $isAdmin && $request->has('user_id')
                ? $validated['user_id']
                : $user->id;

            $incomeData = [
                'value' => $validated['value'],
                'description' => $validated['description'],
                'user_id' => $userId,
            ];

            if ($request->has('created_at')) {
                $incomeData['created_at'] = $validated['created_at'];
            }

            $income = Income::create($incomeData);


            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');

                $imagePath = Helper::uploadFile($imageFile, 'incomes');

                IncomeImage::create([
                    'income_id' => $income->id,
                    'user_id' => $userId,
                    'path' => $imagePath
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingreso creado con éxito',
                'data' => $income,
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el ingreso', 500);
        }
    }

    public function update(Request $request, $incomeId)
    {
        try {
            $income = Income::find($incomeId);
            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $income->user_id)->first();

            if (!$seller) {
                return $this->errorResponse('No se encontró el vendedor asociado a este ingreso', 422);
            }

            // Verificar si existe liquidación aprobada para la fecha del ingreso y este vendedor
            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $income->created_at->format('Y-m-d'))
                ->first();

            if ($liquidation) {
                return $this->errorResponse(
                    'No se puede editar el ingreso porque ya existe una liquidación aprobada para esta fecha',
                    422
                );
            }

            $validated = $request->validate([
                'value' => 'required|numeric|min:0',
                'description' => 'required|string',
            ]);

            $income->update($validated);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingreso actualizado con éxito',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el ingreso', 500);
        }
    }

    public function delete($incomeId)
    {
        try {
            $user = Auth::user();

            $income = Income::find($incomeId);
            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $income->user_id)->first();

            if (!$seller) {
                return $this->errorResponse('No se encontró el vendedor asociado a este ingreso', 422);
            }

            // Verificar si existe liquidación aprobada para la fecha del ingreso y este vendedor
            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $income->created_at->format('Y-m-d'))
                ->first();

            if ($liquidation && $user->role_id !== 1) {
                return $this->errorResponse(
                    'No se puede eliminar el ingreso porque ya existe una liquidación aprobada para esta fecha',
                    422
                );
            }

            $income->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Ingreso eliminado con éxito",
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar el ingreso', 500);
        }
    }


    public function index(
        Request $request,
        string $search,
        int $perpage,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $incomeQuery = Income::with(['user', 'images'])
                ->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });

            if ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Ingresos encontrados',
                        'data' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perpage)
                    ]);
                }

                $companyId = $user->company->id;
                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');

                $incomeQuery->whereIn('user_id', $userIds);
            } elseif ($role === 5) {
                $timezone = 'America/Caracas';
                $today = Carbon::now($timezone)->startOfDay();
                $todayEnd = Carbon::now($timezone)->endOfDay();
                $incomeQuery->whereBetween('created_at', [
                    $today->copy()->timezone('UTC'),
                    $todayEnd->copy()->timezone('UTC')
                ]);
            }

            if ($request->has('seller_id') && $request->seller_id) {
                $incomeQuery->where('user_id', $request->seller_id);
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $incomeQuery->orderBy($orderBy, $orderDirection);

            $income = $incomeQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos encontrados',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los ingresos', 500);
        }
    }

    public function show($expenseId)
    {
        try {
            $income = Income::with(['user'])->find($expenseId);

            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingreso encontrado',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el gasto', 500);
        }
    }

    public function getIncomeSummary()
    {
        try {
            $user = Auth::user();

            $query = Income::query();

            $totalIncome = $query->sum('value');
            $incomeCount = $query->count();
            $averageIncome = $incomeCount > 0 ? $totalIncome / $incomeCount : 0;

            $recentIncome = $query->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['description', 'value', 'created_at']);

            return $this->successResponse([
                'success' => true,
                'message' => 'Resumen de ingresos',
                'data' => [
                    'total_expenses' => $totalIncome,
                    'expense_count' => $incomeCount,
                    'average_expense' => round($averageIncome, 2),
                    'recent_expenses' => $recentIncome
                ]
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el resumen de ingresos', 500);
        }
    }

    public function getIncomeByUser($userId)
    {
        try {
            $income = Income::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos por usuario',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener ingresos por usuario', 500);
        }
    }

    public function getMonthlyIncomeReport()
    {
        try {
            $user = Auth::user();

            $query = Income::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(value) as total'),
                DB::raw('COUNT(*) as count')
            )
                ->groupBy('year', 'month')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc');

            $report = $query->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Reporte mensual de ingresos',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al generar reporte mensual', 500);
        }
    }
    public function getSellerIncomeByDate(int $sellerId, Request $request, int $perpage)
    {
        try {
            $sellerUserId = Seller::where('id', $sellerId)->value('user_id');

            if (!$sellerUserId) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'No se encontró el usuario asociado a este ID de vendedor.',
                    'data' => []
                ]);
            }

            $incomeQuery = Income::with(['user', 'images'])
                ->where('user_id', $sellerUserId);

                 $timezone = 'America/Caracas';

             if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            
            // Convertir fechas al huso horario de Caracas
            $start = Carbon::createFromFormat('Y-m-d', $startDate, $timezone)
                ->startOfDay()
                ->timezone('UTC');
            $end = Carbon::createFromFormat('Y-m-d', $endDate, $timezone)
                ->endOfDay()
                ->timezone('UTC');
                
            $incomeQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($request->has('date')) {
            $filterDate = $request->get('date');
            
            // Convertir fecha al huso horario de Caracas
            $start = Carbon::createFromFormat('Y-m-d', $filterDate, $timezone)
                ->startOfDay()
                ->timezone('UTC');
            $end = Carbon::createFromFormat('Y-m-d', $filterDate, $timezone)
                ->endOfDay()
                ->timezone('UTC');
                
            $incomeQuery->whereBetween('created_at', [$start, $end]);
        }


            $income = $incomeQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los ingresos del vendedor: ' . $e->getMessage(), 500);
        }
    }
}
