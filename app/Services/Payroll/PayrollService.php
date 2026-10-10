<?php

namespace App\Services\Payroll;

use App\Exceptions\CashClosedException;
use App\Helpers\TimezoneHelper;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\PayrollItemAdjustment;
use App\Models\PayrollRule;
use App\Models\PayrollSetting;
use App\Models\Seller;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\LiquidationService;
use App\Services\MetricsCacheService;
use App\Services\TelegramService;
use App\Services\Traits\EnforcesCashOpen;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina semanal de cobradores.
 *
 * Reglas que conviene tener presentes al tocar esto:
 *  - El recaudo se cuenta igual que en la liquidación diaria
 *    (LiquidationService::calculateLiquidationMetrics): pagos por business_date,
 *    sin anulados, atribuidos por credits.seller_id.
 *  - La nómina es una FOTO: se calcula en borrador y deja de moverse al
 *    aprobarla. Un pago anulado después no la cambia.
 *  - El pago SALE DE LA CAJA del cobrador: es un gasto (Expense) de su ruta en
 *    el día en que se paga, con las mismas guardas que cualquier egreso (día
 *    laborable, liquidación del día no aprobada).
 *  - La moneda es la del país del vendedor. Nunca se suman monedas distintas.
 */
class PayrollService
{
    use EnforcesCashOpen;

    /** ¿credits tiene business_date? Se consulta una sola vez por proceso. */
    private static ?bool $creditsHasBusinessDate = null;

    /** Segundos durante los que un borrador recién calculado no se vuelve a calcular. */
    private const FRESH_SECONDS = 20;

    /** Misma lista que usa la liquidación diaria para "recaudo válido". */
    private const VALID_PAYMENT_STATUSES = ['Pagado', 'Aprobado', 'Abonado'];

    public const EXPENSE_CATEGORY = 'NOMINA';

    /** Texto del gasto con el que la nómina sale de la caja del cobrador. */
    public static function expenseDescription(Payroll $payroll): string
    {
        $from = $payroll->week_start->format('d/m/Y');
        $to = $payroll->week_end->format('d/m/Y');

        // Nómina diaria: el período es un solo día.
        return $from === $to
            ? "PAGO DE NOMINA del día {$from}"
            : "PAGO DE NOMINA del {$from} al {$to}";
    }

    // ------------------------------------------------------------------
    // Alcance
    // ------------------------------------------------------------------

    /**
     * Empresa sobre la que opera el usuario. La nómina siempre es de UNA
     * empresa, así que el Super-Admin tiene que indicar cuál.
     */
    public function resolveCompanyId(Request $request): int
    {
        $user = Auth::user();

        if ((int) $user->role_id === 1) {
            if (!$request->filled('company_id')) {
                throw new PayrollException('Seleccione una empresa para trabajar la nómina.');
            }
            return (int) $request->input('company_id');
        }

        // Admin: su empresa. Roles parametrizables (p. ej. Secretaria): cuelgan
        // del administrador por parent_id y no tienen empresa propia.
        $companyId = optional($user->company)->id
            ?? optional(optional(User::find($user->parent_id))->company)->id;

        if (!$companyId) {
            throw new PayrollException('No se pudo determinar la empresa del usuario.', 403);
        }
        return (int) $companyId;
    }

    public function findPayroll(int $id, int $companyId): Payroll
    {
        $payroll = Payroll::where('company_id', $companyId)->find($id);
        if (!$payroll) {
            throw new PayrollException('Nómina no encontrada.', 404);
        }
        return $payroll;
    }

    public function findItem(int $itemId, int $companyId): PayrollItem
    {
        $item = PayrollItem::with('payroll')->find($itemId);
        if (!$item || !$item->payroll || (int) $item->payroll->company_id !== $companyId) {
            throw new PayrollException('Línea de nómina no encontrada.', 404);
        }
        return $item;
    }

    // ------------------------------------------------------------------
    // Parámetros y reglas
    // ------------------------------------------------------------------

    /** Lecturas que se repiten muchas veces dentro de una misma petición. */
    private array $settingsMemo = [];
    private array $todayMemo = [];

    public function getSettings(int $companyId): array
    {
        if (isset($this->settingsMemo[$companyId])) {
            return $this->settingsMemo[$companyId];
        }
        $s = PayrollSetting::where('company_id', $companyId)->first();
        return $this->settingsMemo[$companyId] = [
            'company_id' => $companyId,
            'period_type' => $s ? (string) $s->period_type : 'weekly',
            'week_start_day' => $s ? (int) $s->week_start_day : 1,
            'auto_pay_at_close' => $s ? (bool) $s->auto_pay_at_close : true,
            'currencies' => $this->companyCurrencies($companyId),
        ];
    }

    public const PERIOD_TYPES = ['daily', 'weekly', 'biweekly', 'monthly'];

