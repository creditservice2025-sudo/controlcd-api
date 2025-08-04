<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Expense;
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
                'user_id' => 'nullable|numeric' 
            ]);
    
            $user = Auth::user();
            $isAdmin = in_array($user->role_id, [1, 2]);
    
            $userId = $isAdmin && $request->has('user_id') 
                     ? $validated['user_id'] 
                     : $user->id;
    
            $status = $isAdmin ? 'Aprobado' : 'Pendiente';
    
            $expense = Expense::create([
                'value' => $validated['value'],
                'description' => $validated['description'],
                'user_id' => $userId,
                'category_id' => $validated['category_id'],
                'status' => $status,
            ]);
    
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
        string $search,
        int $perpage,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $user = Auth::user();

            $expensesQuery = Expense::with(['user', 'category'])
                ->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });

            if ($user->role_id == 5) {
                $expensesQuery->where('user_id', $user->id);
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
    public function getSellerExpensesByDate(int $sellerId, Request $request)
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

        $expensesQuery = Expense::with(['user', 'category'])
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

        $expenses = $expensesQuery->get();

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
