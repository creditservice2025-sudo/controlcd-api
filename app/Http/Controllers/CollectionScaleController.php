<?php

namespace App\Http\Controllers;

use App\Services\CollectionScaleService;
use Illuminate\Http\Request;

/**
 * Metas de recaudo por empresa y moneda (Parámetros generales → Metas de
 * recaudo). Solo Super-Admin y Admin (middleware role.id:1,2); el Admin opera
 * siempre sobre su empresa, lo resuelve el servicio.
 */
class CollectionScaleController extends Controller
{
    public function __construct(private CollectionScaleService $scales)
    {
    }

    public function index(Request $request)
    {
        return $this->run(fn () => ['data' => $this->scales->list($this->company($request))]);
    }

    public function deleted(Request $request)
    {
        return $this->run(fn () => ['data' => $this->scales->deleted($this->company($request))]);
    }

    public function history(Request $request, $id)
    {
        return $this->run(fn () => ['data' => $this->scales->history($this->company($request), (int) $id)]);
    }

    public function store(Request $request)
    {
        return $this->run(fn () => [
            'message' => 'Meta de recaudo creada.',
            'data' => $this->scales->save($this->company($request), $request->all()),
        ]);
    }

    public function update(Request $request, $id)
    {
        return $this->run(fn () => [
            'message' => 'Meta de recaudo actualizada.',
            'data' => $this->scales->save($this->company($request), $request->all(), (int) $id),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->scales->delete($this->company($request), (int) $id);
            return ['message' => 'Meta de recaudo eliminada.'];
        });
    }

    /** Interruptor "Mostrar avance al vendedor" de la empresa, con su historial. */
    public function progressSetting(Request $request)
    {
        return $this->run(fn () => ['data' => $this->scales->progressSetting($this->company($request))]);
    }

    /** Lo cambia SOLO el Super-Admin (lo valida el servicio). */
    public function updateProgressSetting(Request $request)
    {
        return $this->run(fn () => [
            'message' => $request->boolean('enabled')
                ? 'Los vendedores ya pueden ver su avance.'
                : 'Los vendedores ya no ven su avance.',
            'data' => $this->scales->setProgress($this->company($request), $request->boolean('enabled')),
        ]);
    }

    private function company(Request $request): int
    {
        return $this->scales->resolveCompanyId(
            $request->filled('company_id') ? (int) $request->input('company_id') : null
        );
    }

    /** Errores de negocio como 422 (o el código que traigan), con su mensaje. */
    private function run(callable $fn)
    {
        try {
            return response()->json(['success' => true] + $fn());
        } catch (\DomainException $e) {
            $code = in_array($e->getCode(), [403, 404], true) ? $e->getCode() : 422;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            \Log::error('CollectionScaleController: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al procesar la meta de recaudo.'], 500);
        }
    }
}