    public function updateSettings(int $companyId, array $data): array
    {
        $current = $this->getSettings($companyId);
        $weekStartDay = (int) ($data['week_start_day'] ?? $current['week_start_day']);
        $periodType = (string) ($data['period_type'] ?? $current['period_type']);
        $autoPay = array_key_exists('auto_pay_at_close', $data)
            ? filter_var($data['auto_pay_at_close'], FILTER_VALIDATE_BOOLEAN)
            : $current['auto_pay_at_close'];

        if ($weekStartDay < 1 || $weekStartDay > 7) {
            throw new PayrollException('El día de inicio de semana no es válido.');
        }
        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new PayrollException('El tipo de período no es válido.');
        }
        unset($this->settingsMemo[$companyId]); // se va a cambiar: fuera de la memoria
        PayrollSetting::updateOrCreate(
            ['company_id' => $companyId],
            [
                'period_type' => $periodType,
                'week_start_day' => $weekStartDay,
                'auto_pay_at_close' => $autoPay,
                'updated_by' => Auth::id(),
            ]
        );
        $this->markDraftsStale($companyId);
        return $this->getSettings($companyId);
    }

    /** Monedas en las que opera la empresa (por el país de sus vendedores). */
    public function companyCurrencies(int $companyId): array
    {
        return Seller::where('company_id', $companyId)->with('city.country')->get()
            ->map(fn ($s) => $this->sellerCurrency($s))
            ->unique()->sort()->values()->all();
    }

    /** Vendedores activos de la empresa con su moneda (para elegir excepciones). */
    public function companySellers(int $companyId): array
    {
        return Seller::where('company_id', $companyId)
            ->with(['city.country', 'user:id,name'])
            ->get()
            ->filter(fn ($s) => strtoupper((string) $s->status) === 'ACTIVE' && $s->user)
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->user->name,
                'currency' => $this->sellerCurrency($s),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function sellerCurrency(?Seller $seller): string
    {
        $currency = optional(optional(optional($seller)->city)->country)->currency;
        return $currency ? strtoupper($currency) : 'PEN';
    }

    public function listRules(int $companyId): array
    {
        return PayrollRule::where('company_id', $companyId)
            ->with(['seller.user:id,name'])
            ->orderByRaw('seller_id IS NOT NULL')
            ->orderBy('currency')
            ->get()
            ->map(fn (PayrollRule $r) => $this->presentRule($r))
            ->all();
    }

    private function presentRule(PayrollRule $r): array
    {
        return array_merge($this->ruleSnapshot($r), [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'currency' => $r->currency,
            'seller_id' => $r->seller_id,
            'seller_name' => optional(optional($r->seller)->user)->name,
            'active' => (bool) $r->active,
        ]);
    }

    /** Campos de la regla que definen el cálculo (lo que se congela en el ítem). */
    private function ruleSnapshot(PayrollRule $r): array
    {
        return [
            'period_type' => $r->period_type ?: 'weekly',
            'week_start_day' => (int) ($r->week_start_day ?: 1),
            'collection_mode' => $r->collection_mode,
            'collection_percentage' => (float) $r->collection_percentage,
            'collection_tiers' => PayrollCalculator::sortTiers($r->collection_tiers ?? []),
            'placement_mode' => $r->placement_mode,
            'placement_percentage' => (float) $r->placement_percentage,
            'fixed_salary' => (float) $r->fixed_salary,
            'allowance' => (float) $r->allowance,
            'fixed_deductions' => array_values($r->fixed_deductions ?? []),
        ];
    }

    public function saveRule(int $companyId, array $data, ?int $ruleId = null): array
    {
        $sellerId = !empty($data['seller_id']) ? (int) $data['seller_id'] : null;

        if ($sellerId) {
            $seller = Seller::with('city.country')->where('company_id', $companyId)->find($sellerId);
            if (!$seller) {
                throw new PayrollException('El vendedor no pertenece a la empresa.');
            }
            // La moneda de una excepción es siempre la del país del vendedor.
            $currency = $this->sellerCurrency($seller);
        } else {
            $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
            if (strlen($currency) !== 3) {
                throw new PayrollException('Indique la moneda de la regla.');
            }
        }

        $collectionMode = $data['collection_mode'] ?? 'none';
        $placementMode = $data['placement_mode'] ?? 'none';
        if (!in_array($collectionMode, PayrollCalculator::COLLECTION_MODES, true)
            || !in_array($placementMode, PayrollCalculator::PLACEMENT_MODES, true)) {
            throw new PayrollException('Tipo de comisión no válido.');
        }

        $tiers = $collectionMode === 'tiers' ? $this->cleanTiers($data['collection_tiers'] ?? []) : [];
        if ($collectionMode === 'tiers' && empty($tiers)) {
            throw new PayrollException('Agregue al menos un tramo de recaudo.');
        }

        $pct = (float) ($data['collection_percentage'] ?? 0);
        $pPct = (float) ($data['placement_percentage'] ?? 0);
        if ($pct < 0 || $pct > 100 || $pPct < 0 || $pPct > 100) {
            throw new PayrollException('Los porcentajes deben estar entre 0 y 100.');
        }

        $deductions = [];
        foreach (($data['fixed_deductions'] ?? []) as $d) {
            $concept = trim((string) ($d['concept'] ?? ''));
            $amount = (float) ($d['amount'] ?? 0);
            if ($concept === '' && $amount == 0) {
                continue;
            }
            if ($concept === '' || $amount <= 0) {
                throw new PayrollException('Cada descuento fijo necesita concepto y un monto mayor a cero.');
            }
            $deductions[] = ['concept' => $concept, 'amount' => PayrollCalculator::round($amount, $currency)];
        }

        $fixedSalary = (float) ($data['fixed_salary'] ?? 0);
        $allowance = (float) ($data['allowance'] ?? 0);
        if ($fixedSalary < 0 || $allowance < 0) {
            throw new PayrollException('El sueldo fijo y los viáticos no pueden ser negativos.');
        }

        // Una sola regla viva por alcance: general (empresa + moneda) o por vendedor.
        $dup = PayrollRule::where('company_id', $companyId)
            ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId),
                fn ($q) => $q->whereNull('seller_id')->where('currency', $currency))
            ->when($ruleId, fn ($q) => $q->where('id', '<>', $ruleId))
            ->exists();
        if ($dup) {
            throw new PayrollException($sellerId
                ? 'Ese vendedor ya tiene una regla propia. Edítela en lugar de crear otra.'
                : "Ya existe una regla general para {$currency}. Edítela en lugar de crear otra.");
        }

        // Tipo de nómina de ESTA regla (cada regla el suyo).
        $periodType = (string) ($data['period_type'] ?? 'weekly');
        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new PayrollException('El tipo de nómina no es válido.');
        }
        $weekStartDay = (int) ($data['week_start_day'] ?? 1);
        if ($weekStartDay < 1 || $weekStartDay > 7) {
            throw new PayrollException('El día de inicio de semana no es válido.');
        }

        $attrs = [
            'company_id' => $companyId,
            'currency' => $currency,
            'seller_id' => $sellerId,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'period_type' => $periodType,
            'week_start_day' => $periodType === 'weekly' ? $weekStartDay : 1,
            'collection_mode' => $collectionMode,
            'collection_percentage' => $collectionMode === 'percentage' ? $pct : 0,
            'collection_tiers' => $tiers,
            'placement_mode' => $placementMode,
            'placement_percentage' => $placementMode === 'none' ? 0 : $pPct,
            'fixed_salary' => PayrollCalculator::round($fixedSalary, $currency),
            'allowance' => PayrollCalculator::round($allowance, $currency),
            'fixed_deductions' => $deductions,
            'updated_by' => Auth::id(),
        ];

        if ($ruleId) {
            $rule = PayrollRule::where('company_id', $companyId)->find($ruleId);
            if (!$rule) {
                throw new PayrollException('Regla no encontrada.', 404);
            }
            $rule->update($attrs);
        } else {
            $rule = PayrollRule::create($attrs + ['created_by' => Auth::id()]);
        }

        $this->markDraftsStale($companyId);

        return $this->presentRule($rule->fresh(['seller.user:id,name']));
    }

    private function cleanTiers(array $tiers): array
    {
        $clean = [];
        foreach ($tiers as $t) {
            if (!is_array($t) || !isset($t['from']) || $t['from'] === '') {
                continue;
            }
            $from = (float) $t['from'];
            $pct = (float) ($t['percentage'] ?? 0);
            $bonus = (float) ($t['bonus'] ?? 0);
            if ($from < 0 || $pct < 0 || $pct > 100 || $bonus < 0) {
                throw new PayrollException('Revise los tramos: valores negativos o porcentaje fuera de 0-100.');
            }
            $clean[] = ['from' => $from, 'percentage' => $pct, 'bonus' => $bonus];
        }
        $clean = PayrollCalculator::sortTiers($clean);
        $froms = array_column($clean, 'from');
        if (count($froms) !== count(array_unique($froms))) {
            throw new PayrollException('Hay dos tramos que empiezan en el mismo monto.');
        }
        return $clean;
    }

    public function deleteRule(int $companyId, int $ruleId): void
    {
        $rule = PayrollRule::where('company_id', $companyId)->find($ruleId);
        if (!$rule) {
            throw new PayrollException('Regla no encontrada.', 404);
        }
        $rule->delete();
        $this->markDraftsStale($companyId);
    }

    /**
     * Un cambio de regla o de parámetros debe verse enseguida: se marca a los
     * borradores como no recientes para que la próxima consulta los recalcule,
     * sin esperar la ventana FRESH_SECONDS.
     */
    private function markDraftsStale(int $companyId): void
    {
        unset($this->defaultScheduleMemo[$companyId]);
        Payroll::where('company_id', $companyId)->where('status', Payroll::DRAFT)
            ->update(['updated_at' => now()->subMinutes(30)]);
    }

    // ------------------------------------------------------------------
    // Semana
    // ------------------------------------------------------------------

    /**
     * Período (inicio, fin) de la empresa que contiene la fecha dada:
     *  - daily:    cada día es un período (se liquida en el cierre de ese día).
     *  - weekly:   7 días desde el día de inicio configurado.
     *  - biweekly: quincena calendario, del 1 al 15 y del 16 a fin de mes.
     *  - monthly:  mes calendario.
     */
    public function weekRange(int $companyId, string $date): array
    {
        $sch = $this->defaultSchedule($companyId);
        return self::rangeFor($sch['type'], $sch['start_day'], $date);
    }

    /** Rango (inicio, fin) del período de un tipo dado que contiene la fecha. */
    public static function rangeFor(string $type, int $startDay, string $date): array
    {
        $d = Carbon::parse($date)->startOfDay();

        if ($type === 'daily') {
            return [$d->toDateString(), $d->toDateString()];
        }
        if ($type === 'monthly') {
            return [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString()];
        }
        if ($type === 'biweekly') {
            return $d->day <= 15
                ? [$d->copy()->startOfMonth()->toDateString(), $d->copy()->day(15)->toDateString()]
                : [$d->copy()->day(16)->toDateString(), $d->copy()->endOfMonth()->toDateString()];
        }

        $diff = ($d->dayOfWeekIso - $startDay + 7) % 7;
        $start = $d->copy()->subDays($diff);
        return [$start->toDateString(), $start->copy()->addDays(6)->toDateString()];
    }

    // -- Calendario de pago = tipo de nómina (+ día de inicio si es semanal) --
    //
    // Cada REGLA define el suyo, así que en una empresa conviven varios: los
    // cobradores de ARS pueden ir por semana y los de PEN por quincena. Hay un
    // período (payroll) por cada calendario, y cada cobrador cae en el que le
    // toca según su regla.

    /** @return array{type: string, start_day: int} */
    private function scheduleOfRule(?PayrollRule $rule, int $companyId): array
    {
        if (!$rule) {
            return $this->defaultSchedule($companyId);
        }
        $type = $rule->period_type ?: 'weekly';
        return ['type' => $type, 'start_day' => $type === 'weekly' ? (int) ($rule->week_start_day ?: 1) : 1];
    }

    private array $defaultScheduleMemo = [];

    /**
     * Calendario de los cobradores SIN regla (se listan igual, en cero): el de
     * la primera regla general de la empresa; si no hay ninguna, el de respaldo
     * guardado en los parámetros (semanal desde el lunes).
     */
    private function defaultSchedule(int $companyId): array
    {
        if (isset($this->defaultScheduleMemo[$companyId])) {
            return $this->defaultScheduleMemo[$companyId];
        }
        $rule = PayrollRule::where('company_id', $companyId)->where('active', true)
            ->orderByRaw('seller_id IS NOT NULL')->orderBy('id')->first();
        if ($rule) {
            return $this->defaultScheduleMemo[$companyId] = $this->scheduleOfRule($rule, $companyId);
        }
        $st = $this->getSettings($companyId);
        return $this->defaultScheduleMemo[$companyId] = [
            'type' => $st['period_type'],
            'start_day' => $st['period_type'] === 'weekly' ? (int) $st['week_start_day'] : 1,
        ];
    }

    /** Calendarios de pago en uso en la empresa (uno por combinación distinta). */
    private function schedules(int $companyId): array
    {
        $out = [];
        foreach (PayrollRule::where('company_id', $companyId)->where('active', true)->get() as $rule) {
            $sch = $this->scheduleOfRule($rule, $companyId);
            $out[$sch['type'] . ':' . $sch['start_day']] = $sch;
        }
        $def = $this->defaultSchedule($companyId);
        $out[$def['type'] . ':' . $def['start_day']] = $def;
        return array_values($out);
    }

    /** Calendario al que pertenece un período ya abierto. */
    private function scheduleOfPayroll(Payroll $payroll): array
    {
        $type = $payroll->period_type ?: 'weekly';
        return ['type' => $type, 'start_day' => $type === 'weekly' ? $payroll->week_start->dayOfWeekIso : 1];
    }

    private function sameSchedule(array $a, array $b): bool
    {
        return $a['type'] === $b['type'] && (int) $a['start_day'] === (int) $b['start_day'];
    }

    /** El período de un tipo que arranca en una fecha (incluye anulados). */
    private function container(int $companyId, string $type, string $start)
    {
        return Payroll::where('company_id', $companyId)->where('period_type', $type)->whereDate('week_start', $start);
    }

    /** Regla vigente de un cobrador: la propia o, si no tiene, la general de su moneda. */
    private function ruleForSeller(Seller $seller): ?PayrollRule
    {
        $rules = PayrollRule::where('company_id', $seller->company_id)->where('active', true)
            ->where(fn ($q) => $q->where('seller_id', $seller->id)
                ->orWhere(fn ($q2) => $q2->whereNull('seller_id')->where('currency', $this->sellerCurrency($seller))))
            ->get();
        return $rules->firstWhere('seller_id', $seller->id) ?? $rules->first();
    }

    /** ¿Hay ya una nómina que pise ese rango de fechas? */
    private function overlapping(int $companyId, string $start, string $end, bool $includeVoid)
    {
        return Payroll::where('company_id', $companyId)
            ->whereDate('week_start', '<=', $end)
            ->whereDate('week_end', '>=', $start)
            ->when(!$includeVoid, fn ($q) => $q->where('status', '<>', Payroll::VOID));
    }

    /**
     * Último día en que la ruta TRABAJA dentro del período: ese es el día en
     * que el cobrador cierra el período (si el último día es domingo o feriado
     * y no trabaja, es el anterior).
     */
    public function lastWorkingDay(Seller $seller, string $start, string $end): ?string
    {
        $d = Carbon::parse($end);
        $min = Carbon::parse($start);
        while ($d->gte($min)) {
            if (\App\Services\BusinessCalendar::isWorkingDate($seller, $d->toDateString())) {
                return $d->toDateString();
            }
            $d->subDay();
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Listado / detalle
    // ------------------------------------------------------------------

    /**
     * "Hoy" para la empresa. No hay zona por empresa en financing: se toma la
     * de su primera ruta (casi siempre todas comparten país); el servidor corre
     * en UTC y de noche ya estaría en el día siguiente.
     */
    public function companyToday(int $companyId): string
    {
        if (isset($this->todayMemo[$companyId])) {
            return $this->todayMemo[$companyId];
        }
        $seller = Seller::where('company_id', $companyId)->with('city.country')->orderBy('id')->first();
        return $this->todayMemo[$companyId] = Carbon::now(TimezoneHelper::getSellerTimezone($seller))->toDateString();
    }

    /**
     * Todo lo que necesita la pantalla principal de Nómina, en UNA petición:
     * parámetros, reglas, períodos (para el calendario), listado y el resumen
     * del período en curso. Antes eran cuatro o cinco peticiones, y una de
     * ellas traía el detalle de todos los cobradores solo para pintar un resumen.
     */
    public function overview(int $companyId, int $perPage = 20): array
    {
        $this->syncAutomatic($companyId);

        $rules = $this->listRules($companyId);
        $periods = $this->periods($companyId);
        // Puede haber varios períodos corriendo a la vez (uno por tipo de nómina).
        $currents = collect($periods)->where('current', true)
            ->sortBy(fn ($p) => array_search($p['period_type'], self::PERIOD_TYPES, true))
            ->map(fn ($p) => $this->summary((int) $p['id']))->values()->all();

        return [
            'settings' => $this->getSettings($companyId),
            'rules' => $rules,
            'has_rules' => collect($rules)->contains('active', true),
            'periods' => $periods,
            'list' => $this->list($companyId, $perPage, false),
            'currents' => $currents,
        ];
    }

    /**
     * Cortes de nómina de la empresa (para pintar el calendario): cada período
     * con sus fechas, su estado y si es el que está corriendo hoy.
     */
    public function periods(int $companyId): array
    {
        $today = $this->companyToday($companyId);

        return Payroll::where('company_id', $companyId)->where('status', '<>', Payroll::VOID)
            ->orderByDesc('week_start')->limit(120)
            ->get(['id', 'week_start', 'week_end', 'status', 'period_type'])
            ->map(fn (Payroll $p) => [
                'id' => $p->id,
                'week_start' => $p->week_start->toDateString(),
                'week_end' => $p->week_end->toDateString(),
                'status' => $p->status,
                'period_type' => $p->period_type ?: 'weekly',
                'current' => $p->week_start->toDateString() <= $today && $p->week_end->toDateString() >= $today,
            ])->all();
    }

    /**
     * Resumen liviano de un período: totales por moneda directo de la base,
     * sin cargar las líneas de cada cobrador.
     */
    public function summary(int $payrollId): array
    {
        $payroll = Payroll::findOrFail($payrollId);

        $rows = PayrollItem::where('payroll_id', $payrollId)
            ->selectRaw('currency, COUNT(*) AS sellers, SUM(collection_base) AS collection_base,'
                . ' SUM(CASE WHEN net > 0 THEN net ELSE 0 END) AS net,'
                . ' SUM(CASE WHEN rule_snapshot IS NULL THEN 1 ELSE 0 END) AS without_rule')
            ->groupBy('currency')->orderBy('currency')->get();

        // Regla general vigente de cada moneda (la misma que se aplica al
        // calcular), solo las que pagan con el calendario de ESTE período.
        $sch = $this->scheduleOfPayroll($payroll);
        $general = PayrollRule::where('company_id', $payroll->company_id)->where('active', true)
            ->whereNull('seller_id')->get()
            ->filter(fn ($r) => $this->sameSchedule($this->scheduleOfRule($r, (int) $payroll->company_id), $sch))
            ->keyBy('currency');

        return [
            'id' => $payroll->id,
            'status' => $payroll->status,
            'week_start' => $payroll->week_start->toDateString(),
            'week_end' => $payroll->week_end->toDateString(),
            'period_type' => $payroll->period_type ?: 'weekly',
            'sellers_without_rule' => (int) $rows->sum('without_rule'),
            'totals' => $rows->map(function ($r) use ($general) {
                $rule = isset($general[$r->currency]) ? $this->ruleSnapshot($general[$r->currency]) : null;
                return [
                    'currency' => $r->currency,
                    'sellers' => (int) $r->sellers,
                    'collection_base' => round((float) $r->collection_base, 2),
                    'net' => round((float) $r->net, 2),
                    'rule' => $rule,
                    'rule_label' => $rule ? self::describeRule($rule) : null,
                ];
            })->all(),
        ];
    }

    /**
     * Nómina automática: el sistema abre solo la semana en curso (y la anterior,
     * que es la que toca pagar) y mantiene al día los borradores con el recaudo
     * registrado hasta el momento. Nadie tiene que "generar" ni "recalcular".
     *
     * Se hace al consultar y no con un cron: así no depende de que el
     * schedule:run del servidor esté vivo. No abre nada si la empresa aún no
     * tiene reglas, ni reabre una semana que alguien anuló a propósito.
     */
    public function syncAutomatic(int $companyId): void
    {
        $lock = Cache::lock("payroll_auto_sync_{$companyId}", 30);
        if (!$lock->get()) {
            return; // otra consulta ya lo está haciendo
        }

        try {
            $hasRules = PayrollRule::where('company_id', $companyId)->where('active', true)->exists();

            if ($hasRules) {
                $today = $this->companyToday($companyId);

                // Por cada tipo de nómina en uso: el período de hoy y el anterior.
                foreach ($this->schedules($companyId) as $sch) {
                    $currentStart = self::rangeFor($sch['type'], $sch['start_day'], $today)[0];
                    // Período anterior = el que contiene el día previo al inicio del actual.
                    $previous = Carbon::parse($currentStart)->subDay()->toDateString();

                    foreach ([$previous, $today] as $date) {
                        [$start, $end] = self::rangeFor($sch['type'], $sch['start_day'], $date);
                        // Ya existe ese período de ese tipo (aunque esté anulado): no se reabre.
                        if ($this->container($companyId, $sch['type'], $start)->exists()) {
                            continue;
                        }
                        $type = $sch['type'];
                        DB::transaction(function () use ($companyId, $start, $end, $type) {
                            $payroll = Payroll::create([
                                'company_id' => $companyId,
                                'week_start' => $start,
                                'week_end' => $end,
                                'period_type' => $type,
                                'status' => Payroll::DRAFT,
                                'created_by' => null, // la abrió el sistema
                            ]);
                            $this->calculateItems($payroll);
                        });
                    }
                }
            }

            // Borradores al día (los recién creados ya lo están).
            $drafts = Payroll::where('company_id', $companyId)
                ->where('status', Payroll::DRAFT)
                ->where('updated_at', '<', now()->subSeconds(self::FRESH_SECONDS))
                ->orderByDesc('week_start')->limit(12)->get();
            $todayForSync = $this->companyToday($companyId);
            foreach ($drafts as $draft) {
                // Un período ya terminado casi no cambia: desde el listado se
                // revisa cada 10 minutos, no cada 20 segundos. Al abrir su
                // detalle (show) se actualiza igual.
                $ended = $draft->week_end->toDateString() < $todayForSync;
                if ($ended && $draft->updated_at && $draft->updated_at->gt(now()->subMinutes(10))) {
                    continue;
                }
                DB::transaction(function () use ($draft) {
                    $this->calculateItems($draft);
                    $draft->touch();
                });
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Período de nómina que contiene una fecha: para moverse entre períodos
     * eligiendo cualquier día. Si esa fecha no tiene nómina abierta, devuelve
     * igual el rango que le correspondería.
     */
    public function findByDate(int $companyId, string $date, ?string $type = null): array
    {
        $this->syncAutomatic($companyId);

        // Un mismo día puede caer en varios períodos (uno por tipo de nómina):
        // se prefiere el tipo que se está viendo; si no, el más corto.
        $payroll = $this->overlapping($companyId, $date, $date, false)->get()
            ->sortBy(fn ($p) => [($type && $p->period_type === $type) ? 0 : 1, $p->week_start->diffInDays($p->week_end)])
            ->first();
        [$start, $end] = $payroll
            ? [$payroll->week_start->toDateString(), $payroll->week_end->toDateString()]
            : $this->weekRange($companyId, $date);

        return [
            'payroll_id' => optional($payroll)->id,
            'week_start' => $start,
            'week_end' => $end,
            'future' => $start > $this->companyToday($companyId),
        ];
    }

    /** Períodos vecinos (anterior y siguiente) de una nómina, para las flechas. */
    private function neighbours(Payroll $payroll): array
    {
        // Vecinos del MISMO tipo de nómina: de una semana se pasa a otra semana.
        $sch = $this->scheduleOfPayroll($payroll);
        $base = fn () => Payroll::where('company_id', $payroll->company_id)->where('status', '<>', Payroll::VOID)
            ->where('period_type', $payroll->period_type ?: 'weekly');
        $same = fn ($list) => optional($list->first(fn ($p) => $this->sameSchedule($this->scheduleOfPayroll($p), $sch)))->id;

        return [
            'prev_id' => $same($base()->whereDate('week_end', '<', $payroll->week_start->toDateString())
                ->orderByDesc('week_start')->limit(10)->get()),
            'next_id' => $same($base()->whereDate('week_start', '>', $payroll->week_end->toDateString())
                ->orderBy('week_start')->limit(10)->get()),
        ];
    }

    /** Semana en curso de la empresa, ya calculada con lo recaudado hasta ahora. */
    public function current(int $companyId): array
    {
        $this->syncAutomatic($companyId);

        $today = $this->companyToday($companyId);
        [$start, $end] = $this->weekRange($companyId, $today);
        $payroll = $this->overlapping($companyId, $today, $today, false)->orderByDesc('id')->first();
        if ($payroll) {
            $start = $payroll->week_start->toDateString();
            $end = $payroll->week_end->toDateString();
        }

        return [
            'week_start' => $start,
            'week_end' => $end,
            'has_rules' => PayrollRule::where('company_id', $companyId)->where('active', true)->exists(),
            'payroll' => $payroll ? $this->show($payroll, false) : null,
        ];
    }

    public function list(int $companyId, int $perPage = 20, bool $sync = true): array
    {
        if ($sync) {
            $this->syncAutomatic($companyId);
        }

        $page = Payroll::where('company_id', $companyId)
            ->with(['createdByUser:id,name', 'approvedByUser:id,name'])
            ->orderByDesc('week_start')->orderByDesc('id')
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id');
        $totals = PayrollItem::whereIn('payroll_id', $ids)
            ->selectRaw('payroll_id, currency, COUNT(*) sellers, SUM(net) net, SUM(CASE WHEN status = ? THEN net ELSE 0 END) paid', [PayrollItem::PAID])
            ->groupBy('payroll_id', 'currency')->get()->groupBy('payroll_id');

        $rows = collect($page->items())->map(function (Payroll $p) use ($totals) {
            return [
                'id' => $p->id,
                'week_start' => $p->week_start->toDateString(),
                'week_end' => $p->week_end->toDateString(),
                'period_type' => $p->period_type ?: 'weekly',
                'status' => $p->status,
                'notes' => $p->notes,
                'created_by' => optional($p->createdByUser)->name ?? ($p->created_by === null ? 'Sistema' : null),
                'approved_by' => optional($p->approvedByUser)->name,
                'totals' => ($totals[$p->id] ?? collect())->map(fn ($t) => [
                    'currency' => $t->currency,
                    'sellers' => (int) $t->sellers,
                    'net' => (float) $t->net,
                    'paid' => (float) $t->paid,
                ])->values()->all(),
            ];
        })->all();

        return [
            'data' => $rows,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * @param bool $refresh Un borrador se recalcula al abrirlo: siempre muestra
     *                      el recaudo del momento. Aprobada ya no se mueve.
     */
    public function show(Payroll $payroll, bool $refresh = true): array
    {
        $fresh = $payroll->updated_at && $payroll->updated_at->gt(now()->subSeconds(self::FRESH_SECONDS));
        if ($refresh && $payroll->status === Payroll::DRAFT && !$fresh) {
            DB::transaction(function () use ($payroll) {
                $this->calculateItems($payroll);
                $payroll->touch();
            });
        }
        $this->reconcilePayments($payroll);
        $payroll->refresh()->load(['items.adjustments', 'createdByUser:id,name', 'approvedByUser:id,name', 'company:id,name']);

        $items = $payroll->items->sortBy('seller_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // Cobradores listados que salen en cero porque su moneda no tiene regla.
        $withoutRule = $items->whereNull('rule_snapshot')
            ->groupBy('currency')
            ->map(fn ($g, $cur) => ['currency' => $cur, 'sellers' => $g->count()])
            ->values()->all();

        // Pagos eliminados de la semana, por vendedor (habrían contado como recaudo).
        $deleted = $this->paymentsQuery($items->pluck('seller_id')->all(), $payroll->week_start->toDateString(), $payroll->week_end->toDateString(), true)
            ->groupBy('credits.seller_id')
            ->selectRaw('credits.seller_id, COUNT(*) AS n, SUM(payments.amount) AS total')
            ->get()->keyBy('seller_id');

        $totals = $items->groupBy('currency')->map(function ($group, $currency) use ($deleted) {
            $payable = $group->where('net', '>', 0);
            // Regla general de la moneda: la de cualquier línea que la haya usado.
            $general = optional($group->first(fn ($i) => ($i->rule_snapshot['source'] ?? null) === 'company'))->rule_snapshot;
            $del = $group->map(fn ($i) => $deleted[$i->seller_id] ?? null)->filter();
            return [
                'currency' => $currency,
                'rule' => $general,
                'rule_label' => $general ? self::describeRule($general) : null,
                'sellers_own_rule' => $group->filter(fn ($i) => ($i->rule_snapshot['source'] ?? null) === 'seller')->count(),
                'sellers_without_rule' => $group->whereNull('rule_snapshot')->count(),
                'deleted_payments' => (int) $del->sum('n'),
                'deleted_amount' => round((float) $del->sum('total'), 2),
                'sellers' => $group->count(),
                'collection_base' => round($group->sum('collection_base'), 2),
                'placement_capital' => round($group->sum('placement_capital'), 2),
                'gross' => round($group->sum('gross'), 2),
                'deductions' => round($group->sum('deductions'), 2),
                'net' => round($payable->sum('net'), 2),
                'paid' => round($group->where('status', PayrollItem::PAID)->sum('net'), 2),
                'pending' => round($payable->where('status', PayrollItem::PENDING)->sum('net'), 2),
            ];
        })->values()->all();

        return [
            'id' => $payroll->id,
            'company_id' => $payroll->company_id,
            'company_name' => optional($payroll->company)->name,
            'week_start' => $payroll->week_start->toDateString(),
            'week_end' => $payroll->week_end->toDateString(),
            'status' => $payroll->status,
            'notes' => $payroll->notes,
            'created_by' => optional($payroll->createdByUser)->name ?? ($payroll->created_by === null ? 'Sistema' : null),
            'approved_by' => optional($payroll->approvedByUser)->name,
            'approved_at' => optional($payroll->approved_at)->toDateTimeString(),
            'void_reason' => $payroll->void_reason,
            'week_in_progress' => $payroll->week_end->toDateString() >= $this->companyToday((int) $payroll->company_id),
            'period_type' => $payroll->period_type ?: 'weekly',
            'prev_id' => $this->neighbours($payroll)['prev_id'],
            'next_id' => $this->neighbours($payroll)['next_id'],
            'auto_pay_at_close' => $this->getSettings((int) $payroll->company_id)['auto_pay_at_close'],
            'progress' => $this->buildProgress($payroll, $items),
            'automatic' => $payroll->created_by === null,
            'calculated_at' => optional($payroll->updated_at)->toIso8601String(),
            'sellers_without_rule' => array_sum(array_column($withoutRule, 'sellers')),
            'without_rule' => $withoutRule,
            'sellers_with_pending_days' => $items->filter(fn ($i) => !empty($i->pending_days))->count(),
            'totals' => $totals,
            'items' => $items->map(fn (PayrollItem $i) => $this->presentItem($i))->all(),
        ];
    }

    /**
     * Seguimiento del proceso, paso a paso, para la pantalla del administrador.
     * state: done | active | pending.
     */
    private function buildProgress(Payroll $payroll, $items): array
    {
        $today = $this->companyToday((int) $payroll->company_id);
        $start = $payroll->week_start->toDateString();
        $end = $payroll->week_end->toDateString();
        $ended = $today > $end;

        $payable = $items->filter(fn ($i) => $i->net > 0);
        $paid = $payable->where('status', PayrollItem::PAID);
        $byClose = $paid->where('paid_via', 'cash_close')->count();
        $manual = $paid->count() - $byClose;
        $provisional = $items->filter(fn ($i) => $i->expense_id !== null && $i->status === PayrollItem::PENDING)->count();
        $allPaid = $payable->count() > 0 && $paid->count() === $payable->count();
        $reviewed = in_array($payroll->status, [Payroll::APPROVED, Payroll::PAID], true);

        $cashDetail = $paid->count() . ' de ' . $payable->count() . ' cerraron con su nómina';
        if ($byClose > 0) {
            $cashDetail .= ' · ' . $byClose . ' en su cierre de caja';
        }
        if ($manual > 0) {
            $cashDetail .= ' · ' . $manual . ' liquidado(s) a mano';
        }
        if ($provisional > 0) {
            $cashDetail .= ' · ' . $provisional . ' cerrando hoy';
        }

        $approvedCount = $paid->filter(fn ($i) => $i->approved_at !== null)->count();
        $awaiting = $paid->count() - $approvedCount;

        $steps = [
            [
                'key' => 'period',
                'label' => 'Período en curso',
                'detail' => 'Se calcula sola con el recaudo',
                'state' => $ended ? 'done' : 'active',
            ],
            [
                'key' => 'cut',
                'label' => 'Corte',
                'detail' => 'Último día: ' . $payroll->week_end->format('d/m/Y'),
                'state' => $ended ? 'done' : 'pending',
            ],
            [
                'key' => 'cash',
                'label' => 'Cierre de caja de cobradores',
                'detail' => $cashDetail,
                'state' => $allPaid ? 'done' : (($paid->count() > 0 || $provisional > 0 || $ended) ? 'active' : 'pending'),
            ],
            [
                'key' => 'review',
                'label' => 'Aprobación del administrador',
                'detail' => $approvedCount . ' de ' . $payable->count() . ' aprobado(s)'
                    . ($awaiting > 0 ? ' · ' . $awaiting . ' esperando aprobación' : ''),
                'state' => ($allPaid && $awaiting === 0) ? 'done' : ($awaiting > 0 ? 'active' : 'pending'),
            ],
            [
                'key' => 'closed',
                'label' => 'Período cerrado',
                'detail' => $payroll->status === Payroll::PAID
                    ? 'Todos cerraron y fueron aprobados'
                    : 'Cuando todos cierren y sean aprobados',
                'state' => $payroll->status === Payroll::PAID ? 'done' : 'pending',
            ],
        ];

        return [
            'cancelled' => $payroll->status === Payroll::VOID,
            'steps' => $steps,
        ];
    }

    private function presentItem(PayrollItem $i): array
    {
        return [
            'id' => $i->id,
            'seller_id' => $i->seller_id,
            'seller_name' => $i->seller_name,
            'currency' => $i->currency,
            'rule' => $i->rule_snapshot,
            'collection_base' => $i->collection_base,
            'placement_capital' => $i->placement_capital,
            'placement_interest' => $i->placement_interest,
            'days_with_collection' => (int) $i->days_with_collection,
            'pending_days' => $i->pending_days ?? [],
            'collection_commission' => $i->collection_commission,
            'tier_bonus' => $i->tier_bonus,
            'placement_commission' => $i->placement_commission,
            'fixed_salary' => $i->fixed_salary,
            'allowance' => $i->allowance,
            'bonuses_total' => $i->bonuses_total,
            'fixed_deductions_total' => $i->fixed_deductions_total,
            'manual_deductions_total' => $i->manual_deductions_total,
            'gross' => $i->gross,
            'deductions' => $i->deductions,
            'net' => $i->net,
            'status' => $i->status,
            'expense_id' => $i->expense_id,
            'paid_at' => optional($i->paid_at)->toDateTimeString(),
            'pay_error' => $i->pay_error,
            // Descuento en el cierre de hoy que aún puede moverse con el recaudo.
            'provisional' => $i->expense_id !== null && $i->status === PayrollItem::PENDING,
            'paid_via' => $i->paid_via,
            // Pagada (cerró caja) pero aún sin el visto bueno del administrador.
            'awaiting_approval' => $i->status === PayrollItem::PAID && $i->approved_at === null,
            'approved' => $i->status === PayrollItem::PAID && $i->approved_at !== null,
            'approved_at' => optional($i->approved_at)->toDateTimeString(),
            'rule_label' => self::describeRule($i->rule_snapshot),
            'adjustments' => $i->adjustments->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'concept' => $a->concept, 'amount' => $a->amount,
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Generar / recalcular
    // ------------------------------------------------------------------

    public function generate(int $companyId, string $date, ?string $notes = null): Payroll
    {
        [$start, $end] = $this->weekRange($companyId, $date);

        if ($start > $this->companyToday($companyId)) {
            throw new PayrollException('Ese período todavía no empieza.');
        }

        $periodType = $this->defaultSchedule($companyId)['type'];

        $clash = $this->container($companyId, $periodType, $start)->where('status', '<>', Payroll::VOID)->first();
        if ($clash) {
            throw new PayrollException(
                "Ya existe una nómina para ese período (del {$clash->week_start->toDateString()} al {$clash->week_end->toDateString()})."
            );
        }

        return DB::transaction(function () use ($companyId, $start, $end, $notes, $periodType) {
            $payroll = Payroll::create([
                'company_id' => $companyId,
                'week_start' => $start,
                'week_end' => $end,
                'period_type' => $periodType,
                'status' => Payroll::DRAFT,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $this->calculateItems($payroll);
            return $payroll;
        });
    }

    public function recalculate(Payroll $payroll): Payroll
    {
        $this->assertDraft($payroll);
        DB::transaction(fn () => $this->calculateItems($payroll));
        return $payroll;
    }

    private function assertDraft(Payroll $payroll): void
    {
        if ($payroll->status !== Payroll::DRAFT) {
            throw new PayrollException('La nómina ya no está en borrador: no se puede modificar.');
        }
    }

    /**
     * (Re)calcula las líneas de un borrador. Conserva los ajustes manuales de
     * los vendedores que siguen en la nómina.
     */
    private function calculateItems(Payroll $payroll, ?int $onlySellerId = null): void
    {
        $companyId = (int) $payroll->company_id;
        $start = $payroll->week_start->toDateString();
        $end = $payroll->week_end->toDateString();

        // Lo que ya se descontó en un cierre de caja queda pagado y fijo.
        $this->finalizeCashClose($payroll);

        // Vendedores dados de baja incluidos: pudieron recaudar esa semana.
        $sellers = Seller::withTrashed()->where('company_id', $companyId)
            ->when($onlySellerId, fn ($q) => $q->where('id', $onlySellerId))
            ->with(['city.country', 'user' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->get()->keyBy('id');
        $sellerIds = $sellers->keys()->all();

        $collection = $this->collectionBySeller($sellerIds, $start, $end);
        $placement = $this->placementBySeller($sellerIds, $start, $end);
        $pendingDays = $this->pendingDaysBySeller($sellerIds, $start, $end);

        $rules = PayrollRule::where('company_id', $companyId)->where('active', true)->get();
        $sellerRules = $rules->whereNotNull('seller_id')->keyBy('seller_id');
        $generalRules = $rules->whereNull('seller_id')->keyBy('currency');

        $existing = $payroll->items()->with('adjustments')->get()->keyBy('seller_id');
        $kept = [];

        // Este período es de UN tipo de nómina: solo entran los cobradores cuya
        // regla paga con ese tipo (los sin regla van al calendario de respaldo).
        $payrollSchedule = $this->scheduleOfPayroll($payroll);

        // Cobradores ya liquidados en otro período que pisa estas fechas (pasa
        // si a su regla se le cambió el tipo a mitad de período): no se les
        // vuelve a contar el mismo recaudo aquí.
        $paidElsewhere = PayrollItem::join('payrolls', 'payrolls.id', '=', 'payroll_items.payroll_id')
            ->where('payrolls.company_id', $companyId)
            ->where('payrolls.id', '<>', $payroll->id)
            ->where('payrolls.status', '<>', Payroll::VOID)
            ->whereNull('payrolls.deleted_at')
            ->whereDate('payrolls.week_start', '<=', $end)
            ->whereDate('payrolls.week_end', '>=', $start)
            ->where('payroll_items.status', PayrollItem::PAID)
            ->pluck('payroll_items.seller_id')->flip();

        foreach ($sellers as $seller) {
            $col = $collection[$seller->id] ?? null;
            $pla = $placement[$seller->id] ?? null;
            $isActive = !$seller->trashed() && strtoupper((string) $seller->status) === 'ACTIVE';
            $hasMovement = ($col && $col->total > 0) || ($pla && $pla->capital > 0);

            // Inactivos sin movimiento en la semana no entran.
            if (!$isActive && !$hasMovement && !$existing->has($seller->id)) {
                continue;
            }

            $currency = $this->sellerCurrency($seller);
            $rule = $sellerRules[$seller->id] ?? $generalRules[$currency] ?? null;

            $belongs = $this->sameSchedule($this->scheduleOfRule($rule, $companyId), $payrollSchedule)
                && !$paidElsewhere->has($seller->id);
            if (!$belongs) {
                // No es su período. Si aquí ya cobró (o está cerrando hoy), esa
                // línea se respeta tal cual; si no, sale de este período.
                $prev = $existing[$seller->id] ?? null;
                if ($prev && ($prev->status === PayrollItem::PAID || $prev->expense_id)) {
                    $kept[] = $prev->id;
                }
                continue;
            }

            $snapshot = $rule
                ? $this->ruleSnapshot($rule) + ['source' => $rule->seller_id ? 'seller' : 'company']
                : null;


            $bases = [
                'collection' => (float) ($col->total ?? 0),
                'placement_capital' => (float) ($pla->capital ?? 0),
                'placement_interest' => (float) ($pla->interest ?? 0),
            ];

            $item = $existing[$seller->id] ?? new PayrollItem(['payroll_id' => $payroll->id, 'seller_id' => $seller->id]);

            // Línea ya pagada (en el cierre de caja o a mano): no se recalcula,
            // lo que el cobrador ya cobró no cambia por pagos posteriores.
            if ($item->exists && $item->status === PayrollItem::PAID) {
                $kept[] = $item->id;
                continue;
            }

            $bonuses = $item->exists ? (float) $item->adjustments->where('type', PayrollItemAdjustment::BONUS)->sum('amount') : 0;
            $manual = $item->exists ? (float) $item->adjustments->where('type', PayrollItemAdjustment::DEDUCTION)->sum('amount') : 0;

            $amounts = PayrollCalculator::compute($snapshot, $bases, $currency, $bonuses, $manual);

            $attrs = array_merge($amounts, [
                'seller_user_id' => $seller->user_id,
                'seller_name' => optional($seller->user)->name ?? ('Vendedor #' . $seller->id),
                'currency' => $currency,
                'rule_id' => optional($rule)->id,
                'collection_base' => $bases['collection'],
                'placement_capital' => $bases['placement_capital'],
                'placement_interest' => $bases['placement_interest'],
                'days_with_collection' => (int) ($col->days ?? 0),
                'status' => PayrollItem::PENDING,
            ]);

            // Las columnas JSON se asignan solo si cambiaron de verdad. MySQL
            // reordena las claves al guardar, y Eloquent las compara en orden:
            // sin esto veía "cambios" en cada recálculo y reescribía todas las
            // líneas (156 UPDATE por período) aunque nada hubiera cambiado.
            // `!=` entre arrays compara contenido sin importar el orden.
            $newPending = $pendingDays[$seller->id] ?? [];
            if (!$item->exists || $item->rule_snapshot != $snapshot) {
                $attrs['rule_snapshot'] = $snapshot;
            }
            if (!$item->exists || ($item->pending_days ?? []) != $newPending) {
                $attrs['pending_days'] = $newPending;
            }

            $item->fill($attrs)->save(); // save() no toca la base si no hay nada distinto

            $kept[] = $item->id;
        }

        if ($onlySellerId) {
            return; // cálculo de un solo vendedor: no se toca al resto
        }

        // Vendedores que ya no corresponden (p. ej. cambiaron de empresa).
        $stale = $payroll->items()->whereNotIn('id', $kept)->whereNull('expense_id')->pluck('id');
        if ($stale->isNotEmpty()) {
            PayrollItemAdjustment::whereIn('payroll_item_id', $stale)->delete();
            PayrollItem::whereIn('id', $stale)->delete();
        }
    }

    /** Recaudo de la semana por vendedor. Misma condición que la liquidación diaria. */
    private function collectionBySeller(array $sellerIds, string $start, string $end)
    {
        if (empty($sellerIds)) {
            return collect();
        }
        return DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->whereIn('credits.seller_id', $sellerIds)
            ->whereNull('payments.deleted_at')
            ->whereBetween('payments.business_date', [$start, $end])
            ->whereIn('payments.status', self::VALID_PAYMENT_STATUSES)
            ->groupBy('credits.seller_id')
            ->selectRaw('credits.seller_id, SUM(payments.amount) AS total, COUNT(DISTINCT payments.business_date) AS days')
            ->get()->keyBy('seller_id');
    }

    /**
     * Créditos NUEVOS entregados en la semana por vendedor: capital y el interés
     * pactado de esos créditos. No cuentan los importados (cartera cargada por
     * archivo, no colocada esa semana), las renovaciones ni las unificaciones.
     */
    private function placementBySeller(array $sellerIds, string $start, string $end)
    {
        if (empty($sellerIds)) {
            return collect();
        }
        // La fecha se filtra SIN envolver la columna en una función, para que
        // MySQL pueda usar el índice de business_date. Con
        // COALESCE(business_date, DATE(created_at)) BETWEEN ... recorría ~82.000
        // créditos (0,76 s por período); así lee solo los del rango (0,02 s).
        //
        // Créditos sin business_date (históricos, o entornos donde la columna
        // aún no está migrada) caen a created_at: primero una ventana amplia
        // sobre la columna cruda —un día de margen a cada lado por la zona
        // horaria— y recién después el DATE() exacto.
        $desde = Carbon::parse($start)->subDay()->toDateString() . ' 00:00:00';
        $hasta = Carbon::parse($end)->addDays(2)->toDateString() . ' 00:00:00';
        $porCreatedAt = fn ($q) => $q
            ->where('credits.created_at', '>=', $desde)
            ->where('credits.created_at', '<', $hasta)
            ->whereRaw('DATE(credits.created_at) BETWEEN ? AND ?', [$start, $end]);

        $hasBusinessDate = self::$creditsHasBusinessDate ??= Schema::hasColumn('credits', 'business_date');

        return DB::table('credits')
            ->whereIn('seller_id', $sellerIds)
            ->whereNull('deleted_at')
            ->whereNull('imported_at')
            ->whereNull('renewed_from_id')
            ->whereNull('unification_reason')
            ->where(function ($q) use ($start, $end, $porCreatedAt, $hasBusinessDate) {
                if (!$hasBusinessDate) {
                    $porCreatedAt($q);
                    return;
                }
                $q->whereBetween('credits.business_date', [$start, $end])
                    ->orWhere(fn ($q2) => $porCreatedAt($q2->whereNull('credits.business_date')));
            })
            ->groupBy('seller_id')
            ->selectRaw('seller_id, SUM(credit_value) AS capital, SUM(credit_value * COALESCE(total_interest, 0) / 100) AS interest')
            ->get()->keyBy('seller_id');
    }

    /** Días de la semana cuya liquidación existe pero aún no fue aprobada. */
    private function pendingDaysBySeller(array $sellerIds, string $start, string $end): array
    {
        if (empty($sellerIds)) {
            return [];
        }
        $rows = DB::table('liquidations')
            ->whereIn('seller_id', $sellerIds)
            ->whereNull('deleted_at')
            ->where('date', '>=', $start . ' 00:00:00')
            ->where('date', '<', Carbon::parse($end)->addDay()->toDateString() . ' 00:00:00')
            ->where('status', '<>', 'approved')
            ->selectRaw('seller_id, DATE(date) AS d')
            ->orderBy('date')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->seller_id][] = $r->d;
        }
        return array_map(fn ($days) => array_values(array_unique($days)), $out);
    }

    // ------------------------------------------------------------------
    // Ajustes manuales
    // ------------------------------------------------------------------

    public function addAdjustment(PayrollItem $item, array $data): void
    {
        $this->assertDraft($item->payroll);

        $type = $data['type'] ?? '';
        $concept = trim((string) ($data['concept'] ?? ''));
        $amount = PayrollCalculator::round((float) ($data['amount'] ?? 0), $item->currency);

        if (!in_array($type, [PayrollItemAdjustment::BONUS, PayrollItemAdjustment::DEDUCTION], true)) {
            throw new PayrollException('Indique si es un bono o un descuento.');
        }
        if ($concept === '' || $amount <= 0) {
            throw new PayrollException('El ajuste necesita un concepto y un monto mayor a cero.');
        }

        DB::transaction(function () use ($item, $type, $concept, $amount) {
            PayrollItemAdjustment::create([
                'payroll_item_id' => $item->id,
                'type' => $type,
                'concept' => $concept,
                'amount' => $amount,
                'created_by' => Auth::id(),
            ]);
            $this->refreshItemTotals($item);
        });
    }

    public function removeAdjustment(int $adjustmentId, int $companyId): void
    {
        $adj = PayrollItemAdjustment::with('item.payroll')->find($adjustmentId);
        if (!$adj || !$adj->item || (int) optional($adj->item->payroll)->company_id !== $companyId) {
            throw new PayrollException('Ajuste no encontrado.', 404);
        }
        $this->assertDraft($adj->item->payroll);

        DB::transaction(function () use ($adj) {
            $item = $adj->item;
            $adj->delete();
            $this->refreshItemTotals($item);
        });
    }

    /** Recalcula los totales de una línea con sus bases y regla YA guardadas. */
    private function refreshItemTotals(PayrollItem $item): void
    {
        $adjs = $item->adjustments()->get();
        $amounts = PayrollCalculator::compute(
            $item->rule_snapshot,
            [
                'collection' => $item->collection_base,
                'placement_capital' => $item->placement_capital,
                'placement_interest' => $item->placement_interest,
            ],
            $item->currency,
            (float) $adjs->where('type', PayrollItemAdjustment::BONUS)->sum('amount'),
            (float) $adjs->where('type', PayrollItemAdjustment::DEDUCTION)->sum('amount')
        );
        $item->fill($amounts)->save();
    }

    // ------------------------------------------------------------------
    // Aprobar / anular
    // ------------------------------------------------------------------

    public function approve(Payroll $payroll): Payroll
    {
        $this->assertDraft($payroll);

        if (!$payroll->items()->exists()) {
            throw new PayrollException('La nómina no tiene vendedores.');
        }

        DB::transaction(function () use ($payroll) {
            // Sin nada que cobrar (neto <= 0): no genera pago.
            $payroll->items()->where('net', '<=', 0)->update(['status' => PayrollItem::NO_PAYMENT]);
            $payroll->update([
                'status' => Payroll::APPROVED,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
            $this->syncPaidStatus($payroll);
        });

        return $payroll;
    }

    public function void(Payroll $payroll, ?string $reason): Payroll
    {
        if ($payroll->status === Payroll::VOID) {
            throw new PayrollException('La nómina ya está anulada.');
        }
        $this->reconcilePayments($payroll);
        if ($payroll->items()->where('status', PayrollItem::PAID)->exists()) {
            throw new PayrollException('Hay pagos realizados. Anule primero esos pagos y luego la nómina.');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new PayrollException('Indique el motivo de la anulación.');
        }

        // Descuentos del cierre de hoy que aún no quedaron firmes: se retiran.
        foreach ($payroll->items()->whereNotNull('expense_id')->where('status', PayrollItem::PENDING)->get() as $item) {
            $this->removeProvisionalExpense($item);
        }

        $payroll->update([
            'status' => Payroll::VOID,
            'voided_by' => Auth::id(),
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);
        return $payroll;
    }

    // ------------------------------------------------------------------
    // Descuento automático en el cierre de caja
    // ------------------------------------------------------------------

    /**
     * El último día laborable del período, la nómina del cobrador se descuenta
     * de su caja como un gasto del día. Se llama cada vez que se carga su
     * pantalla de cierre (y antes de cerrar): así el gasto YA existe cuando el
     * cobrador ve "Gastos del día" y el cierre sale con la nómina descontada.
     *
     * Mientras la caja del día siga abierta el monto acompaña al recaudo (un
     * cobro más cambia la comisión). Al cerrarse la caja queda firme.
     *
     * Nunca debe impedir un cierre: quien lo llama lo envuelve en try/catch.
     *
     * @param bool $allowPast El cierre automático puede cerrar un día ya pasado.
     * @return array{changed: bool, amount: float} changed = el gasto se creó,
     *         cambió de monto o se retiró en esta llamada.
     */
    public function syncCashClose(int $sellerId, string $date, bool $allowPast = false): array
    {
        $none = ['changed' => false, 'amount' => 0.0];

        $seller = Seller::with('city.country')->find($sellerId);
        if (!$seller || !$seller->user_id || !$seller->company_id) {
            return $none;
        }
        $companyId = (int) $seller->company_id;
        $date = substr($date, 0, 10);

        $settings = PayrollSetting::where('company_id', $companyId)->first();
        if ($settings && !$settings->auto_pay_at_close) {
            return $none;
        }
        if (!PayrollRule::where('company_id', $companyId)->where('active', true)->exists()) {
            return $none;
        }

        $today = TimezoneHelper::getBusinessNow($seller)->toDateString();
        if ($date > $today || ($date < $today && !$allowPast)) {
            return $none;
        }

        // Período del tipo de nómina de SU regla que contiene ese día.
        $sch = $this->scheduleOfRule($this->ruleForSeller($seller), $companyId);
        [$start, $end] = self::rangeFor($sch['type'], $sch['start_day'], $date);
        $payroll = $this->container($companyId, $sch['type'], $start)->orderByDesc('id')->first();

        if ($this->lastWorkingDay($seller, $start, $end) !== $date) {
            return $none; // no es el día en que este cobrador cierra el período
        }

        $lock = Cache::lock("payroll_cash_close_{$sellerId}_{$date}", 30);
        if (!$lock->get()) {
            return $none;
        }

        try {
            if (!$payroll) {
                $periodType = $sch['type'];
                $payroll = DB::transaction(function () use ($companyId, $start, $end, $periodType) {
                    $p = Payroll::create([
                        'company_id' => $companyId,
                        'week_start' => $start,
                        'week_end' => $end,
                        'period_type' => $periodType,
                        'status' => Payroll::DRAFT,
                        'created_by' => null,
                    ]);
                    $this->calculateItems($p);
                    return $p;
                });
            }

            if (!in_array($payroll->status, [Payroll::DRAFT, Payroll::APPROVED], true)) {
                return $none; // anulada o ya pagada
            }

            // Caja del día ya cerrada: no se tocan sus gastos.
            $closed = \App\Models\Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', $date)
                ->whereIn('status', ['pending', 'auto', 'approved'])
                ->exists();
            if ($closed) {
                $this->finalizeCashClose($payroll);
                return $none;
            }

            // En borrador se recalcula SOLO este cobrador con su recaudo al momento.
            if ($payroll->status === Payroll::DRAFT) {
                DB::transaction(fn () => $this->calculateItems($payroll, $sellerId));
            }

            $item = $payroll->items()->where('seller_id', $sellerId)->first();
            if (!$item || $item->status === PayrollItem::PAID) {
                return $none;
            }

            $desired = $item->net > 0 ? (float) $item->net : 0.0;
            $expense = $item->expense_id ? Expense::find($item->expense_id) : null;
            $changed = false;

            if (!$expense && $desired > 0) {
                $stamp = $date === $today
                    ? TimezoneHelper::businessStampForSeller($seller)
                    : [
                        'business_timestamp' => $date . ' 23:59:00',
                        'business_date' => $date,
                        'business_timezone' => TimezoneHelper::getSellerTimezone($seller),
                    ];
                $category = Category::firstOrCreate(['name' => self::EXPENSE_CATEGORY]);

                DB::transaction(function () use ($item, $payroll, $seller, $stamp, $category, $desired) {
                    $expense = Expense::create([
                        'value' => $desired,
                        'description' => self::expenseDescription($payroll),
                        'user_id' => $seller->user_id,
                        'created_by' => null, // lo registró el sistema en el cierre
                        'category_id' => $category->id,
                        'status' => 'Aprobado',
                        'client_timezone' => $stamp['business_timezone'],
                        'business_timezone' => $stamp['business_timezone'],
                        'business_timestamp' => $stamp['business_timestamp'],
                        'business_date' => $stamp['business_date'],
                    ]);
                    $item->update(['expense_id' => $expense->id, 'pay_error' => null]);
                });
                $changed = true;
            } elseif ($expense && $desired <= 0) {
                $this->removeProvisionalExpense($item, false);
                $changed = true;
            } elseif ($expense && abs((float) $expense->value - $desired) > 0.004) {
                $expense->update(['value' => $desired]);
                $changed = true;
            }

            if ($changed) {
                $this->afterCashMovement($sellerId, $date);
            }

            return ['changed' => $changed, 'amount' => $desired];
        } finally {
            $lock->release();
        }
    }

    /**
     * Vista previa de lo que pasará cuando este cobrador cierre su caja el
     * último día del período. SOLO LECTURA: no crea el gasto ni toca la caja.
     *
     * Usa la nómina tal como va hoy; el día del cierre el monto será el que
     * tenga con el recaudo de ese momento.
     */
    public function closePreview(PayrollItem $item): array
    {
        $payroll = $item->payroll;
        $start = $payroll->week_start->toDateString();
        $end = $payroll->week_end->toDateString();

        $seller = Seller::withTrashed()->with('city.country')->find($item->seller_id);
        $today = $seller ? TimezoneHelper::getBusinessNow($seller)->toDateString() : $this->companyToday((int) $payroll->company_id);
        $closeDay = $seller ? $this->lastWorkingDay($seller, $start, $end) : $end;
        $settings = $this->getSettings((int) $payroll->company_id);

        // Caja de hoy del cobrador, tal como está (si ya tiene liquidación abierta).
        $liq = \App\Models\Liquidation::where('seller_id', $item->seller_id)->whereDate('date', $today)->first();
        $expenseLive = $item->expense_id ? Expense::find($item->expense_id) : null;
        // Si el descuento ya está en su caja (cerrando hoy), "antes" es sin él.
        $already = $expenseLive ? (float) $expenseLive->value : 0.0;

        $net = max(0.0, (float) $item->net);
        $toDeliver = $liq ? (float) $liq->real_to_deliver + $already : null;
        $expenses = $liq ? (float) $liq->total_expenses - $already : null;

        return [
            'seller_name' => $item->seller_name,
            'currency' => $item->currency,
            'week_start' => $start,
            'week_end' => $end,
            'today' => $today,
            'close_day' => $closeDay,
            'days_left' => $closeDay ? max(0, Carbon::parse($today)->diffInDays(Carbon::parse($closeDay), false)) : null,
            'is_close_day' => $closeDay === $today,
            'auto_pay_at_close' => $settings['auto_pay_at_close'],
            'has_rule' => $item->rule_snapshot !== null,
            'rule_label' => self::describeRule($item->rule_snapshot),
            'already_settled' => $item->status === PayrollItem::PAID,
            'in_progress' => $item->expense_id !== null && $item->status === PayrollItem::PENDING,
            'collection_base' => $item->collection_base,
            'net' => $net,
            'expense_description' => self::expenseDescription($payroll),
            // Cómo se vería SU cierre con la caja de hoy (null si hoy no abrió caja).
            'cash' => $liq ? [
                'liquidation_status' => $liq->status,
                'collected_today' => (float) $liq->total_collected,
                'expenses_before' => round($expenses, 2),
                'expenses_after' => round($expenses + $net, 2),
                'to_deliver_before' => round($toDeliver, 2),
                'to_deliver_after' => round($toDeliver - $net, 2),
            ] : null,
        ];
    }

    /**
     * Descuentos que ya quedaron firmes: la línea tiene su gasto y la caja de
     * ese día ya se cerró (por el cobrador, el supervisor o el cierre
     * automático). Pasan a "pagado" y dejan de recalcularse.
     */
    private function finalizeCashClose(Payroll $payroll): void
    {
        $items = $payroll->items()->whereNotNull('expense_id')->where('status', PayrollItem::PENDING)->with('expense')->get();

        foreach ($items as $item) {
            $expense = $item->expense;
            if (!$expense || $expense->trashed()) {
                $item->update(['expense_id' => null]); // lo eliminaron desde Gastos
                continue;
            }
            $date = $expense->business_date instanceof \DateTimeInterface
                ? $expense->business_date->format('Y-m-d')
                : substr((string) $expense->business_date, 0, 10);

            $liq = \App\Models\Liquidation::where('seller_id', $item->seller_id)
                ->whereDate('date', $date)
                ->whereIn('status', ['pending', 'auto', 'approved'])
                ->first();
            if (!$liq) {
                continue; // la caja sigue abierta: el monto todavía puede moverse
            }

            // Lo que valió el gasto al cerrar es lo que se pagó.
            $item->update([
                'status' => PayrollItem::PAID,
                'paid_at' => $liq->closed_at ?? now(),
                'paid_by' => $liq->closed_by,
                'paid_via' => 'cash_close',
                'pay_error' => abs((float) $expense->value - (float) $item->net) > 0.004
                    ? 'Se descontó ' . $expense->value . ' en el cierre; el cálculo actual da ' . $item->net . '.'
                    : null,
            ]);
        }
    }

    /** Retira el gasto de un descuento que aún no quedó firme. */
    private function removeProvisionalExpense(PayrollItem $item, bool $recalc = true): void
    {
        $expense = $item->expense_id ? Expense::find($item->expense_id) : null;
        if ($expense) {
            $date = $expense->business_date instanceof \DateTimeInterface
                ? $expense->business_date->format('Y-m-d')
                : substr((string) $expense->business_date, 0, 10);
            if (Schema::hasColumn('expenses', 'deleted_by')) {
                $expense->deleted_by = Auth::id();
                $expense->save();
            }
            $expense->delete();
            if ($recalc) {
                $this->afterCashMovement((int) $item->seller_id, $date);
            }
        }
        $item->update(['expense_id' => null]);
    }

    // ------------------------------------------------------------------
    // Pago (sale de la caja del cobrador)
    // ------------------------------------------------------------------

    /**
     * Paga una línea: registra un gasto en la ruta del cobrador HOY (día de
     * negocio del vendedor), con las guardas de cualquier egreso.
     */
    public function payItem(PayrollItem $item): PayrollItem
    {
        $lock = Cache::lock("payroll_pay_item_{$item->id}", 30);
        if (!$lock->get()) {
            throw new PayrollException('Ese pago ya se está procesando. Espere un momento.');
        }

        try {
            $item->refresh()->load('payroll');
            $payroll = $item->payroll;

            if ($payroll->status === Payroll::VOID) {
                throw new PayrollException('El período está anulado.');
            }
            if ($item->status === PayrollItem::PAID) {
                throw new PayrollException('La nómina de este cobrador ya fue liquidada.');
            }
            // Cada cobrador liquida al cerrar su caja el último día. A mano
            // solo se liquida al que se le pasó, con el período ya terminado.
            if ($payroll->week_end->toDateString() >= $this->companyToday((int) $payroll->company_id)) {
                throw new PayrollException(
                    'El período todavía no termina. Este cobrador liquida su nómina al cerrar su caja el último día.'
                );
            }
            // Con el período terminado se toma su recaudo final antes de pagar.
            if ($payroll->status === Payroll::DRAFT && !$item->expense_id) {
                DB::transaction(fn () => $this->calculateItems($payroll, (int) $item->seller_id));
                $item->refresh();
            }
            if ($item->net <= 0) {
                throw new PayrollException('Esa línea no tiene monto por pagar.');
            }
            if ($item->expense_id) {
                throw new PayrollException('Esta nómina ya se está descontando en el cierre de caja de hoy del cobrador.');
            }

            $seller = Seller::with('city.country')->find($item->seller_id);
            if (!$seller || !$seller->user_id) {
                throw new PayrollException('El vendedor ya no está activo: no tiene caja de la cual descontar el pago.');
            }

            $stamp = TimezoneHelper::businessStampForSeller($seller);
            $businessDate = $stamp['business_date'];

            try {
                $this->assertSellerWorksOnDate($seller, $businessDate);
                // Quien paga la nómina opera como administrador de la caja: solo
                // lo frena una liquidación del día ya APROBADA (sellada).
                $this->assertExpenseCashOpen((int) $seller->id, $businessDate, true);
            } catch (CashClosedException $e) {
                throw new PayrollException($item->seller_name . ': ' . $e->getMessage());
            }

            $category = Category::firstOrCreate(['name' => self::EXPENSE_CATEGORY]);

            $expense = DB::transaction(function () use ($item, $payroll, $seller, $stamp, $category) {
                $expense = Expense::create([
                    'value' => $item->net,
                    'description' => self::expenseDescription($payroll),
                    'user_id' => $seller->user_id,
                    'created_by' => Auth::id(),
                    'category_id' => $category->id,
                    'status' => 'Aprobado',
                    'client_timezone' => $stamp['business_timezone'],
                    'business_timezone' => $stamp['business_timezone'],
                    'business_timestamp' => $stamp['business_timestamp'],
                    'business_date' => $stamp['business_date'],
                ]);

                $item->update([
                    'status' => PayrollItem::PAID,
                    'expense_id' => $expense->id,
                    'paid_at' => now(),
                    'paid_by' => Auth::id(),
                    'paid_via' => 'manual',
                    'approved_at' => now(),
                    'approved_by' => Auth::id(),
                    'pay_error' => null,
                ]);

                return $expense;
            });

            $this->afterCashMovement((int) $seller->id, $businessDate);

            try {
                app(TelegramService::class)->notifyNewExpense($expense);
            } catch (\Throwable $e) {
                Log::warning('[payroll.pay] telegram', ['expense_id' => $expense->id, 'error' => $e->getMessage()]);
            }

            $this->syncPaidStatus($payroll);
            return $item->fresh();
        } finally {
            $lock->release();
        }
    }

    /** Paga todas las líneas pendientes. Devuelve qué se pagó y qué no (con motivo). */
    public function payAll(Payroll $payroll): array
    {
        if ($payroll->status === Payroll::VOID) {
            throw new PayrollException('El período está anulado.');
        }

        $paid = [];
        $failed = [];
        // Los que están cerrando hoy ya tienen su gasto: no se pagan dos veces.
        $items = $payroll->items()->where('status', PayrollItem::PENDING)->where('net', '>', 0)
            ->whereNull('expense_id')->get();

        foreach ($items as $item) {
            try {
                $this->payItem($item);
                $paid[] = $item->seller_name;
            } catch (PayrollException $e) {
                $item->update(['pay_error' => mb_substr($e->getMessage(), 0, 250)]);
                $failed[] = ['seller' => $item->seller_name, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                Log::error('[payroll.payAll] ' . $e->getMessage(), ['item_id' => $item->id]);
                $failed[] = ['seller' => $item->seller_name, 'reason' => 'Error inesperado al registrar el pago.'];
            }
        }

        return ['paid' => $paid, 'failed' => $failed];
    }

    /**
     * Aprobación del administrador sobre la nómina que un cobrador ya liquidó
     * al cerrar su caja. No mueve plata (el descuento ya está en su
     * liquidación): es el visto bueno que la deja firme.
     */
    public function approveItem(PayrollItem $item): PayrollItem
    {
        if ($item->status !== PayrollItem::PAID) {
            throw new PayrollException('Este cobrador todavía no cerró su caja con la nómina: no hay nada que aprobar.');
        }
        if ($item->approved_at) {
            throw new PayrollException('La nómina de este cobrador ya está aprobada.');
        }
        $item->update(['approved_at' => now(), 'approved_by' => Auth::id()]);
        $this->syncPaidStatus($item->payroll);
        return $item->fresh();
    }

    /** Aprueba de una vez a todos los que ya cerraron caja. Devuelve cuántos. */
    public function approveClosed(Payroll $payroll): int
    {
        $this->finalizeCashClose($payroll);
        $count = $payroll->items()->where('status', PayrollItem::PAID)->whereNull('approved_at')
            ->update(['approved_at' => now(), 'approved_by' => Auth::id()]);
        $this->syncPaidStatus($payroll);
        return $count;
    }

    /** Anula el pago de una línea: elimina el gasto por el camino normal de Gastos. */
    public function unpayItem(PayrollItem $item): PayrollItem
    {
        if ($item->status !== PayrollItem::PAID || !$item->expense_id) {
            throw new PayrollException('Esa línea no tiene un pago registrado.');
        }

        $expense = Expense::withTrashed()->find($item->expense_id);
        if ($expense && !$expense->trashed()) {
            $resp = app(ExpenseService::class)->delete($expense->id);
            if ($resp->getStatusCode() !== 200) {
                $body = json_decode($resp->getContent(), true) ?: [];
                throw new PayrollException($body['message'] ?? 'No se pudo anular el gasto del pago.');
            }
            $seller = Seller::withTrashed()->find($item->seller_id);
            if ($seller) {
                $date = $expense->business_date instanceof \DateTimeInterface
                    ? $expense->business_date->format('Y-m-d')
                    : substr((string) $expense->business_date, 0, 10);
                $this->afterCashMovement((int) $seller->id, $date);
            }
        }

        $item->update([
            'status' => PayrollItem::PENDING,
            'expense_id' => null,
            'paid_at' => null,
            'paid_by' => null,
            'paid_via' => null,
            'approved_at' => null,
            'approved_by' => null,
            'pay_error' => null,
        ]);
        $this->syncPaidStatus($item->payroll);

        return $item->fresh();
    }

    /** Igual que al crear un gasto: recalcula la caja del día y las siguientes. */
    private function afterCashMovement(int $sellerId, string $businessDate): void
    {
        $liq = app(LiquidationService::class);
        $liq->recalculateLiquidation($sellerId, $businessDate);
        $liq->recalculateNextLiquidations($sellerId, $businessDate);
        app(MetricsCacheService::class)->invalidateLiquidationMetrics($sellerId, $businessDate);
    }

    /**
     * El gasto del pago vive en Gastos y alguien puede eliminarlo o editarlo
     * allí. Al abrir la nómina se pone al día: gasto eliminado = pago anulado.
     */
    private function reconcilePayments(Payroll $payroll): void
    {
        $this->finalizeCashClose($payroll);

        $paidItems = $payroll->items()->where('status', PayrollItem::PAID)->with('expense')->get();
        foreach ($paidItems as $item) {
            $expense = $item->expense;
            if (!$expense || $expense->trashed()) {
                $item->update([
                    'status' => PayrollItem::PENDING,
                    'expense_id' => null,
                    'paid_at' => null,
                    'paid_by' => null,
                    'paid_via' => null,
                    'approved_at' => null,
                    'approved_by' => null,
                    'pay_error' => 'El gasto de este pago fue eliminado desde Gastos: quedó pendiente de pago.',
                ]);
            } elseif (abs((float) $expense->value - (float) $item->net) > 0.004) {
                $item->update([
                    'pay_error' => 'El gasto de este pago fue modificado en Gastos (valor ' . $expense->value . ').',
                ]);
            }
        }
        $this->syncPaidStatus($payroll);
    }

    /** approved <-> paid según queden o no líneas por pagar. */
    private function syncPaidStatus(Payroll $payroll): void
    {
        $payroll->refresh();
        if ($payroll->status === Payroll::VOID) {
            return;
        }
        $ended = $payroll->week_end->toDateString() < $this->companyToday((int) $payroll->company_id);
        $pending = $payroll->items()->where('status', PayrollItem::PENDING)->where('net', '>', 0)->exists();
        $anyPaid = $payroll->items()->where('status', PayrollItem::PAID)->exists();
        $unapproved = $payroll->items()->where('status', PayrollItem::PAID)->whereNull('approved_at')->exists();

        $open = $payroll->approved_at ? Payroll::APPROVED : Payroll::DRAFT;
        $target = ($ended && !$pending && $anyPaid && !$unapproved) ? Payroll::PAID : $open;

        if ($payroll->status !== $target) {
            if ($target === Payroll::PAID) {
                // Los que quedaron sin nada que cobrar no generan pago.
                $payroll->items()->where('status', PayrollItem::PENDING)->where('net', '<=', 0)
                    ->update(['status' => PayrollItem::NO_PAYMENT]);
            }
            $payroll->update(['status' => $target]);
        }
    }

    // ------------------------------------------------------------------
    // Pagos del corte (de dónde sale el recaudo) y pagos eliminados
    // ------------------------------------------------------------------

    /**
     * Pagos de clientes de la semana para un grupo de vendedores. Con
     * $deleted = false son los que SUMAN al recaudo (misma condición que el
     * cálculo); con true, los que se eliminaron y por eso ya no suman.
     */
    private function paymentsQuery(array $sellerIds, string $start, string $end, bool $deleted)
    {
        $q = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->whereIn('credits.seller_id', $sellerIds ?: [0])
            ->whereBetween('payments.business_date', [$start, $end])
            ->whereIn('payments.status', self::VALID_PAYMENT_STATUSES);

        return $deleted ? $q->whereNotNull('payments.deleted_at') : $q->whereNull('payments.deleted_at');
    }

    private function paymentRows($query, int $limit = 2000): array
    {
        $rows = $query
            ->leftJoin('clients', 'credits.client_id', '=', 'clients.id')
            ->leftJoin('users as cu', 'payments.created_by', '=', 'cu.id')
            ->leftJoin('users as du', 'payments.deleted_by', '=', 'du.id')
            ->select(
                'payments.id', 'payments.credit_id', 'payments.amount', 'payments.status',
                'payments.payment_method', 'payments.business_date', 'payments.business_timestamp',
                'payments.business_timezone', 'payments.deleted_at',
                'credits.seller_id', 'clients.name as client_name',
                'cu.name as registered_by', 'du.name as deleted_by_name'
            )
            ->orderBy('payments.business_date')->orderBy('payments.id')
            ->limit($limit)->get();

        $appTz = config('app.timezone');

        return $rows->map(function ($p) use ($appTz) {
            $tz = $p->business_timezone ?: 'America/Lima';
            // business_timestamp ya es hora local (se muestra tal cual).
            // deleted_at lo graba Eloquent en la zona de la app: se pasa a la
            // zona del negocio para que se lea en la misma hora que el pago.
            $deletedAt = null;
            if ($p->deleted_at) {
                try {
                    $deletedAt = Carbon::parse($p->deleted_at, $appTz)->setTimezone($tz)->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    $deletedAt = (string) $p->deleted_at;
                }
            }
            return [
                'id' => $p->id,
                'credit_id' => $p->credit_id,
                'seller_id' => $p->seller_id,
                'client_name' => $p->client_name ?? '—',
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'payment_method' => $p->payment_method,
                'business_date' => substr((string) $p->business_date, 0, 10),
                'paid_at' => $p->business_timestamp ? substr((string) $p->business_timestamp, 0, 19) : null,
                'registered_by' => $p->registered_by,
                'deleted_at' => $deletedAt,
                'deleted_by' => $p->deleted_by_name,
            ];
        })->all();
    }

    /** Detalle de una línea: los pagos que forman su recaudo y los eliminados. */
    public function itemPayments(PayrollItem $item): array
    {
        $start = $item->payroll->week_start->toDateString();
        $end = $item->payroll->week_end->toDateString();

        $payments = $this->paymentRows($this->paymentsQuery([$item->seller_id], $start, $end, false));
        $deleted = $this->paymentRows($this->paymentsQuery([$item->seller_id], $start, $end, true));

        // Resumen por día: cuánto se recaudó cada día del corte.
        $byDay = [];
        foreach ($payments as $p) {
            $d = $p['business_date'];
            $byDay[$d] = [
                'date' => $d,
                'count' => ($byDay[$d]['count'] ?? 0) + 1,
                'amount' => round(($byDay[$d]['amount'] ?? 0) + $p['amount'], 2),
            ];
        }
        ksort($byDay);

        return [
            'seller_name' => $item->seller_name,
            'currency' => $item->currency,
            'week_start' => $start,
            'week_end' => $end,
            'collection_base' => $item->collection_base,
            'payments' => $payments,
            'payments_total' => round(array_sum(array_column($payments, 'amount')), 2),
            'by_day' => array_values($byDay),
            'deleted' => $deleted,
            'deleted_total' => round(array_sum(array_column($deleted, 'amount')), 2),
        ];
    }

    /**
     * Un día concreto del período, por cobrador: lo recaudado, los créditos
     * nuevos y los pagos eliminados de ESE día. Es solo consulta: la nómina se
     * sigue calculando sobre el período completo.
     */
    public function dayBreakdown(Payroll $payroll, string $date): array
    {
        $date = substr($date, 0, 10);
        if ($date < $payroll->week_start->toDateString() || $date > $payroll->week_end->toDateString()) {
            throw new PayrollException('Ese día no pertenece a este período.');
        }

        $sellerIds = $payroll->items()->pluck('seller_id')->all();
        $collection = $this->collectionBySeller($sellerIds, $date, $date);
        $placement = $this->placementBySeller($sellerIds, $date, $date);
        $payments = $this->paymentsQuery($sellerIds, $date, $date, false)
            ->groupBy('credits.seller_id')
            ->selectRaw('credits.seller_id, COUNT(*) AS n')
            ->get()->keyBy('seller_id');
        $deleted = $this->paymentsQuery($sellerIds, $date, $date, true)
            ->groupBy('credits.seller_id')
            ->selectRaw('credits.seller_id, COUNT(*) AS n, SUM(payments.amount) AS total')
            ->get()->keyBy('seller_id');

        $rows = [];
        foreach ($sellerIds as $id) {
            $rows[$id] = [
                'seller_id' => $id,
                'collection' => round((float) ($collection[$id]->total ?? 0), 2),
                'payments' => (int) ($payments[$id]->n ?? 0),
                'placement_capital' => round((float) ($placement[$id]->capital ?? 0), 2),
                'placement_interest' => round((float) ($placement[$id]->interest ?? 0), 2),
                'deleted_payments' => (int) ($deleted[$id]->n ?? 0),
                'deleted_amount' => round((float) ($deleted[$id]->total ?? 0), 2),
            ];
        }

        return ['date' => $date, 'sellers' => $rows];
    }

    /**
     * Tira del período: para cada cobrador, un casillero por día con lo
     * recaudado y el estado de la liquidación de ese día. Es lo que permite
     * ver la nómina armándose día a día sin entrar al detalle.
     *
     * Estados de un día:
     *   approved  liquidación aprobada por el administrador (recaudo firme)
     *   closed    cerrada por el cobrador / cierre automático, sin aprobar
     *   today     hoy, caja abierta
     *   open      día pasado cuya caja sigue abierta
     *   empty     día pasado sin liquidación ni cobros
     *   off       la ruta no trabaja (domingo sin works_sundays o feriado)
     *   future    todavía no llega
     *
     * Todo sale de cuatro consultas, sin importar cuántos cobradores haya.
     */
    public function grid(Payroll $payroll): array
    {
        $start = $payroll->week_start->toDateString();
        $end = $payroll->week_end->toDateString();
        $today = $this->companyToday((int) $payroll->company_id);

        $days = [];
        for ($d = Carbon::parse($start); $d->toDateString() <= $end; $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $sellerIds = $payroll->items()->pluck('seller_id')->all();
        if (empty($sellerIds)) {
            return ['days' => $days, 'today' => $today, 'sellers' => []];
        }

        // 1) Recaudo por cobrador y día.
        $collected = [];
        foreach ($this->paymentsQuery($sellerIds, $start, $end, false)
            ->groupBy('credits.seller_id', 'payments.business_date')
            ->selectRaw('credits.seller_id, payments.business_date AS d, SUM(payments.amount) AS total, COUNT(*) AS n')
            ->get() as $r) {
            $collected[$r->seller_id][substr((string) $r->d, 0, 10)] = [round((float) $r->total, 2), (int) $r->n];
        }

        // 2) Estado de la liquidación de cada día.
        $liq = [];
        foreach (DB::table('liquidations')
            ->whereIn('seller_id', $sellerIds)->whereNull('deleted_at')
            ->where('date', '>=', $start . ' 00:00:00')
            ->where('date', '<', Carbon::parse($end)->addDay()->toDateString() . ' 00:00:00')
            ->selectRaw('seller_id, DATE(date) AS d, status')->get() as $r) {
            $liq[$r->seller_id][$r->d] = $r->status;
        }

        // 3) Días que la ruta no trabaja: domingos (si no trabaja domingos) y
        //    feriados de su país, nacionales o de la empresa. Mismo criterio
        //    que BusinessCalendar, resuelto en bloque.
        $sellers = Seller::withTrashed()->whereIn('id', $sellerIds)
            ->with(['city:id,country_id', 'config:id,seller_id,works_sundays'])->get(['id', 'city_id'])->keyBy('id');

        $holidays = []; // [country_id][Y-m-d] = true
        $rows = \App\Models\Holiday::where('active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $payroll->company_id))
            ->get(['country_id', 'recurring', 'month', 'day', 'date']);
        foreach ($rows as $h) {
            foreach ($days as $day) {
                $c = Carbon::parse($day);
                $match = $h->recurring
                    ? ((int) $h->month === $c->month && (int) $h->day === $c->day)
                    : ($h->date && substr((string) $h->date, 0, 10) === $day);
                if ($match) {
                    $holidays[$h->country_id][$day] = true;
                }
            }
        }

        $out = [];
        foreach ($sellerIds as $id) {
            $seller = $sellers[$id] ?? null;
            $countryId = optional(optional($seller)->city)->country_id;
            $noSundays = $seller && $seller->config && (int) $seller->config->works_sundays === 0;

            $cells = [];
            foreach ($days as $day) {
                [$amount, $count] = $collected[$id][$day] ?? [0.0, 0];
                $status = $liq[$id][$day] ?? null;
                $isOff = ($noSundays && Carbon::parse($day)->isSunday()) || isset($holidays[$countryId][$day]);

                if ($status === 'approved') {
                    $state = 'approved';
                } elseif (in_array($status, ['pending', 'auto'], true)) {
                    $state = 'closed';
                } elseif ($day > $today) {
                    $state = $isOff ? 'off' : 'future';
                } elseif ($isOff && $amount <= 0) {
                    $state = 'off';
                } elseif ($day === $today) {
                    $state = 'today';
                } elseif ($status !== null || $amount > 0) {
                    $state = 'open';
                } else {
                    $state = 'empty';
                }

                // [recaudo, n.º de pagos, estado]
                $cells[] = [$amount, $count, $state];
            }
            $out[$id] = $cells;
        }

        return ['days' => $days, 'today' => $today, 'sellers' => $out];
    }

    /** Todos los pagos eliminados de la semana, de los vendedores de la nómina. */
    public function deletedPayments(Payroll $payroll): array
    {
        $items = $payroll->items()->get(['seller_id', 'seller_name', 'currency'])->keyBy('seller_id');
        $rows = $this->paymentRows($this->paymentsQuery(
            $items->keys()->all(),
            $payroll->week_start->toDateString(),
            $payroll->week_end->toDateString(),
            true
        ), 5000);

        $byCurrency = [];
        foreach ($rows as &$r) {
            $it = $items[$r['seller_id']] ?? null;
            $r['seller_name'] = optional($it)->seller_name ?? '—';
            $r['currency'] = optional($it)->currency ?? 'PEN';
            $c = $r['currency'];
            $byCurrency[$c] = [
                'currency' => $c,
                'count' => ($byCurrency[$c]['count'] ?? 0) + 1,
                'amount' => round(($byCurrency[$c]['amount'] ?? 0) + $r['amount'], 2),
            ];
        }
        unset($r);

        return ['rows' => $rows, 'totals' => array_values($byCurrency)];
    }

    // ------------------------------------------------------------------
    // Exportes
    // ------------------------------------------------------------------

    public const STATUS_LABELS = [
        Payroll::DRAFT => 'Borrador',
        Payroll::APPROVED => 'Aprobada',
        Payroll::PAID => 'Pagada',
        Payroll::VOID => 'Anulada',
    ];

    public const ITEM_STATUS_LABELS = [
        PayrollItem::PENDING => 'Pendiente',
        PayrollItem::PAID => 'Pagado',
        PayrollItem::NO_PAYMENT => 'Sin pago',
    ];

    /** Texto corto de la regla aplicada a una línea (pantalla, PDF y Excel). */
    public static function describeRule(?array $rule): string
    {
        if (!$rule) {
            return 'Sin regla';
        }
        $parts = [];
        if (($rule['collection_mode'] ?? 'none') === 'percentage') {
            $parts[] = self::pct($rule['collection_percentage']) . ' del recaudo';
        } elseif (($rule['collection_mode'] ?? 'none') === 'tiers') {
            $parts[] = 'Tramos de recaudo (' . count($rule['collection_tiers'] ?? []) . ')';
        }
        if (($rule['placement_mode'] ?? 'none') === 'capital') {
            $parts[] = self::pct($rule['placement_percentage']) . ' de créditos nuevos';
        } elseif (($rule['placement_mode'] ?? 'none') === 'interest') {
            $parts[] = self::pct($rule['placement_percentage']) . ' del interés de créditos nuevos';
        }
        if (($rule['fixed_salary'] ?? 0) > 0) {
            $parts[] = 'Sueldo fijo';
        }
        if (($rule['allowance'] ?? 0) > 0) {
            $parts[] = 'Viáticos';
        }
        return $parts ? implode(' + ', $parts) : 'Sin conceptos';
    }

    private static function pct($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',') . '%';
    }
}
