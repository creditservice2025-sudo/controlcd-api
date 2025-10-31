<?php

namespace App\Http\Controllers;

use App\Services\ExpenseService;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    protected $expenseService;

    public function __construct(ExpenseService $expenseService)
    {
        $this->expenseService = $expenseService;
        /* $this->middleware('permission:ver_egresos')->only('index');
        $this->middleware('permission:crear_egresos')->only('store');
        $this->middleware('permission:editar_egresos')->only('update');
        $this->middleware('permission:eliminar_egresos')->only('destroy'); */
    }

    public function index(Request $request)
    {
        $companyId = $request->input('company_id');
        return $this->expenseService->index(
            $request,
            $request->query('search', ''),
            $request->query('perpage', 10),
            $request->query('orderBy', 'created_at'),
            $request->query('orderDirection', 'desc'),
            $companyId
        );
    }

    public function store(Request $request)
    {
        return $this->expenseService->create($request);
    }

    public function show($id)
    {
        return $this->expenseService->show($id);
    }

    public function update(Request $request, $id)
    {
        return $this->expenseService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->expenseService->delete($id);
    }

    public function changeStatus(Request $request, $expenseId, $status)
    {
        $validStatuses = ['Aprobado', 'Rechazado'];

        if (!in_array($status, $validStatuses)) {
            return response()->json(['error' => 'Acción no válida'], 400);
        }

        return $this->expenseService->changeExpenseStatus(
            $expenseId,
            $status === 'Aprobado' ? 'Aprobado' : 'Rechazado'
        );
    }

    public function summary()
    {
        return $this->expenseService->getExpenseSummary();
    }

    public function monthlyReport()
    {
        return $this->expenseService->getMonthlyExpenseReport();
    }

    public function getSellerExpensesByDate(Request $request, int $sellerId)
    {
        try {
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            $companyId = $request->input('company_id');
            return $this->expenseService->getSellerExpensesByDate($sellerId, $request, $perPage, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
