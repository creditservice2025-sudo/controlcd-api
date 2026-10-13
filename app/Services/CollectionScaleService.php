<?php

namespace App\Services;

use App\Models\SellerCollectionScale;
use App\Models\SellerCollectionScaleAudit;
use App\Support\EscalaRecaudo;
use App\Support\FormatoMoneda;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Metas de recaudo: la escala rojo / amarillo / azul / verde con la que se
 * pinta a cada vendedor en el Resumen General.
 *
 * Dos alcances:
 *   - GENERAL de una moneda (seller_id null): aplica a todos los vendedores de
 *     la empresa que operan en esa moneda.
 *   - PROPIA de un vendedor (seller_id = X): manda sobre la general de su
 *     moneda. Su moneda es siempre la del país de su ruta.
 *
 * Es la ÚNICA fuente de la escala: la pantalla, el Excel, el PDF y el mensaje
 * de WhatsApp reciben ya resuelto el tono y la meta de cada fila.
 *
 * Tipos de meta (period_type). El reporte deja consultar cualquier rango, así
 * que la meta se lleva a la misma escala que el rango antes de comparar:
 *   - daily:   promedio por DÍA TRABAJADO = recaudo ÷ días con liquidación
 *              aprobada. Domingos y feriados sin trabajar no lo castigan.
 *   - weekly:  ritmo semanal  = recaudo ÷ (días del rango / 7).
 *   - monthly: ritmo mensual  = recaudo ÷ (días del rango / 30).
 *   - range:   el TOTAL del período consultado, sin prorrateo. Es la regla que
 *              había antes de parametrizar y con la que se sembró cada empresa.
 */
class CollectionScaleService
{
    public const PERIOD_TYPES = ['daily', 'weekly', 'monthly', 'range'];

    public const PERIOD_LABELS = [
        'daily' => 'Meta diaria (promedio por día trabajado)',
        'weekly' => 'Meta semanal',
        'monthly' => 'Meta mensual',
        'range' => 'Total del período consultado',
    ];

    private const RELATIONS = [
        'creator:id,name', 'editor:id,name', 'remover:id,name',
        'seller:id,user_id,city_id', 'seller.user:id,name', 'seller.city:id,name',
    ];

    // ---- Alcance ------------------------------------------------------------

    /**
     * Empresa sobre la que se opera. El Admin SIEMPRE la suya (nunca la del
     * input); el Super-Admin la que elija.
     */
    public function resolveCompanyId(?int $requested): int
    {
        $user = Auth::user();
        if ((int) $user->role_id === 1) {
            if (!$requested) {
                throw new \DomainException('Elija una empresa.');
            }
            return $requested;
        }
        $companyId = optional($user->company)->id;
        if (!$companyId) {
            throw new \DomainException('No se pudo determinar la empresa del usuario.');
        }
        return (int) $companyId;
    }

