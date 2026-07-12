<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Services\SellerConfigService;
use App\Support\Tenant;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vista y edición del parámetro "trabaja domingos" (seller_configs.works_sundays),
 * agrupado por empresa y filtrable por país.
 *
 * Alcance por rol:
 *   - Super-Admin: ve TODAS las empresas (o una si impersona con company_id).
 *   - Admin (rol 2): ve y edita SOLO los vendedores de SU empresa.
 *
 * works_sundays es POR VENDEDOR/RUTA; la empresa no tiene país propio (se deriva
 * de la ciudad de cada vendedor). Edición individual reusa PUT seller/{id}/config;
 * el cambio masivo va por bulkUpdate (mismo service → consistencia + auditoría).
 */
class SundayScheduleController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $scopeCompanyId = $this->resolveScopeCompanyId($request, $error);
            if ($error) {
                return $error;
            }

            $countryId = $request->filled('country_id') ? (int) $request->get('country_id') : null;
            $search = trim((string) $request->get('search', ''));

            $sellers = Seller::with([
                    'user:id,name',
                    'city:id,name,country_id',
                    'city.country:id,name',
                    'company:id,name',
                    'config:id,seller_id,works_sundays',
                ])
                ->where('status', 'ACTIVE')
                ->when($scopeCompanyId, fn ($q) => $q->where('company_id', $scopeCompanyId))
                ->when($countryId, function ($q) use ($countryId) {
                    $q->whereHas('city', fn ($c) => $c->where('country_id', $countryId));
                })
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->whereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                          ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                    });
                })
                ->get();

            $grouped = [];
            foreach ($sellers as $s) {
                $companyId = $s->company_id ?? 0;
                if (!isset($grouped[$companyId])) {
                    $grouped[$companyId] = [
                        'company_id'      => $s->company_id,
                        'company_name'    => optional($s->company)->name ?? 'Sin empresa',
                        'sellers'         => [],
                        'works_count'     => 0,
                        'not_works_count' => 0,
                    ];
                }

                $works = $s->config ? (bool) $s->config->works_sundays : true;

                $grouped[$companyId]['sellers'][] = [
                    'seller_id'     => $s->id,
                    'uuid'          => $s->uuid,
                    'name'          => optional($s->user)->name ?? ('Ruta #' . $s->id),
                    'city'          => optional($s->city)->name,
                    'country'       => optional(optional($s->city)->country)->name,
                    'works_sundays' => $works,
                ];

                $works
                    ? $grouped[$companyId]['works_count']++
                    : $grouped[$companyId]['not_works_count']++;
            }

            $result = array_values(array_map(function ($g) {
                $g['sellers_count'] = count($g['sellers']);
                usort($g['sellers'], fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
                return $g;
            }, $grouped));

            usort($result, fn ($a, $b) => strcasecmp($a['company_name'], $b['company_name']));

            return $this->successResponse($result);
        } catch (\Throwable $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    /**
     * Cambio MASIVO de works_sundays para un conjunto de rutas. Un Admin solo
     * puede afectar vendedores de SU empresa (los ajenos se descartan en silencio).
     */
    public function bulkUpdate(Request $request, SellerConfigService $service)
    {
        try {
            $data = $request->validate([
                'works_sundays' => 'required|boolean',
                'seller_ids'    => 'required|array|min:1',
                'seller_ids.*'  => 'integer',
            ]);

            $isSuper = Tenant::isSuperAdmin();
            // Super-Admin: acota a la empresa impersonada si viene company_id.
            // Admin: siempre su empresa (ignora el input).
            $companyId = $isSuper
                ? ($request->filled('company_id') ? (int) $request->get('company_id') : null)
                : Tenant::currentCompanyId();
            if (!$isSuper && !$companyId) {
                return $this->errorForbiddenResponse('No se pudo determinar la empresa del usuario.');
            }

            $sellers = Seller::whereIn('id', $data['seller_ids'])
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId)) // Admin: solo los suyos
                ->get();

            $value = (bool) $data['works_sundays'];

            // Atómico: o se aplican todas las rutas o ninguna. Evita que un fallo
            // a mitad de un masivo grande deje la empresa en un estado inconsistente.
            $updated = DB::transaction(function () use ($sellers, $service, $value) {
                $n = 0;
                foreach ($sellers as $s) {
                    $service->createOrUpdate($s->id, ['works_sundays' => $value]);
                    $n++;
                }
                return $n;
            });

            return $this->successResponse(['updated' => $updated, 'works_sundays' => $value]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    /**
     * Empresa de alcance: Super-Admin ve todas (o una si impersona con company_id);
     * Admin siempre la suya. Devuelve null = todas (super). Si falla, setea $error.
     */
    private function resolveScopeCompanyId(Request $request, &$error): ?int
    {
        $error = null;
        if (Tenant::isSuperAdmin()) {
            return $request->filled('company_id') ? (int) $request->get('company_id') : null;
        }
        $companyId = Tenant::currentCompanyId();
        if (!$companyId) {
            $error = $this->errorForbiddenResponse('No se pudo determinar la empresa del usuario.');
        }
        return $companyId;
    }
}
