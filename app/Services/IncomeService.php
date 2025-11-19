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
                'value' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'user_id' => 'nullable|numeric',
                'image' => 'nullable|image|max:2048',
                'created_at' => 'nullable|date',
                'timezone' => 'nullable|string',
            ]);

            $user = Auth::user();
            $isAdmin = in_array($user->role_id, [1, 2]);

            $userId = $isAdmin && $request->has('user_id')
                ? $validated['user_id']
                : $user->id;

            if (isset($validated['timezone']) && !empty($validated['timezone'])) {
                $createdAt = Carbon::now($validated['timezone']);
                $updatedAt = Carbon::now($validated['timezone']);
                unset($validated['timezone']);
            } else {
                $createdAt = null;
                $updatedAt = null;
            }

            $incomeData = [
                'value' => $validated['value'],
                'description' => $validated['description'],
                'user_id' => $userId,
                'created_at' => $createdAt ?? ($request->has('created_at') ? $validated['created_at'] : null),
                'updated_at' => $updatedAt ?? null
            ];

            $income = Income::create($incomeData);

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');
                $imagePath = Helper::uploadFile($imageFile, 'incomes');
                IncomeImage::create([
                    'income_id' => $income->id,
                    'user_id' => $userId,
                    'path' => $imagePath,
                    'created_at' => $createdAt ?? null,
                    'updated_at' => $updatedAt ?? null
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

        // Logging para depuración (opcional)
        // Log::info('Income update request all', $request->all());
        // Log::info('Income update files', $request->files->all());

        // Reglas base
        $rules = [
            'value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'timezone' => 'nullable|string',
            'user_id' => 'nullable|numeric',
            // 'image' solo si se sube archivo
        ];

        if ($request->hasFile('image')) {
            $rules['image'] = 'image|max:2048';
        }

        // Opcional: allow remove_image flag (boolean)
        $rules['remove_image'] = 'nullable|boolean';

        $validated = $request->validate($rules);

        // Buscar seller como antes (tu lógica)
        $seller = null;
        if ($income->user_id) {
            $seller = Seller::where('user_id', $income->user_id)->first();
        }

        if (!$seller) {
            $authUser = Auth::user();
            if ($authUser) {
                $seller = Seller::where('user_id', $authUser->id)->first();
            }
        }

        if ($seller && $income->created_at) {
            $incomeDate = $income->created_at instanceof Carbon
                ? $income->created_at->toDateString()
                : Carbon::parse($income->created_at)->toDateString();

            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $incomeDate)
                ->first();

            if ($liquidation) {
                return $this->errorResponse(
                    'No se puede editar el ingreso porque ya existe una liquidación aprobada para esta fecha',
                    422
                );
            }
        }

        // Manejo timezone => set updated_at
        if (isset($validated['timezone']) && !empty($validated['timezone'])) {
            $validated['updated_at'] = Carbon::now($validated['timezone']);
            unset($validated['timezone']);
        }

        // Preparar datos para update (solo los campos permitidos)
        $updateData = [];
        if (array_key_exists('value', $validated) && $validated['value'] !== null) {
            $updateData['value'] = $validated['value'];
        }
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }
        if (array_key_exists('user_id', $validated) && $validated['user_id']) {
            $updateData['user_id'] = $validated['user_id'];
        }
        if (array_key_exists('updated_at', $validated)) {
            $updateData['updated_at'] = $validated['updated_at'];
        }

        // Actualizar income
        if (!empty($updateData)) {
            $income->update($updateData);
        }

        // === Manejo de imagen ===
        // 1) Si viene archivo nuevo: subir, crear registro image y eliminar anterior
        if ($request->hasFile('image')) {
            $imageFile = $request->file('image');
            $imagePath = Helper::uploadFile($imageFile, 'incomes'); // tu helper

            // Buscar imagen previa (ajusta según relación: income->images())
            $oldImage = IncomeImage::where('income_id', $income->id)->first();

            // Crear nuevo registro
            $newImage = IncomeImage::create([
                'income_id' => $income->id,
                'user_id' => $income->user_id ?? Auth::id(),
                'path' => $imagePath,
                'created_at' => $updateData['updated_at'] ?? now(),
                'updated_at' => $updateData['updated_at'] ?? now(),
            ]);

            // Borrar archivo y registro anterior si existía
            if ($oldImage) {
                try {
                    // Si tienes Helper::deleteFile
                    if (function_exists('Helper') && method_exists(Helper::class, 'deleteFile')) {
                        Helper::deleteFile($oldImage->path);
                    } else {
                        // fallback usando Storage (ajusta disco si lo necesitas)
                        \Illuminate\Support\Facades\Storage::delete($oldImage->path);
                    }
                } catch (\Exception $ex) {
                    Log::warning("No se pudo borrar archivo antiguo: " . $ex->getMessage());
                }

                // eliminar registro antiguo (si no quieres mantener histórico)
                $oldImage->delete();
            }
        } else if (!empty($validated['remove_image']) && $validated['remove_image']) {
            // 2) Si viene flag remove_image = true: borrar imagen existente y registro
            $oldImage = IncomeImage::where('income_id', $income->id)->first();
            if ($oldImage) {
                try {
                    if (function_exists('Helper') && method_exists(Helper::class, 'deleteFile')) {
                        Helper::deleteFile($oldImage->path);
                    } else {
                        \Illuminate\Support\Facades\Storage::delete($oldImage->path);
                    }
                } catch (\Exception $ex) {
                    Log::warning("No se pudo borrar archivo antiguo: " . $ex->getMessage());
                }
                $oldImage->delete();
            }
        }

        // Refrescar modelo para devolver data actualizada
        $income->refresh();

        return $this->successResponse([
            'success' => true,
            'message' => 'Ingreso actualizado con éxito',
            'data' => $income
        ]);
    } catch (\Exception $e) {
        Log::error('Error actualizando ingreso: ' . $e->getMessage(), ['exception' => $e]);
        return $this->errorResponse('Error al actualizar el ingreso', 500);
    }
}

    public function delete($incomeId, Request $request = null)
    {
        try {
            $user = Auth::user();

            $income = Income::find($incomeId);
            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            // Solo permitir eliminar ingresos del día actual
            $timezone = 'America/Lima';

            $incomeDate = Carbon::parse($income->created_at)->timezone($timezone)->format('Y-m-d');
            $currentDate = Carbon::now($timezone)->format('Y-m-d');

            if ($incomeDate !== $currentDate) {
                return $this->errorResponse(
                    'Solo se pueden eliminar ingresos creados el día de hoy',
                    422
                );
            }

            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $income->user_id)->first();

            if (!$seller) {
                return $this->errorResponse('No se encontró el vendedor asociado a este ingreso', 422);
            }

            // Verificar si existe liquidación aprobada para la fecha del ingreso y este vendedor
            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $incomeDate)
                ->first();

            if ($liquidation) {
                return $this->errorResponse(
                    'No se puede eliminar el ingreso porque ya existe una liquidación aprobada para esta fecha',
                    422
                );
            }

            $timezone = $request && $request->has('timezone') ? $request->get('timezone') : null;
            if ($timezone) {
                $income->deleted_at = Carbon::now($timezone);
                $income->save();
                $income->delete();
            } else {
                $income->delete();
            }

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
        string $orderDirection = 'desc',
        $companyId = null
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

            if ($companyId) {
                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');
                $incomeQuery->whereIn('user_id', $userIds);
            } else if ($role === 2) {
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
            } else if ($role === 5) {
                $timezone = 'America/Lima';
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

            if ($request->has('start_date') && $request->has('end_date')) {
                $timezone = $request->input('timezone', 'America/Lima');
                $start = Carbon::parse($request->start_date, $timezone)->startOfDay()->timezone('UTC');
                $end = Carbon::parse($request->end_date, $timezone)->endOfDay()->timezone('UTC');
                $incomeQuery->whereBetween('created_at', [$start, $end]);
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
    public function getSellerIncomeByDate(int $sellerId, Request $request, int $perpage, $companyId = null)
    {
        try {
            $sellerUserId = Seller::where('id', $sellerId)
                ->when($companyId, function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })
                ->value('user_id');

            if (!$sellerUserId) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'No se encontró el usuario asociado a este ID de vendedor.',
                    'data' => []
                ]);
            }

            $incomeQuery = Income::with(['user', 'images'])
                ->where('user_id', $sellerUserId);

            $timezone = $request->input('timezone', 'America/Lima');

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = $request->get('start_date');
                $endDate = $request->get('end_date');

                $start = Carbon::parse($startDate, $timezone)
                    ->startOfDay()
                    ->timezone('UTC');
                $end = Carbon::parse($endDate, $timezone)
                    ->endOfDay()
                    ->timezone('UTC');

                $incomeQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($request->has('date')) {
                $filterDate = $request->get('date');

                $start = Carbon::parse($filterDate, $timezone)
                    ->startOfDay()
                    ->timezone('UTC');
                $end = Carbon::parse($filterDate, $timezone)
                    ->endOfDay()
                    ->timezone('UTC');

                $incomeQuery->whereBetween('created_at', [$start, $end]);
            } else {
                $todayStart = Carbon::now($timezone)->startOfDay()->timezone('UTC');
                $todayEnd = Carbon::now($timezone)->endOfDay()->timezone('UTC');
                $incomeQuery->whereBetween('created_at', [$todayStart, $todayEnd]);
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