    /**
     * Vendedores activos de la empresa con la moneda del país de su ruta,
     * ordenados por nombre. Alimenta el selector de "meta propia".
     */
    public function companySellers(int $companyId): array
    {
        return DB::table('sellers as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('cities as ci', 'ci.id', '=', 's.city_id')
            ->leftJoin('countries as co', 'co.id', '=', 'ci.country_id')
            ->where('s.company_id', $companyId)
            ->whereNull('s.deleted_at')
            ->orderBy('u.name')
            ->get(['s.id', 'u.name', 's.city_id', 'ci.name as city_name', 'co.currency'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'city_id' => (int) $r->city_id,
                'city_name' => $r->city_name,
                'currency' => strtoupper((string) $r->currency),
            ])
            ->all();
    }

    /** Monedas en las que opera la empresa: las de los países de sus rutas. */
    public function companyCurrencies(int $companyId): array
    {
        return collect($this->companySellers($companyId))
            ->pluck('currency')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // ---- Consulta -----------------------------------------------------------

    /**
     * Todo lo que necesita la pantalla en una petición. Las metas van
     * ORDENADAS: primero por moneda; dentro de cada moneda la general y después
     * las propias de cada vendedor por nombre.
     */
    public function list(int $companyId): array
    {
        $sellers = $this->companySellers($companyId);

        return [
            'currencies' => collect($sellers)->pluck('currency')->filter()->unique()->sort()->values()->all(),
            'period_types' => collect(self::PERIOD_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'sellers' => $sellers,
            'scales' => $this->ordenar(
                SellerCollectionScale::where('company_id', $companyId)->with(self::RELATIONS)->get()
                    ->map(fn ($s) => $this->present($s))
            ),
        ];
    }

    public function deleted(int $companyId): array
    {
        return SellerCollectionScale::onlyTrashed()
            ->where('company_id', $companyId)
            ->with(self::RELATIONS)
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get()
            ->map(fn ($s) => $this->present($s))
            ->all();
    }

    /** Línea de tiempo de una meta (también eliminada), lo más reciente primero. */
    public function history(int $companyId, int $id): array
    {
        $scale = SellerCollectionScale::withTrashed()->where('company_id', $companyId)
            ->with(self::RELATIONS)->find($id);
        if (!$scale) {
            throw new \DomainException('Meta no encontrada.', 404);
        }

        $events = SellerCollectionScaleAudit::where('scale_id', $scale->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'user_name' => $a->user_name,
                'at' => optional($a->created_at)->toIso8601String(),
                'changes' => $a->changes,
            ])
            ->all();

        return ['scale' => $this->present($scale), 'events' => $events];
    }

    // ---- Alta, edición y baja ----------------------------------------------

    public function save(int $companyId, array $data, ?int $id = null): array
    {
        $existente = null;
        if ($id) {
            $existente = SellerCollectionScale::where('company_id', $companyId)->find($id);
            if (!$existente) {
                throw new \DomainException('Meta no encontrada.', 404);
            }
        }

        // El alcance (moneda / vendedor) no cambia al editar: se elimina y se
        // crea otra. Así el historial de una meta siempre habla de lo mismo.
        $sellerId = $existente ? $existente->seller_id : (!empty($data['seller_id']) ? (int) $data['seller_id'] : null);

        if ($sellerId) {
            $vendedor = collect($this->companySellers($companyId))->firstWhere('id', $sellerId);
            if (!$vendedor && !$existente) {
                throw new \DomainException('El vendedor no pertenece a la empresa.');
            }
            // La moneda de una meta propia es siempre la del país del vendedor.
            $currency = $existente ? $existente->currency : $vendedor['currency'];
            if (!$currency) {
                throw new \DomainException('La ruta del vendedor no tiene moneda configurada.');
            }
        } else {
            $currency = $existente ? $existente->currency : strtoupper(trim((string) ($data['currency'] ?? '')));
            if (!$existente && !in_array($currency, $this->companyCurrencies($companyId), true)) {
                throw new \DomainException('La moneda no es de las que opera la empresa.');
            }
        }

        $periodType = (string) ($data['period_type'] ?? '');
        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new \DomainException('Elija si la meta es diaria, semanal, mensual o por período.');
        }

        $red = (float) ($data['cut_red'] ?? 0);
        $yellow = (float) ($data['cut_yellow'] ?? 0);
        $blue = (float) ($data['cut_blue'] ?? 0);
        if ($red <= 0 || $yellow <= 0 || $blue <= 0) {
            throw new \DomainException('Los tres montos deben ser mayores a cero.');
        }
        if (!($red < $yellow && $yellow < $blue)) {
            throw new \DomainException('Los montos deben ir de menor a mayor: rojo < amarillo < azul.');
        }

        // Una sola meta viva por alcance: general de la moneda, o por vendedor.
        $dup = SellerCollectionScale::where('company_id', $companyId)
            ->when(
                $sellerId,
                fn ($q) => $q->where('seller_id', $sellerId),
                fn ($q) => $q->whereNull('seller_id')->where('currency', $currency)
            )
            ->when($id, fn ($q) => $q->where('id', '<>', $id))
            ->exists();
        if ($dup) {
            throw new \DomainException($sellerId
                ? 'Ese vendedor ya tiene una meta propia. Edítela en lugar de crear otra.'
                : "Ya existe la meta general de {$currency}. Edítela en lugar de crear otra.");
        }

        $decimales = FormatoMoneda::decimales($currency);
        $attrs = [
            'company_id' => $companyId,
            'currency' => $currency,
            'seller_id' => $sellerId,
            'period_type' => $periodType,
            'cut_red' => round($red, $decimales),
            'cut_yellow' => round($yellow, $decimales),
            'cut_blue' => round($blue, $decimales),
            'colors' => $this->limpiarColores($data['colors'] ?? null),
        ];

        if ($existente) {
            // updated_by solo en una edición: así "Editó" no aparece en una meta
            // que nadie tocó después de crearla.
            $existente->update($attrs + ['updated_by' => Auth::id()]);
            $scale = $existente;
        } else {
            $scale = SellerCollectionScale::create($attrs + ['created_by' => Auth::id()]);
        }

        return $this->present($scale->fresh(self::RELATIONS));
    }

    /**
     * Colores de los cuatro tramos. Cada uno #RRGGBB; si los cuatro son los de
     * la paleta de siempre (o no vino ninguno) se guarda null, así "volver a
     * los colores originales" deja la meta igual que una sin personalizar.
     */
    private function limpiarColores($colors): ?array
    {
        if (!is_array($colors) || !$colors) {
            return null;
        }

        $out = [];
        foreach (EscalaRecaudo::TONOS as $tono) {
            $hex = EscalaRecaudo::normalizarHex($colors[$tono] ?? null);
            if (!$hex) {
                throw new \DomainException('Cada tramo necesita un color válido (formato #RRGGBB).');
            }
            $out[$tono] = '#' . $hex;
        }

        $original = array_map(fn ($c) => '#' . $c['texto'], EscalaRecaudo::COLORES);
        return $out == $original ? null : $out;
    }

    public function delete(int $companyId, int $id): void
    {
        $scale = SellerCollectionScale::where('company_id', $companyId)->find($id);
        if (!$scale) {
            throw new \DomainException('Meta no encontrada.', 404);
        }
        // El borrado suave solo escribe deleted_at: quién la eliminó se guarda
        // antes, en la misma transacción.
        DB::transaction(function () use ($scale) {
            $scale->forceFill(['deleted_by' => Auth::id()])->save();
            $scale->delete();
        });
    }

    // ---- Interruptor "Mostrar avance al vendedor" ---------------------------

    /** Estado del interruptor de la empresa y sus últimos cambios. */
    public function progressSetting(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);
        if (!$company) {
            throw new \DomainException('Empresa no encontrada.', 404);
        }

        $targets = DB::table('collection_progress_targets')->where('company_id', $companyId)->get();
        $vendedores = $this->companySellers($companyId);

        // Rutas de la empresa (las de sus vendedores), con cuántos tiene cada una.
        $rutas = collect($vendedores)
            ->groupBy('city_id')
            ->map(fn ($g, $cityId) => [
                'id' => (int) $cityId,
                'name' => $g->first()['city_name'],
                'sellers_count' => $g->count(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $scope = $company->collection_progress_scope === 'custom' ? 'custom' : 'all';
        $cityIds = $targets->where('target_type', 'city')->pluck('target_id')->map(fn ($v) => (int) $v)->values()->all();
        $sellerIds = $targets->where('target_type', 'seller')->pluck('target_id')->map(fn ($v) => (int) $v)->values()->all();

        return [
            'enabled' => (bool) $company->show_collection_progress,
            'can_edit' => (int) Auth::user()->role_id === 1,
            'scope' => $scope,
            'city_ids' => $cityIds,
            'seller_ids' => $sellerIds,
            'cities' => $rutas,
            // Cuántos vendedores entran en la audiencia actual.
            'reach' => $scope === 'all'
                ? count($vendedores)
                : collect($vendedores)->filter(
                    fn ($v) => in_array($v['city_id'], $cityIds, true) || in_array($v['id'], $sellerIds, true)
                )->count(),
            'history' => DB::table('collection_progress_audits')
                ->where('company_id', $companyId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn ($a) => [
                    'enabled' => (bool) $a->enabled,
                    'detail' => $a->detail ? json_decode($a->detail, true) : null,
                    'user_name' => $a->user_name,
                    'at' => Carbon::parse($a->created_at)->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Prende o apaga la barra de avance y define QUIÉN la ve:
     *   scope 'all'    → todas las rutas de la empresa;
     *   scope 'custom' → las rutas de $cityIds (con todos sus vendedores,
     *                    también los que se sumen después) MÁS los vendedores
     *                    de $sellerIds. Se suman.
     *   scope null     → se conserva la audiencia actual (p. ej. solo apagar).
     * SOLO el Super-Admin: mostrarle la meta al cobrador es una decisión de
     * negocio que no queda en manos de cada administrador.
     */
    public function setProgress(int $companyId, bool $enabled, ?string $scope = null, array $cityIds = [], array $sellerIds = []): array
    {
        if ((int) Auth::user()->role_id !== 1) {
            throw new \DomainException('Solo el Super-Admin puede cambiar esta opción.', 403);
        }

        $company = \App\Models\Company::find($companyId);
        if (!$company) {
            throw new \DomainException('Empresa no encontrada.', 404);
        }

        $actuales = DB::table('collection_progress_targets')->where('company_id', $companyId)->get();
        $idsActuales = fn ($tipo) => $actuales->where('target_type', $tipo)->pluck('target_id')->map(fn ($v) => (int) $v)->values()->all();

        $cambiaAudiencia = $scope !== null;
        $scope = $scope ?? ($company->collection_progress_scope ?: 'all');
        if (!in_array($scope, ['all', 'custom'], true)) {
            throw new \DomainException('Alcance no válido.');
        }

        if ($cambiaAudiencia && $scope === 'custom') {
            // Solo rutas y vendedores de ESTA empresa: no se puede apuntar a
            // los de otra cambiando ids.
            $vendedores = collect($this->companySellers($companyId));
            $cityIds = array_values(array_intersect(
                array_unique(array_map('intval', $cityIds)),
                $vendedores->pluck('city_id')->unique()->all()
            ));
            $sellerIds = array_values(array_intersect(
                array_unique(array_map('intval', $sellerIds)),
                $vendedores->pluck('id')->all()
            ));
            if (!$cityIds && !$sellerIds) {
                throw new \DomainException('Elija al menos una ruta o un vendedor.');
            }
        } else {
            // 'all' o sin cambio de audiencia: la lista guardada se conserva
            // (si luego se vuelve a 'custom', aparece como estaba).
            $cityIds = $idsActuales('city');
            $sellerIds = $idsActuales('seller');
        }

        $firma = fn ($en, $sc, $c, $s) => json_encode([
            $en, $sc,
            $sc === 'custom' ? collect($c)->sort()->values()->all() : [],
            $sc === 'custom' ? collect($s)->sort()->values()->all() : [],
        ]);
        $antes = $firma(
            (bool) $company->show_collection_progress,
            $company->collection_progress_scope ?: 'all',
            $idsActuales('city'),
            $idsActuales('seller')
        );
        $despues = $firma($enabled, $scope, $cityIds, $sellerIds);

        if ($antes === $despues) {
            return $this->progressSetting($companyId);
        }

        $detalle = ['scope' => $scope];
        if ($scope === 'custom') {
            $detalle['cities'] = DB::table('cities')->whereIn('id', $cityIds ?: [0])->orderBy('name')->pluck('name')->all();
            $detalle['sellers'] = DB::table('sellers as s')->join('users as u', 'u.id', '=', 's.user_id')
                ->whereIn('s.id', $sellerIds ?: [0])->orderBy('u.name')->pluck('u.name')->all();
        }

        DB::transaction(function () use ($company, $enabled, $scope, $cambiaAudiencia, $cityIds, $sellerIds, $detalle) {
            // forceFill: estos campos no están en $fillable a propósito.
            $company->forceFill([
                'show_collection_progress' => $enabled,
                'collection_progress_scope' => $scope,
            ])->save();

            if ($cambiaAudiencia && $scope === 'custom') {
                DB::table('collection_progress_targets')->where('company_id', $company->id)->delete();
                $filas = array_merge(
                    array_map(fn ($id) => ['company_id' => $company->id, 'target_type' => 'city', 'target_id' => $id, 'created_at' => now()], $cityIds),
                    array_map(fn ($id) => ['company_id' => $company->id, 'target_type' => 'seller', 'target_id' => $id, 'created_at' => now()], $sellerIds)
                );
                DB::table('collection_progress_targets')->insert($filas);
            }

            DB::table('collection_progress_audits')->insert([
                'company_id' => $company->id,
                'enabled' => $enabled,
                'detail' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'user_id' => Auth::id(),
                'user_name' => optional(Auth::user())->name,
                'created_at' => now(),
            ]);
        });

        return $this->progressSetting($companyId);
    }

    /**
     * ¿Este vendedor está en la audiencia de la barra? No mira el interruptor
     * (eso lo hace quien llama). Lo decide el servidor: el APK no puede saltarlo.
     */
    public function enAudiencia(int $companyId, int $sellerId, ?int $cityId): bool
    {
        $scope = DB::table('companies')->where('id', $companyId)->value('collection_progress_scope');
        if ($scope !== 'custom') {
            return true;
        }

        return DB::table('collection_progress_targets')
            ->where('company_id', $companyId)
            ->where(function ($q) use ($sellerId, $cityId) {
                $q->where(fn ($w) => $w->where('target_type', 'seller')->where('target_id', $sellerId))
                  ->orWhere(fn ($w) => $w->where('target_type', 'city')->where('target_id', (int) $cityId));
            })
            ->exists();
    }

    // ---- Evaluación (lo que usa el reporte) ---------------------------------

    /**
     * Metas vivas de varias empresas, en una sola consulta:
     *   'general' => ["empresa|MONEDA" => meta]
     *   'seller'  => [seller_id => meta]
     */
    public function scalesFor(array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_filter($companyIds)));
        $out = ['general' => [], 'seller' => []];
        if (!$companyIds) {
            return $out;
        }

        foreach (SellerCollectionScale::whereIn('company_id', $companyIds)->with('seller.user:id,name')->get() as $s) {
            if ($s->seller_id) {
                $out['seller'][$s->seller_id] = $s;
            } else {
                $out['general'][$s->company_id . '|' . strtoupper($s->currency)] = $s;
            }
        }
        return $out;
    }

    /** La meta que aplica a un vendedor: la propia, o la general de su moneda. */
    public function scaleForSeller(array $scales, $sellerId, $companyId, ?string $currency): ?SellerCollectionScale
    {
        return $scales['seller'][$sellerId]
            ?? $scales['general'][$companyId . '|' . strtoupper((string) $currency)]
            ?? null;
    }

    /**
     * Tono de un vendedor para el rango consultado.
     *
     * @return array{tone: ?string, value: ?float, scale: ?array}
     *         tone null = no tiene meta (ni propia ni de su moneda): sin color.
     */
    public function evaluate(?SellerCollectionScale $scale, float $collected, int $workedDays, int $rangeDays): array
    {
        if (!$scale) {
            return ['tone' => null, 'value' => null, 'scale' => null];
        }

        $value = $this->normalize($scale->period_type, $collected, $workedDays, $rangeDays);

        return ['tone' => $this->toneFor($scale, $value), 'value' => round($value, 2), 'scale' => $this->summary($scale)];
    }

    /**
     * En qué tramo cae un valor. Los cortes son INCLUSIVOS hacia arriba: un
     * valor igual al corte del rojo es rojo. Única definición de la regla: la
     * usan el reporte, las descargas y la barra del cobrador.
     */
    public function toneFor(SellerCollectionScale $scale, float $value): string
    {
        if ($value <= $scale->cut_red) {
            return 'rojo';
        }
        if ($value <= $scale->cut_yellow) {
            return 'amarillo';
        }
        if ($value <= $scale->cut_blue) {
            return 'azul';
        }
        return 'verde';
    }

    private function normalize(string $type, float $collected, int $workedDays, int $rangeDays): float
    {
        $rangeDays = max(1, $rangeDays);
        return match ($type) {
            'daily' => $collected / max(1, $workedDays),
            'weekly' => $collected / ($rangeDays / 7),
            'monthly' => $collected / ($rangeDays / 30),
            default => $collected,
        };
    }

    /** Días calendario del rango, ambos extremos incluidos. */
    public static function rangeDays($startDate, $endDate): int
    {
        return Carbon::parse($startDate)->startOfDay()->diffInDays(Carbon::parse($endDate)->startOfDay()) + 1;
    }

    /**
     * Lo que necesita quien dibuja la escala: cortes, tipo, alcance y la
     * leyenda con los montos en el formato de la moneda de su país.
     */
    public function summary(SellerCollectionScale $s): array
    {
        $m = fn ($n) => FormatoMoneda::formatear($n, $s->currency);
        // Texto y fondo de cada tramo, ya resueltos (propios o los de siempre),
        // en hex con '#' para usarlos directo en CSS.
        $paleta = EscalaRecaudo::paleta($s->colors);
        $color = fn ($tono) => [
            'texto' => '#' . $paleta[$tono]['texto'],
            'fondo' => '#' . $paleta[$tono]['fondo'],
        ];

        return [
            'id' => $s->id,
            'currency' => $s->currency,
            'currency_symbol' => FormatoMoneda::simbolo($s->currency),
            'currency_decimals' => FormatoMoneda::decimales($s->currency),
            'scope' => $s->seller_id ? 'seller' : 'company',
            'seller_id' => $s->seller_id,
            'seller_name' => optional(optional($s->seller)->user)->name,
            'period_type' => $s->period_type,
            'period_label' => self::PERIOD_LABELS[$s->period_type] ?? $s->period_type,
            'cut_red' => $s->cut_red,
            'cut_yellow' => $s->cut_yellow,
            'cut_blue' => $s->cut_blue,
            // Lo guardado (para el formulario): null = paleta de siempre.
            'colors' => $s->colors,
            'custom_colors' => !empty($s->colors),
            // Lo que se pinta, tramo por tramo.
            'palette' => array_combine(EscalaRecaudo::TONOS, array_map($color, EscalaRecaudo::TONOS)),
            'legend' => [
                ['tono' => 'rojo', 'etiqueta' => 'hasta ' . $m($s->cut_red)] + $color('rojo'),
                ['tono' => 'amarillo', 'etiqueta' => $m($s->cut_red) . ' a ' . $m($s->cut_yellow)] + $color('amarillo'),
                ['tono' => 'azul', 'etiqueta' => $m($s->cut_yellow) . ' a ' . $m($s->cut_blue)] + $color('azul'),
                ['tono' => 'verde', 'etiqueta' => 'más de ' . $m($s->cut_blue)] + $color('verde'),
            ],
        ];
    }

    private function present(SellerCollectionScale $s): array
    {
        // Las metas sembradas no tienen updated_by; una edición real sí.
        $edited = $s->updated_by && $s->updated_at && $s->created_at && $s->updated_at->gt($s->created_at);

        return $this->summary($s) + [
            'company_id' => $s->company_id,
            'seller_city' => optional(optional($s->seller)->city)->name,
            'created_by_name' => optional($s->creator)->name ?? ($s->created_by ? null : 'Sistema (regla anterior)'),
            'created_at' => optional($s->created_at)->toIso8601String(),
            'updated_by_name' => $edited ? optional($s->editor)->name : null,
            'updated_at' => $edited ? $s->updated_at->toIso8601String() : null,
            'deleted_by_name' => optional($s->remover)->name,
            'deleted_at' => optional($s->deleted_at)->toIso8601String(),
        ];
    }

    /** Moneda; dentro de ella la general primero y luego los vendedores por nombre. */
    private function ordenar($metas): array
    {
        return collect($metas)
            ->sortBy(fn ($m) => sprintf(
                '%s|%d|%s',
                $m['currency'],
                $m['scope'] === 'seller' ? 1 : 0,
                mb_strtolower((string) $m['seller_name'])
            ))
            ->values()
            ->all();
    }
}
