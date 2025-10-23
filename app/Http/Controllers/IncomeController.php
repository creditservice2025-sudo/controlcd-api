<?php

namespace App\Http\Controllers;

use App\Services\IncomeService;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    protected $incomeService;

    public function __construct(IncomeService $incomeService)
    {
        $this->incomeService = $incomeService;
    }

    public function index(Request $request)
    {
        $companyId = $request->input('company_id');
        return $this->incomeService->index(
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
        return $this->incomeService->create($request);
    }

    public function show($id)
    {
        return $this->incomeService->show($id);
    }

    public function update(Request $request, $id)
    {
        return $this->incomeService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->incomeService->delete($id);
    }

    public function summary()
    {
        return $this->incomeService->getIncomeSummary();
    }

    public function monthlyReport()
    {
        return $this->incomeService->getMonthlyIncomeReport();
    }

    public function getSellerIncomeByDate(Request $request, int $sellerId)
    {
        try {
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            $companyId = $request->input('company_id');
            return $this->incomeService->getSellerIncomeByDate($sellerId, $request, $perPage, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
