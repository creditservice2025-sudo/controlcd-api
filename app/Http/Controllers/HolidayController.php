<?php

namespace App\Http\Controllers;

use App\Http\Requests\Holiday\HolidayRequest;
use App\Models\Holiday;
use App\Services\HolidayImportService;
use App\Support\Tenant;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * ABM de días no laborables (feriados) — modelo HÍBRIDO:
 *
 *   - NACIONAL (company_id = null): compartido por todas las empresas del país.
 *     Solo lo administra el Super-Admin (rol 1). Al crearlo sin company_id.
 *   - DE EMPRESA (company_id = X): propio de la empresa X. Lo administra un
 *     usuario de esa empresa o el Super-Admin impersonando (company_id = X).
 *
 * Una empresa VE los nacionales de su país + los suyos, pero solo puede editar
 * los suyos. El alcance nunca se confía del input para un Admin: se deriva del
 * usuario autenticado.
 */
class HolidayController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $isSuper = Tenant::isSuperAdmin();
            $companyId = $isSuper
                ? ($request->filled('company_id') ? (int) $request->get('company_id') : null)
                : Tenant::currentCompanyId();

            if (!$isSuper && !$companyId) {
                return $this->errorResponse('No se pudo determinar la empresa del usuario.', 422);
            }

            $query = Holiday::with('country');

            if ($companyId) {
                // Contexto de empresa: nacionales (company_id null) + propios.
                $query->where(function ($q) use ($companyId) {
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                });
            } else {
                // Super-Admin sin empresa: administra los feriados NACIONALES.
                $query->whereNull('company_id');
            }

            if ($request->filled('country_id')) {
                $query->where('country_id', (int) $request->get('country_id'));
            }
            if ($request->filled('search')) {
                $query->where('description', 'like', '%' . $request->get('search') . '%');
            }

            $holidays = $query
                ->orderByRaw('COALESCE(month, MONTH(`date`))')
                ->orderByRaw('COALESCE(day, DAY(`date`))')
                ->get();

            return $this->successResponse($holidays);
        } catch (\Throwable $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    public function store(HolidayRequest $request)
    {
        try {
            if (Tenant::isSuperAdmin()) {
                // Con company_id => feriado de esa empresa; sin él => NACIONAL.
                $companyId = $request->filled('company_id')
                    ? (int) $request->input('company_id')
                    : null;
            } else {
                $companyId = Tenant::currentCompanyId();
                if (!$companyId) {
                    return $this->errorResponse('No se pudo determinar la empresa del usuario.', 422);
                }
            }

            $data = $this->normalize($request->validated());
            $data['company_id'] = $companyId; // null = nacional

            $holiday = Holiday::create($data);
            return $this->successCreatedResponse($holiday->load('country'));
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(HolidayRequest $request, $id)
    {
        try {
            $holiday = Holiday::find($id);
            if (!$holiday) {
                return $this->errorNotFoundResponse('Feriado no encontrado.');
            }
            if (!$this->canManage($holiday)) {
                return $this->errorResponse('No tiene permiso para administrar este feriado.', 403);
            }

            $data = $this->normalize($request->validated());
            unset($data['company_id']); // el alcance (nacional/empresa) no se reasigna en edición
            $holiday->update($data);

            return $this->successResponse($holiday->load('country'));
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $holiday = Holiday::find($id);
            if (!$holiday) {
                return $this->errorNotFoundResponse('Feriado no encontrado.');
            }
            if (!$this->canManage($holiday)) {
                return $this->errorResponse('No tiene permiso para administrar este feriado.', 403);
            }

            $holiday->delete();
            return $this->successResponse(['message' => 'Feriado eliminado.']);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Importa los feriados NACIONALES oficiales de un país desde Nager.Date.
     * Solo el Super-Admin (los nacionales son compartidos por todas las empresas).
     */
    public function import(Request $request, HolidayImportService $service)
    {
        try {
            if (!Tenant::isSuperAdmin()) {
                return $this->errorForbiddenResponse('Solo el administrador general puede importar feriados oficiales.');
            }

            $data = $request->validate([
                'country_id' => 'required|integer|exists:countries,id',
                'from_year'  => 'nullable|integer|min:2000|max:2100',
                'to_year'    => 'nullable|integer|min:2000|max:2100',
            ]);

            $result = $service->import(
                (int) $data['country_id'],
                isset($data['from_year']) ? (int) $data['from_year'] : null,
                isset($data['to_year']) ? (int) $data['to_year'] : null
            );

            return $this->successResponse($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Un feriado NACIONAL (company_id null) solo lo administra el Super-Admin.
     * Uno DE EMPRESA lo administra el Super-Admin o un usuario de esa empresa.
     */
    private function canManage(Holiday $holiday): bool
    {
        if (Tenant::isSuperAdmin()) {
            return true;
        }
        if ($holiday->company_id === null) {
            return false; // nacional: fuera del alcance de un Admin de empresa
        }
        return (int) $holiday->company_id === (int) Tenant::currentCompanyId();
    }

    /**
     * Deja consistentes los campos según el tipo: recurrente usa month/day y
     * limpia date; fecha exacta usa date y limpia month/day.
     */
    private function normalize(array $data): array
    {
        $data['recurring'] = filter_var($data['recurring'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($data['recurring']) {
            $data['date'] = null;
        } else {
            $data['month'] = null;
            $data['day'] = null;
        }
        if (!array_key_exists('active', $data) || $data['active'] === null) {
            $data['active'] = true;
        }
        return $data;
    }
}
