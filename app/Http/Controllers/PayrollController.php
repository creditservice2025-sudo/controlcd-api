<?php

namespace App\Http\Controllers;

use App\Exports\GenericExport;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\Payroll\PayrollException;
use App\Services\Payroll\PayrollService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Nómina semanal de cobradores. Controlador delgado: la lógica vive en
 * PayrollService. Los permisos se aplican en routes/api.php.
 */
class PayrollController extends Controller
{
    use ApiResponse;

    public function __construct(private PayrollService $payroll)
    {
    }

    /** Envuelve cada acción: los errores de negocio salen con su mensaje. */
    private function run(callable $action)
    {
        try {
            return $this->successResponse(['success' => true] + $action());
        } catch (PayrollException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[payroll] ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            return $this->errorResponse('Ocurrió un error en la nómina. Intente de nuevo.', 500);
        }
    }

    // ---- Parámetros y reglas -------------------------------------------

    public function settings(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->getSettings($this->payroll->resolveCompanyId($request))]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'week_start_day' => 'nullable|integer|min:1|max:7',
            'period_type' => 'nullable|string|in:daily,weekly,biweekly,monthly',
            'auto_pay_at_close' => 'nullable|boolean',
        ]);
        return $this->run(fn () => [
            'message' => 'Parámetros de nómina guardados.',
            'data' => $this->payroll->updateSettings(
                $this->payroll->resolveCompanyId($request),
                $request->only(['week_start_day', 'period_type', 'auto_pay_at_close'])
            ),
        ]);
    }

    public function sellers(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->companySellers($this->payroll->resolveCompanyId($request))]);
    }

    public function rules(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->listRules($this->payroll->resolveCompanyId($request))]);
    }

    /** Excepciones por cobrador: paginadas, con buscador y filtros. */
    public function ruleExceptions(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->exceptions(
            $this->payroll->resolveCompanyId($request),
            $request->only(['search', 'currency', 'period_type', 'active']),
            max(5, min(100, (int) $request->input('per_page', 15)))
        )]);
    }

    public function storeRule(Request $request)
    {
        return $this->run(function () use ($request) {
            $companyId = $this->payroll->resolveCompanyId($request);
            $currencies = $request->input('currencies');

            // Varias monedas (o todas) a la vez: una regla por moneda.
            if (is_array($currencies) && count($currencies) > 0 && !$request->filled('seller_id')) {
                $created = $this->payroll->saveRulesForCurrencies($companyId, $request->except('currencies'), $currencies);
                return [
                    'message' => count($created) === 1
                        ? 'Regla de comisión creada.'
                        : count($created) . ' reglas creadas, una por moneda.',
                    'data' => $created,
                ];
            }

            return [
                'message' => 'Regla de comisión creada.',
                'data' => $this->payroll->saveRule($companyId, $request->all()),
            ];
        });
    }

    public function updateRule(Request $request, $id)
    {
        return $this->run(fn () => [
            'message' => 'Regla de comisión actualizada.',
            'data' => $this->payroll->saveRule($this->payroll->resolveCompanyId($request), $request->all(), (int) $id),
        ]);
    }

    public function destroyRule(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->payroll->deleteRule($this->payroll->resolveCompanyId($request), (int) $id);
            return ['message' => 'Regla eliminada.'];
        });
    }

    // ---- Nóminas ---------------------------------------------------------

    public function index(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->list(
            $this->payroll->resolveCompanyId($request),
            max(1, min(100, (int) $request->input('per_page', 20)))
        )]);
    }

    /** Pantalla principal en una sola petición: parámetros, reglas, períodos, listado y resumen. */
    public function overview(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->overview(
            $this->payroll->resolveCompanyId($request),
            max(1, min(100, (int) $request->input('per_page', 20)))
        )]);
    }

    /** Cortes de nómina de la empresa, para el calendario. */
    public function periods(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->periods($this->payroll->resolveCompanyId($request))]);
    }

    /** Semana en curso, abierta y calculada sola por el sistema. */
    public function current(Request $request)
    {
        return $this->run(fn () => ['data' => $this->payroll->current($this->payroll->resolveCompanyId($request))]);
    }

    /** Nómina del período que contiene una fecha (para ir a un período por fecha). */
    public function byDate(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        return $this->run(fn () => ['data' => $this->payroll->findByDate(
            $this->payroll->resolveCompanyId($request),
            substr((string) $request->input('date'), 0, 10),
            $request->input('type')
        )]);
    }

    /** Semana (inicio/fin) que contiene una fecha, según el parámetro de la empresa. */
    public function week(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        return $this->run(function () use ($request) {
            [$start, $end] = $this->payroll->weekRange($this->payroll->resolveCompanyId($request), $request->input('date'));
            return ['data' => ['week_start' => $start, 'week_end' => $end]];
        });
    }

    public function store(Request $request)
    {
        $request->validate(['date' => 'required|date', 'notes' => 'nullable|string|max:1000']);
        return $this->run(function () use ($request) {
            $payroll = $this->payroll->generate(
                $this->payroll->resolveCompanyId($request),
                $request->input('date'),
                $request->input('notes')
            );
            return ['message' => 'Nómina generada en borrador.', 'data' => $this->payroll->show($payroll)];
        });
    }

    public function show(Request $request, $id)
    {
        return $this->run(fn () => ['data' => $this->payroll->show($this->find($request, $id))]);
    }

    public function recalculate(Request $request, $id)
    {
        return $this->run(fn () => [
            'message' => 'Nómina recalculada.',
            'data' => $this->payroll->show($this->payroll->recalculate($this->find($request, $id))),
        ]);
    }

    public function approve(Request $request, $id)
    {
        return $this->run(fn () => [
            'message' => 'Nómina aprobada.',
            'data' => $this->payroll->show($this->payroll->approve($this->find($request, $id))),
        ]);
    }

    public function void(Request $request, $id)
    {
        return $this->run(fn () => [
            'message' => 'Nómina anulada.',
            'data' => $this->payroll->show($this->payroll->void($this->find($request, $id), $request->input('reason'))),
        ]);
    }

    public function payAll(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $payroll = $this->find($request, $id);
            $result = $this->payroll->payAll($payroll);
            return [
                'message' => count($result['paid']) . ' cobrador(es) liquidado(s)'
                    . (count($result['failed']) ? ', ' . count($result['failed']) . ' sin liquidar.' : '.'),
                'result' => $result,
                'data' => $this->payroll->show($payroll),
            ];
        });
    }

    // ---- Líneas ------------------------------------------------------------

    public function payItem(Request $request, $itemId)
    {
        return $this->run(function () use ($request, $itemId) {
            $item = $this->findItem($request, $itemId);
            $this->payroll->payItem($item);
            return ['message' => 'Nómina de ' . $item->seller_name . ' liquidada: salió de su caja de hoy.', 'data' => $this->payroll->show($item->payroll)];
        });
    }

    /** Simulación (solo lectura) del cierre de caja del último día para un cobrador. */
    public function closePreview(Request $request, $itemId)
    {
        return $this->run(fn () => ['data' => $this->payroll->closePreview($this->findItem($request, $itemId))]);
    }

    /** Visto bueno del administrador a la nómina que un cobrador ya cerró en su caja. */
    public function approveItem(Request $request, $itemId)
    {
        return $this->run(function () use ($request, $itemId) {
            $item = $this->findItem($request, $itemId);
            $this->payroll->approveItem($item);
            return ['message' => 'Nómina de ' . $item->seller_name . ' aprobada.', 'data' => $this->payroll->show($item->payroll)];
        });
    }

    /** Aprueba a todos los cobradores que ya cerraron caja. */
    public function approveClosed(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $payroll = $this->find($request, $id);
            $count = $this->payroll->approveClosed($payroll);
            return ['message' => $count . ' nómina(s) aprobada(s).', 'data' => $this->payroll->show($payroll)];
        });
    }

    public function unpayItem(Request $request, $itemId)
    {
        return $this->run(function () use ($request, $itemId) {
            $item = $this->findItem($request, $itemId);
            $this->payroll->unpayItem($item);
            return ['message' => 'Liquidación anulada: el gasto se retiró de su caja.', 'data' => $this->payroll->show($item->payroll)];
        });
    }

    /** Pagos que forman el recaudo de la línea, y los eliminados del corte. */
    public function itemPayments(Request $request, $itemId)
    {
        return $this->run(fn () => ['data' => $this->payroll->itemPayments($this->findItem($request, $itemId))]);
    }

    /** Tira del período: recaudo y estado de la liquidación de cada día, por cobrador. */
    public function grid(Request $request, $id)
    {
        return $this->run(fn () => ['data' => $this->payroll->grid($this->find($request, $id))]);
    }

    /** Vista de un día del período: recaudo, créditos nuevos y eliminados por cobrador. */
    public function day(Request $request, $id)
    {
        $request->validate(['date' => 'required|date']);
        return $this->run(fn () => ['data' => $this->payroll->dayBreakdown(
            $this->find($request, $id),
            (string) $request->input('date')
        )]);
    }

    /** Pagos eliminados de la semana, de todos los cobradores de la nómina. */
    public function deletedPayments(Request $request, $id)
    {
        return $this->run(fn () => ['data' => $this->payroll->deletedPayments($this->find($request, $id))]);
    }

    public function storeAdjustment(Request $request, $itemId)
    {
        return $this->run(function () use ($request, $itemId) {
            $item = $this->findItem($request, $itemId);
            $this->payroll->addAdjustment($item, $request->only(['type', 'concept', 'amount']));
            return ['message' => 'Ajuste agregado.', 'data' => $this->payroll->show($item->payroll)];
        });
    }

    public function destroyAdjustment(Request $request, $adjustmentId)
    {
        return $this->run(function () use ($request, $adjustmentId) {
            $companyId = $this->payroll->resolveCompanyId($request);
            $adj = \App\Models\PayrollItemAdjustment::with('item')->find($adjustmentId);
            $payrollId = optional(optional($adj)->item)->payroll_id;
            $this->payroll->removeAdjustment((int) $adjustmentId, $companyId);
            return ['message' => 'Ajuste eliminado.', 'data' => $this->payroll->show($this->payroll->findPayroll((int) $payrollId, $companyId))];
        });
    }

    // ---- Exportes ----------------------------------------------------------

    public function export(Request $request, $id)
    {
        try {
            $data = $this->payroll->show($this->find($request, $id));
        } catch (PayrollException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        }

        $data['status_label'] = PayrollService::STATUS_LABELS[$data['status']] ?? $data['status'];
        foreach ($data['items'] as &$item) {
            $item['rule_label'] = PayrollService::describeRule($item['rule']);
            $item['status_label'] = PayrollService::ITEM_STATUS_LABELS[$item['status']] ?? $item['status'];
        }
        unset($item);

        $name = 'nomina_' . $data['week_start'] . '_' . $data['week_end'];

        if ($request->input('format') === 'excel') {
            $headings = [
                'Vendedor', 'Moneda', 'Regla', 'Recaudo', 'Créditos nuevos', 'Comisión recaudo', 'Bono tramo',
                'Comisión créditos nuevos', 'Sueldo fijo', 'Viáticos', 'Bonos', 'Descuentos', 'Neto a pagar', 'Estado',
            ];
            $rows = array_map(fn ($i) => [
                $i['seller_name'], $i['currency'], $i['rule_label'], $i['collection_base'], $i['placement_capital'],
                $i['collection_commission'], $i['tier_bonus'], $i['placement_commission'], $i['fixed_salary'],
                $i['allowance'], $i['bonuses_total'], $i['deductions'], $i['net'], $i['status_label'],
            ], $data['items']);
            return Excel::download(new GenericExport($headings, $rows), $name . '.xlsx');
        }

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('reports.payroll', ['p' => $data]);
        $pdf->setPaper('a4', 'landscape');

        return response()->make($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '.pdf"',
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, $id): Payroll
    {
        return $this->payroll->findPayroll((int) $id, $this->payroll->resolveCompanyId($request));
    }

    private function findItem(Request $request, $itemId): PayrollItem
    {
        return $this->payroll->findItem((int) $itemId, $this->payroll->resolveCompanyId($request));
    }
}
