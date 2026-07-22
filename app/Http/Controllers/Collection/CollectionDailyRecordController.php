<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionDailyRecordService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionDailyRecordController extends Controller
{
    use ResolvesCollectionCompany;

    protected $service;

    public function __construct(CollectionDailyRecordService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->index($request);
    }

    public function store(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->create($request);
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->destroy($request, $id);
    }

    /**
     * Editar un movimiento por equivocación (con observación obligatoria y
     * auditoría del monto anterior→actual). Solo ingreso/gasto del día abierto.
     */
    public function update(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->update($request, $id);
    }

    /**
     * Historial de ajustes (trazabilidad) de un movimiento.
     */
    public function auditHistory(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->auditHistory($request, $id);
    }

    /**
     * Tendencia de movimientos día por día en un rango (para reporte Excel).
     */
    public function trend(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $tz = $request->query('timezone') ?: 'America/Bogota';
        $today = \Carbon\Carbon::now($tz)->toDateString();
        $from = $request->query('from', \Carbon\Carbon::now($tz)->startOfMonth()->toDateString());
        $to = $request->query('to', $today);
        $countryCode = $request->query('country_code');

        return $this->service->getTrend($companyId, $from, $to, $tz, $countryCode);
    }

    /**
     * Resumen del flujo de caja por período (diario/semanal/quincenal/mensual/
     * anual) con saldo acumulado que arrastra de un período al siguiente.
     */
    public function periodSummary(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $tz = $request->query('timezone') ?: 'America/Bogota';
        $granularity = $request->query('granularity', 'monthly');
        $today = \Carbon\Carbon::now($tz)->toDateString();
        // Por defecto: lo que va del año hasta hoy (buen rango para ver el arrastre).
        $from = $request->query('from', \Carbon\Carbon::now($tz)->startOfYear()->toDateString());
        $to = $request->query('to', $today);
        $countryCode = $request->query('country_code');

        return $this->service->periodSummary($companyId, $granularity, $from, $to, $countryCode);
    }

    /**
     * Comparativo período actual vs anterior (mensual/anual).
     */
    public function periodComparison(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $granularity = $request->query('granularity', 'monthly');
        $countryCode = $request->query('country_code');

        return $this->service->periodComparison($companyId, $granularity, $countryCode);
    }

    /**
     * Búsqueda de movimientos por rango + filtros.
     */
    public function search(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->search($request);
    }

    /**
     * Presupuestos por categoría: listado.
     */
    public function listBudgets(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->listBudgets($companyId);
    }

    /**
     * Estado de presupuestos del mes (gastado vs tope + semáforo).
     */
    public function budgetStatus(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->budgetStatus($companyId, $request->query('country_code'));
    }

    /**
     * Crea o actualiza el presupuesto de una categoría.
     */
    public function upsertBudget(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
        ]);

        return $this->service->upsertBudget(
            $companyId,
            $validated['category'],
            (float) $validated['amount'],
            $validated['currency'] ?? null,
            \Illuminate\Support\Facades\Auth::id()
        );
    }

    /**
     * Elimina un presupuesto.
     */
    public function deleteBudget(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->deleteBudget($companyId, $id);
    }

    /**
     * Gastos agrupados por categoría en un rango.
     */
    public function expensesByCategory(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $tz = $request->query('timezone') ?: 'America/Bogota';
        $today = \Carbon\Carbon::now($tz)->toDateString();
        $from = $request->query('from', \Carbon\Carbon::now($tz)->startOfMonth()->toDateString());
        $to = $request->query('to', $today);
        $countryCode = $request->query('country_code');

        return $this->service->expensesByCategory($companyId, $from, $to, $countryCode);
    }

    /**
     * PDF del resumen por período con saldo acumulado.
     */
    public function periodSummaryPdf(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $tz = $request->query('timezone') ?: 'America/Bogota';
        $granularity = $request->query('granularity', 'monthly');
        $today = \Carbon\Carbon::now($tz)->toDateString();
        $from = $request->query('from', \Carbon\Carbon::now($tz)->startOfYear()->toDateString());
        $to = $request->query('to', $today);
        $countryCode = $request->query('country_code');
        $currency = $request->query('currency');

        return $this->service->downloadPeriodSummaryPdf($companyId, $granularity, $from, $to, $countryCode, $currency);
    }

    /**
     * Detalle de movimientos en un rango de fechas (drill-down del resumen
     * por período).
     */
    public function rangeDetail(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->service->rangeDetail($request);
    }
}
