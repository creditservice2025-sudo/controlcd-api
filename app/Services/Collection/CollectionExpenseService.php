<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionPayment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CollectionExpenseService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function index($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $date = $request->query('date', Carbon::now()->toDateString());
        
        $query = CollectionExpense::where('company_id', $companyId);
        
        // If not admin, only see own expenses
        if (Auth::user()->role_id !== 1 && Auth::user()->role_id !== 2) {
            $query->where('user_id', Auth::id());
        }

        if ($date) {
            $query->whereDate('recorded_at', $date);
        }

        $expenses = $query->orderBy('recorded_at', 'desc')->get();

        // Calculate summary for the day
        $totalExpenses = $expenses->where('status', 'approved')->sum('amount');
        
        // Calculate total collections for the same day/user
        $collectionsQuery = CollectionPayment::where('company_id', $companyId)
            ->whereDate('recorded_at', $date);
            
        if (Auth::user()->role_id !== 1 && Auth::user()->role_id !== 2) {
            $collectionsQuery->where('user_id', Auth::id());
        }
        
        $totalCollections = $collectionsQuery->sum('amount_paid');

        return $this->successResponse([
            'expenses' => $expenses,
            'summary' => [
                'total_expenses' => (float) $totalExpenses,
                'total_collections' => (float) $totalCollections,
                'net_balance' => (float) ($totalCollections - $totalExpenses),
            ]
        ]);
    }

    public function create($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'category' => 'required|string|max:50',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'evidence' => 'nullable|image|max:5120', // 5MB max
        ]);

        $metadata = [];
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store("collection/expenses/evidence/{$companyId}", 'public');
            $metadata['evidence_path'] = $path;
        }

        $expense = CollectionExpense::create([
            'company_id' => $companyId,
            'user_id' => Auth::id(),
            'amount' => $validated['amount'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'status' => 'approved',
            'recorded_at' => Carbon::now(),
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'metadata' => $metadata,
        ]);

        // Sync with Centralized Wallet
        app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
            'company_id' => $companyId,
            'currency' => $request->currency ?? 'COP',
            'country_code' => $request->country_code ?? 'CO',
            'amount' => $validated['amount'],
            'type' => 'debit', // Expense/Outflow
            'action_type' => 'expense',
            'reference_type' => 'expense',
            'reference_id' => $expense->id,
            'description' => $validated['description'] ?? "Gasto: {$validated['category']}",
        ]);

        return $this->successCreatedResponse([
            'success' => true,
            'message' => 'Gasto registrado correctamente',
            'data' => $expense
        ]);
    }

    private function resolveCompanyId($requestedId)
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        if (!$user) return null;
        return $user->company->id ?? $user->seller->company_id ?? null;
    }
}
