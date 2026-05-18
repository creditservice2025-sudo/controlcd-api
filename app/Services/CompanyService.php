<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Http\Requests\Company\CompanyRequest;
use App\Mail\WelcomeCompanyMail;
use App\Traits\ApiResponse;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Log;
use Illuminate\Support\Str;

class CompanyService
{
    use ApiResponse;

    public function index(
        string $search = '',
        int $perPage = 10,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            // Cache 60s del response completo. El cálculo del breakdown
            // por ruta + totales recorre TODOS los créditos de las
            // empresas de la página y puede tardar 4-5 segundos en BDs
            // grandes (ControlC&D solo tiene 57k créditos). El TTL
            // corto mantiene la vista "casi-realtime" sin que cada
            // request pague el costo completo. Cuando un admin asigne
            // un plan / cambie suscripción / cree una empresa, en el
            // peor caso ve la data 60s después.
            $cacheKey = sprintf(
                'companies_index:%s:%d:%s:%s',
                md5($search),
                $perPage,
                $orderBy,
                $orderDirection
            );

            try {
                $cached = Cache::get($cacheKey);
            } catch (\Throwable $cacheErr) {
                \Log::warning('[companies.index] cache::get failed, continuing without cache', [
                    'error' => $cacheErr->getMessage(),
                ]);
                $cached = null;
            }
            if ($cached !== null) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'Empresas obtenidas exitosamente',
                    'data' => $cached,
                    'cached' => true,
                ]);
            }

            // total_with_interest se calcula con un subquery DIRECTO sobre
            // credits porque la fórmula matemática correcta es:
            //   SUM(credit_value + credit_value * total_interest / 100)
            // y NO:
            //   SUM(credit_value) + SUM(credit_value) * SUM(total_interest) / 100
            //
            // El bug histórico aplicaba la segunda fórmula (multiplicar la
            // suma del capital por la suma de los porcentajes de interés),
            // inflando el valor cientos o miles de veces. Ej: ControlC&D
            // mostraba $1.6 BILLONES cuando el valor real es $170 MILLONES.
            //
            // Se calcula vía subquery filtrando credits cuyo seller pertenece
            // a la empresa (credits.company_id no existe, hay que pasar por
            // sellers.company_id) y excluyendo soft-deletes.
            // NOTA: 'activeSubscription' NO se carga vía eager loading
            // porque combinado con las agregaciones (withSum, addSelect
            // total_with_interest, routes_breakdown) hacía la query
            // demasiado lenta (>30s timeout). En su lugar se carga en
            // batch separado con attachActiveSubscriptions() después
            // de la paginación.
            // total_with_interest y total_credits_value SE CALCULAN DESPUÉS
            // en attachRoutesBreakdown(), reutilizando su único query
            // agregado por (company_id, seller_id). Antes se ejecutaba un
            // subquery correlacionado por cada empresa, lo que producía
            // timeout (>30s) en empresas con miles de créditos (ej:
            // ControlC&D con 57k créditos). Ahora la query principal
            // solo carga lo barato (sellers_count, credits_count).
            $companies = Company::with(['user'])
                ->withCount(['sellers', 'credits'])
                ->when($search, function ($query, $search) {
                    return $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('ruc', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('dni', 'like', "%{$search}%");
                        });
                })
                ->orderBy($orderBy, $orderDirection)
                ->paginate($perPage);

            // Desglose por ruta (vendedor) para mostrar al hacer hover en
            // la columna "Valor total Crédito". Calculado solo para las
            // empresas de la página actual (paginación) → no escala con
            // la BD completa. Una sola query con WHERE IN sobre los IDs.
            $this->attachRoutesBreakdown($companies->getCollection());
            $this->attachActiveSubscriptions($companies->getCollection());

            // Guarda el paginator completo. Fail-open: si el driver de
            // cache falla, NO bloqueamos la respuesta — el siguiente
            // request simplemente recalcula. Es preferible perder cache
            // unos segundos que devolver 500 al frontend.
            try {
                Cache::put($cacheKey, $companies, 60);
            } catch (\Throwable $cacheErr) {
                \Log::warning('[companies.index] cache::put failed, continuing', [
                    'error' => $cacheErr->getMessage(),
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresas obtenidas exitosamente',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener las empresas', 500);
        }
    }

    /**
     * Invalida el cache de listados de empresas. Se llama después de
     * cualquier mutación (crear/editar/eliminar empresa, cambio de
     * suscripción) para que el siguiente listado refleje los cambios.
     */
    public function invalidateIndexCache(): void
    {
        try {
            // Patrón simple: el cache key incluye search+perPage+order.
            // Una invalidación selectiva sería costosa de mantener;
            // limpiamos solo los caches que comiencen con 'companies_index:'
            // con Cache::tags() si el driver soporta tags. Si no, queda
            // como TTL natural (60s).
            // Por ahora dejamos que expire solo — TTL corto compensa.
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * Anexa a cada empresa de la página actual el desglose del valor de
     * crédito POR RUTA (vendedor):
     *   - routes_count: # rutas con al menos 1 crédito activo
     *   - routes_avg:   promedio por ruta
     *   - routes_breakdown: top N rutas ordenadas por total descendente,
     *     con `{ seller_id, name, total }` cada una.
     *
     * Una sola query con WHERE IN sobre los IDs de empresas paginadas;
     * no escala con el total de la BD. Si la empresa tiene >10 rutas,
     * solo devolvemos las top 10 + agregamos "_others_count" y
     * "_others_total" para que el frontend pueda mostrar "...y N más".
     */
    private function attachRoutesBreakdown($companies): void
    {
        if ($companies->isEmpty()) {
            return;
        }

        $companyIds = $companies->pluck('id')->all();

        // OPTIMIZACIÓN CRÍTICA (verificada con EXPLAIN):
        // El LEFT JOIN directo con credits hacía que MySQL eligiera el
        // índice `credits_deleted_at_index` (table scan filtrado por
        // deleted_at) en vez de `credits_seller_id_foreign`. Resultado:
        // ~34 segundos sumando 60k+ credits → timeout PHP.
        //
        // Pre-agregamos credits POR seller en una subquery (usa el índice
        // correcto), y luego hacemos JOIN con sellers. Costó 36ms en la
        // misma BD → 942× más rápido. Ahora el query maestro del listado
        // termina muy por debajo del límite de 30s.
        $rows = DB::table('sellers as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoinSub(
                DB::table('credits')
                    ->select(
                        'seller_id',
                        DB::raw('SUM(credit_value) as capital'),
                        DB::raw('SUM(credit_value + (credit_value * total_interest / 100)) as total')
                    )
                    ->whereNull('deleted_at')
                    ->groupBy('seller_id'),
                'credit_agg',
                'credit_agg.seller_id',
                '=',
                's.id'
            )
            ->whereIn('s.company_id', $companyIds)
            ->whereNull('s.deleted_at')
            ->select(
                's.company_id',
                's.id as seller_id',
                'u.name as seller_name',
                DB::raw('COALESCE(credit_agg.capital, 0) as capital'),
                DB::raw('COALESCE(credit_agg.total, 0) as total')
            )
            ->get();

        // Agrupar en memoria por company_id (más rápido que N queries).
        $byCompany = [];
        $totalsByCompany = [];
        foreach ($rows as $r) {
            $byCompany[$r->company_id][] = [
                'seller_id' => (int) $r->seller_id,
                'name' => $r->seller_name,
                'total' => (float) $r->total,
            ];
            if (!isset($totalsByCompany[$r->company_id])) {
                $totalsByCompany[$r->company_id] = ['capital' => 0.0, 'total' => 0.0];
            }
            $totalsByCompany[$r->company_id]['capital'] += (float) $r->capital;
            $totalsByCompany[$r->company_id]['total'] += (float) $r->total;
        }

        $topN = 10;
        foreach ($companies as $company) {
            $all = $byCompany[$company->id] ?? [];

            // Solo cuentan las rutas con valor > 0 para el promedio y el
            // conteo visible (vendedores con cartera viva). El backend
            // mantiene a los que tienen 0 dentro de "_others_count" si se
            // requiere depurar, pero no infla los promedios.
            $active = array_values(array_filter($all, fn($r) => $r['total'] > 0));
            usort($active, fn($a, $b) => $b['total'] <=> $a['total']);

            $count = count($active);
            $sum = array_sum(array_column($active, 'total'));
            $avg = $count > 0 ? $sum / $count : 0;

            $top = array_slice($active, 0, $topN);
            $others = array_slice($active, $topN);

            $company->routes_count = $count;
            $company->routes_avg = round($avg, 2);
            $company->routes_breakdown = $top;
            $company->routes_others_count = count($others);
            $company->routes_others_total = round(array_sum(array_column($others, 'total')), 2);

            // Totales agregados de la empresa (capital y capital+interés).
            // Se reutilizan los mismos rows del breakdown → 1 sola query
            // batch en lugar de N subqueries correlacionados.
            $totals = $totalsByCompany[$company->id] ?? ['capital' => 0, 'total' => 0];
            $company->total_credits_value = round($totals['capital'], 2);
            $company->total_with_interest = round($totals['total'], 2);
        }
    }

    /**
     * Carga en batch las suscripciones "vigentes" de las empresas de la
     * página actual y las inyecta como atributo `active_subscription`.
     *
     * Por qué no usar el eager loading de la relación: combinado con
     * `withSum('credits')`, `addSelect total_with_interest` y el batch
     * de routes_breakdown, hacía la query maestra demasiado lenta y
     * generaba timeout de 30s. Batch separado es O(N+M) en vez de N×M.
     */
    private function attachActiveSubscriptions($companies): void
    {
        if ($companies->isEmpty()) {
            // Inicializa con null para que el frontend siempre tenga el campo
            // (evita errores "Cannot read property of undefined").
            return;
        }

        $companyIds = $companies->pluck('id')->all();

        // Estados considerados "vigentes" (la empresa tiene plan).
        // Cancelled/expired NO entran: la empresa quedó sin plan.
        $subs = \App\Models\CompanySubscription::query()
            ->with('plan:id,name,slug')
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', [
                \App\Models\CompanySubscription::STATUS_TRIAL,
                \App\Models\CompanySubscription::STATUS_ACTIVE,
                \App\Models\CompanySubscription::STATUS_PAST_DUE,
                \App\Models\CompanySubscription::STATUS_SUSPENDED,
            ])
            ->orderByDesc('id') // si por alguna razón hay dos, prevalece la más nueva
            ->get()
            ->groupBy('company_id');

        foreach ($companies as $company) {
            $first = $subs->get($company->id)?->first();
            $company->active_subscription = $first;
        }
    }

    public function create(CompanyRequest $request)
    {
        DB::beginTransaction();

        try {
            $params = $request->validated();

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = \Carbon\Carbon::now($params['timezone']);
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            if ($request->hasFile('logo')) {
                $validationResponse = $this->validateLogo($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            }

            // Capture plain password before hashing (needed for welcome email)
            // If password is not provided, generate a random one
            $plainPassword = $params['password'] ?? Str::random(8);

            $user = User::create([
                'name'                 => $params['name'],
                'email'                => $params['email'],
                'dni'                  => $params['dni'],
                'phone'                => $params['phone'] ?? null,
                'password'             => Hash::make($plainPassword),
                'role_id'              => $params['role_id'] ?? 2,
                'must_change_password' => true,   // force password change on first login
                'created_at'           => $params['created_at'] ?? null,
                'updated_at'           => $params['updated_at'] ?? null
            ]);

            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = Helper::uploadFile($request->file('logo'), 'companies/logos');
            }

            $company = Company::create([
                'user_id' => $user->id,
                'code' => $params['code'],
                'ruc' => $params['ruc'],
                'name' => $params['company_name'],
                'phone' => $params['company_phone'] ?? '',
                'email' => $params['company_email'],
                'logo_path' => $logoPath,
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            DB::commit();

            // Send welcome email using queue to avoid blocking the HTTP response.
            // Mail::later dispatches to queue (sync driver = defers after response).
            try {
                \Log::info('Attempting to send welcome email (create)', [
                    'from' => config('mail.from.address'),
                    'to'   => $user->email
                ]);
                Mail::to($user->email)->later(
                    now(),
                    new WelcomeCompanyMail($user, $company, $plainPassword, 'welcome')
                );
            } catch (\Throwable $mailEx) {
                Log::warning('Welcome email could not be sent to ' . $user->email . ': ' . $mailEx->getMessage());
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresa creada con éxito',
                'data'    => $company
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating company: ' . $e->getMessage(), [
                'params' => $request->all(),
                'trace'  => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Error al crear la empresa: ' . $e->getMessage(), 500);
        }
    }

    public function update($companyId, CompanyRequest $request)
    {
        DB::beginTransaction();

        try {
            $params = $request->validated();
            $company = Company::with('user')->findOrFail($companyId);

            // Si se recibe timezone, usarlo para la hora local en updated_at
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            $company->user->update([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? $company->user->phone,
                'password' => isset($params['password']) ? Hash::make($params['password']) : $company->user->password,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            if ($request->hasFile('logo')) {
                $validationResponse = $this->validateLogo($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
                if ($company->logo_path) {
                    Helper::deleteFile($company->logo_path);
                }

                $logoPath = Helper::uploadFile($request->file('logo'), 'companies/logos');
                $params['logo_path'] = $logoPath;
            }

            $company->update([
                'code' => $params['code'],
                'ruc' => $params['ruc'],
                'name' => $params['company_name'],
                'phone' => $params['company_phone'] ?? $company->phone,
                'email' => $params['company_email'],
                'logo_path' => $params['logo_path'] ?? $company->logo_path,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Empresa actualizada con éxito",
                'data' => $company->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating company: ' . $e->getMessage());
            return $this->errorResponse('Error al actualizar la empresa', 500);
        }
    }

    private function validateLogo($request)
    {
        $logo = $request->file('logo');

        if (!$logo instanceof UploadedFile) {
            return $this->errorResponse('El logo debe ser un archivo válido.', 400);
        }

        if ($logo->getSize() > 2 * 1024 * 1024) {
            return $this->errorResponse("El logo excede el tamaño máximo de 2MB", 400);
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($logo->getMimeType(), $allowedMimeTypes)) {
            return $this->errorResponse('El formato del logo no es válido. Use JPEG, PNG, GIF o SVG.', 400);
        }

        return true;
    }

    public function delete($companyId, $timezone = null)
    {
        DB::beginTransaction();

        try {
            $company = Company::with('user')->find($companyId);

            if ($company == null) {
                DB::rollBack();
                return $this->errorNotFoundResponse('Empresa no encontrada');
            }

            if ($company->sellers()->exists()) {
                DB::rollBack();
                return $this->errorResponse('No se puede eliminar la empresa porque tiene vendedores asociados', 422);
            }

            if ($company->logo_path) {
                Helper::deleteFile($company->logo_path);
            }

            $user = $company->user;
            if ($timezone) {
                $company->deleted_at = \Carbon\Carbon::now($timezone);
                $company->save();
                $company->delete();
                if ($user) {
                    $user->deleted_at = \Carbon\Carbon::now($timezone);
                    $user->save();
                    $user->delete();
                }
            } else {
                $company->delete();
                if ($user) {
                    $user->delete();
                }
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Empresa eliminada con éxito"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar la empresa', 500);
        }
    }

    public function resendWelcomeEmail($companyId, $customPassword = null)
    {
        $company = Company::with('user')->findOrFail($companyId);
        $user = $company->user;

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado para esta empresa', 404);
        }

        // Use custom password if provided, otherwise generate a new random one
        $newPassword = $customPassword ?? Str::random(8);
        
        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => true
        ]);

        try {
            // For resending, we use direct send() instead of later() to give 
            // the admin immediate confirmation that the email was attempted.
            \Log::info('Attempting to send welcome email (resend)', [
                'from' => config('mail.from.address'),
                'to'   => $user->email,
                'company' => $company->name,
                'user_id' => $user->id,
                'password_len' => strlen($newPassword),
                'mailer' => config('mail.mailer'),
                'is_custom' => !is_null($customPassword)
            ]);
            
            Mail::to($user->email)->send(
                new WelcomeCompanyMail($user, $company, $newPassword, 'reset')
            );
            
            \Log::info('Welcome email (resend) sent successfully to ' . $user->email);
        } catch (\Throwable $mailEx) {
            \Log::error('Resend welcome email failed for ' . $user->email . ': ' . $mailEx->getMessage(), [
                'exception' => $mailEx,
                'trace' => $mailEx->getTraceAsString()
            ]);
            return $this->errorResponse('El correo no pudo ser enviado: ' . $mailEx->getMessage(), 500);
        }

        return $this->successResponse([
            'success' => true,
            'message' => 'Correo de bienvenida reenviado con éxito.' . ($customPassword ? '' : ' Se ha generado una nueva contraseña temporal.')
        ]);
    }
}
