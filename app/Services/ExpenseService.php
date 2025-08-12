<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Traits\ApiResponse;
use App\Models\Expense;
use App\Models\ExpenseImage;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseService
{
    use ApiResponse;

    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'value' => 'required|numeric|min:0',
                'description' => 'required|string',
                'category_id' => 'required|numeric',
                'user_id' => 'nullable|numeric',
                'image' => 'nullable|image|max:2048',
            ]);

            $user = Auth::user();
            $isAdmin = in_array($user->role_id, [1, 2]);

            $userId = $isAdmin && $request->has('user_id')
                ? $validated['user_id']
                : $user->id;


            $expense = Expense::create([
                'value' => $validated['value'],
                'description' => $validated['description'],
                'user_id' => $userId,
                'category_id' => $validated['category_id'],
                'status' => 'Aprobado',
            ]);

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');

                $imagePath = Helper::uploadFile($imageFile, 'expenses');

                ExpenseImage::create([
                    'expense_id' => $expense->id,
                    'user_id' => $userId,
                    'path' => $imagePath
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Gasto creado con éxito',
                'data' => $expense,
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el gasto', 500);
        }
    }

    public function update(Request $request, $expenseId)
    {
        try {
            $expense = Expense::find($expenseId);
            if (!$expense) {
                return $this->errorNotFoundResponse('Gasto no encontrado');
            }

            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $expense->user_id)->first();

            // Verificar si existe liquidación aprobada para la fecha del gasto
            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $expense->created_at->format('Y-m-d'))
                ->first();

            if ($liquidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede editar el gasto porque ya existe una liquidación aprobada para esta fecha'
                ], 422);
            }

            $validated = $request->validate([
                'category_id' => 'required|numeric',
                'value' => 'required|numeric|min:0',
                'description' => 'required|string',
            ]);

            $expense->update($validated);

            return $this->successResponse([
                'success' => true,
                'message' => 'Gasto actualizado con éxito',
                'data' => $expense
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el gasto', 500);
        }
    }

    public function delete($expenseId)
    {
        try {
            $expense = Expense::find($expenseId);
            if (!$expense) {
                return $this->errorNotFoundResponse('Gasto no encontrado');
            }
            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $expense->user_id)->first();

            // Verificar si existe liquidación aprobada para la fecha del gasto
            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $expense->created_at->format('Y-m-d'))
                ->first();



            if ($liquidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el gasto porque ya existe una liquidación aprobada para esta fecha'
                ], 422);
            }

            $expense->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Gasto eliminado con éxito",
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar el gasto', 500);
        }
    }

    public function changeExpenseStatus($expenseId, $status)
    {
        try {
            $validStatuses = ['Aprobado', 'Rechazado'];

            if (!in_array($status, $validStatuses)) {
                return $this->errorResponse('Estado no válido', 400);
            }

            $expense = Expense::find($expenseId);
            if (!$expense) {
                return $this->errorNotFoundResponse('Gasto no encontrado');
            }

            $user = Auth::user();

            if (!in_array($user->role_id, [1, 2])) {
                return $this->errorResponse("No tienes permisos para {$status} gastos", 403);
            }

            $expense->update([
                'status' => $status,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $actionMessage = $status === 'approved' ? 'aprobado' : 'rechazado';

            return $this->successResponse([
                'success' => true,
                'message' => "Gasto {$actionMessage} con éxito",
                'data' => $expense
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $actionMessage = $status === 'approved' ? 'aprobar' : 'rechazar';
            return $this->errorResponse("Error al {$actionMessage} el gasto", 500);
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

            $expensesQuery = Expense::with(['user', 'category', 'images'])
                ->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });

            // Filtrar por vendedor si se proporciona seller_id
            if ($request->has('seller_id') && $request->seller_id) {
                $expensesQuery->where('user_id', $request->seller_id);
            }
            // Para usuarios vendedores (role 5), mostrar solo sus gastos
            else if ($user->role_id == 5) {
                $expensesQuery->where('user_id', $user->id);
            }
            // Para administradores sin filtro, mostrar todos los gastos
            else {
                // No se aplica filtro adicional
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $expensesQuery->orderBy($orderBy, $orderDirection);

            $expenses = $expensesQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Gastos encontrados',
                'data' => $expenses
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los gastos', 500);
        }
    }

    public function show($expenseId)
    {
        try {
            $expense = Expense::with(['user'])->find($expenseId);

            if (!$expense) {
                return $this->errorNotFoundResponse('Gasto no encontrado');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Gasto encontrado',
                'data' => $expense
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el gasto', 500);
        }
    }

    public function getExpenseSummary()
    {
        try {
            $user = Auth::user();

            $query = Expense::query();

            $totalExpenses = $query->sum('value');
            $expenseCount = $query->count();
            $averageExpense = $expenseCount > 0 ? $totalExpenses / $expenseCount : 0;

            $recentExpenses = $query->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['description', 'value', 'created_at']);

            return $this->successResponse([
                'success' => true,
                'message' => 'Resumen de gastos',
                'data' => [
                    'total_expenses' => $totalExpenses,
                    'expense_count' => $expenseCount,
                    'average_expense' => round($averageExpense, 2),
                    'recent_expenses' => $recentExpenses
                ]
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el resumen de gastos', 500);
        }
    }

    public function getExpensesByUser($userId)
    {
        try {
            $expenses = Expense::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Gastos por usuario',
                'data' => $expenses
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener gastos por usuario', 500);
        }
    }

    public function getMonthlyExpenseReport()
    {
        try {
            $user = Auth::user();

            $query = Expense::select(
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
                'message' => 'Reporte mensual de gastos',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al generar reporte mensual', 500);
        }
    }
    public function getSellerExpensesByDate(int $sellerId, Request $request, int $perpage)
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

            $expensesQuery = Expense::with(['user', 'category', 'images'])
                ->where('user_id', $sellerUserId);

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
                $expensesQuery->whereBetween('created_at', [$startDate, $endDate]);
            } elseif ($request->has('date')) {
                $filterDate = Carbon::parse($request->get('date'))->toDateString();
                $expensesQuery->whereDate('created_at', $filterDate);
            } else {
                $expensesQuery->whereDate('created_at', Carbon::today()->toDateString());
            }

            $expenses = $expensesQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Gastos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $expenses
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los gastos del vendedor: ' . $e->getMessage(), 500);
        }
    }
}
