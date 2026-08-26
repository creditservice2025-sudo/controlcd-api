<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCashbox;
use App\Models\Collection\CollectionCashboxAudit;
use App\Models\Collection\CollectionDailyRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cajas (cuentas) del módulo Collection. Cada caja es un contenedor de la
 * bitácora de registros diarios con su propio saldo DERIVADO de los
 * movimientos (no persistido como verdad).
 */
class CollectionCashboxService
{
    /**
     * Lista las cajas de la empresa con su saldo derivado y conteo de movimientos.
     */
    public function index(Request $request)
    {
        $companyId = (int) $request->company_id;
        $includeInactive = $request->boolean('include_inactive');

        $query = CollectionCashbox::where('company_id', $companyId);
        if (!$includeInactive) {
            $query->where('active', true);
        }
        $cashboxes = $query->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $balances = $this->balancesFor($companyId);
        // Quién apagó (o volvió a encender) cada caja, cuándo y por qué. Se pide
        // en batch aunque solo se muestre en las inhabilitadas: son pocas cajas
        // por empresa y evita una consulta por tarjeta.
        $toggles = $this->lastToggleFor($companyId, $cashboxes->pluck('id')->all());
        // Primer día con movimiento por caja: define desde cuándo opera cada
        // una, que no siempre coincide con cuándo se creó su ficha.
        $primeros = $this->firstMovementFor($companyId);

        $rows = $cashboxes->map(function (CollectionCashbox $c) use ($balances, $toggles, $primeros) {
            return $this->presentRow(
                $c,
                $balances[$c->id] ?? ['balance' => 0, 'count' => 0],
                $toggles[(int) $c->id] ?? null,
                $primeros[(int) $c->id] ?? null
            );
        })->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Saldo neto (sin opening_balance) y conteo por caja, en una sola pasada.
     * balance = Σ(ingreso) − Σ(gasto) − Σ(transferencia saliente) + Σ(transferencia entrante).
     *
     * @return array<int, array{balance: float, count: int}>
     */
    protected function balancesFor(int $companyId): array
    {
        // Movimientos propios de cada caja (ingreso/gasto/transferencia saliente).
        $own = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNotNull('cashbox_id')
            ->groupBy('cashbox_id')
            ->selectRaw('cashbox_id')
            ->selectRaw("SUM(CASE WHEN type = 'ingreso' THEN amount ELSE 0 END) AS ingreso")
            ->selectRaw("SUM(CASE WHEN type = 'gasto' THEN amount ELSE 0 END) AS gasto")
            ->selectRaw("SUM(CASE WHEN type = 'transferencia' THEN amount ELSE 0 END) AS transfer_out")
            ->selectRaw('COUNT(*) AS cnt')
            ->get();

        // Transferencias entrantes (caja destino). Fase 2 las crea; hoy suma 0.
        $incoming = CollectionDailyRecord::where('company_id', $companyId)
            ->where('type', 'transferencia')
            ->whereNotNull('cashbox_to_id')
            ->groupBy('cashbox_to_id')
            ->selectRaw('cashbox_to_id')
            ->selectRaw('SUM(amount) AS transfer_in')
            ->pluck('transfer_in', 'cashbox_to_id');

        $out = [];
        foreach ($own as $r) {
            $id = (int) $r->cashbox_id;
            $out[$id] = [
                'balance' => (float) $r->ingreso - (float) $r->gasto - (float) $r->transfer_out,
                'count'   => (int) $r->cnt,
            ];
        }
        foreach ($incoming as $toId => $amount) {
            $id = (int) $toId;
            if (!isset($out[$id])) {
                $out[$id] = ['balance' => 0, 'count' => 0];
            }
            $out[$id]['balance'] += (float) $amount;
        }

        return $out;
    }

    public function store(Request $request)
    {
        $companyId = (int) $request->company_id;

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'icon'            => 'nullable|string|max:60',
            'color'           => 'nullable|string|max:30',
            'currency'        => 'nullable|string|size:3',
            'country_code'    => 'nullable|string|size:2',
            'opening_balance' => 'nullable|numeric',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        // Evitar nombres duplicados activos dentro de la empresa.
        $dupe = CollectionCashbox::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->exists();
        if ($dupe) {
            return response()->json(['message' => 'Ya existe una caja con ese nombre.'], 422);
        }

        $cashbox = CollectionCashbox::create([
            'company_id'      => $companyId,
            'name'            => trim($validated['name']),
            'icon'            => $validated['icon'] ?? null,
            'color'           => $validated['color'] ?? null,
            'currency'        => $validated['currency'] ?? 'COP',
            'country_code'    => $validated['country_code'] ?? null,
            'opening_balance' => $validated['opening_balance'] ?? 0,
            'is_default'      => false,
            'active'          => true,
            'sort_order'      => $validated['sort_order'] ?? 0,
            'created_by'      => Auth::id(),
        ]);

        return response()->json(['message' => 'Caja creada', 'data' => $cashbox], 201);
    }

    public function update(Request $request, int $id)
    {
        $companyId = (int) $request->company_id;

        $cashbox = CollectionCashbox::where('company_id', $companyId)->find($id);
        if (!$cashbox) {
            return response()->json(['message' => 'Caja no encontrada'], 404);
        }

        $validated = $request->validate([
            'name'            => 'sometimes|required|string|max:100',
            'icon'            => 'nullable|string|max:60',
            'color'           => 'nullable|string|max:30',
            'currency'        => 'nullable|string|size:3',
            'country_code'    => 'nullable|string|size:2',
            'opening_balance' => 'nullable|numeric',
            'sort_order'      => 'nullable|integer|min:0',
            'active'          => 'nullable|boolean',
        ]);

        if (isset($validated['name'])) {
            $dupe = CollectionCashbox::where('company_id', $companyId)
                ->where('id', '!=', $cashbox->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
                ->exists();
            if ($dupe) {
                return response()->json(['message' => 'Ya existe una caja con ese nombre.'], 422);
            }
            $cashbox->name = trim($validated['name']);
        }
        if (array_key_exists('icon', $validated)) $cashbox->icon = $validated['icon'];
        if (array_key_exists('color', $validated)) $cashbox->color = $validated['color'];

        // La moneda y el país de una caja CON movimientos no se tocan: los
        // importes ya grabados quedarían reetiquetados (500 pasarían a leerse
        // como 500 de otra moneda) y el país ancla la fecha contable, así que
        // cambiarlo podría correr movimientos de día. Se rechaza en el backend
        // y no solo en la pantalla, porque el APK viejo sigue mandando ambos.
        $cambiaMoneda = array_key_exists('currency', $validated)
            && !empty($validated['currency'])
            && strtoupper($validated['currency']) !== strtoupper((string) $cashbox->currency);
        $cambiaPais = array_key_exists('country_code', $validated)
            && strtoupper((string) $validated['country_code']) !== strtoupper((string) $cashbox->country_code);

        if ($cambiaMoneda || $cambiaPais) {
            $tieneMovimientos = CollectionDailyRecord::where('company_id', $companyId)
                ->where(function ($q) use ($id) {
                    $q->where('cashbox_id', $id)->orWhere('cashbox_to_id', $id);
                })->exists();

            if ($tieneMovimientos) {
                return response()->json([
                    'message' => 'La caja ya tiene movimientos: no se puede cambiar su moneda ni su país. '
                        . 'Creá una caja nueva con la moneda correcta.',
                ], 422);
            }

            if ($cambiaMoneda) $cashbox->currency = strtoupper($validated['currency']);
            if ($cambiaPais) {
                $cashbox->country_code = $validated['country_code']
                    ? strtoupper($validated['country_code'])
                    : null;
            }
        }
        if (array_key_exists('opening_balance', $validated)) $cashbox->opening_balance = $validated['opening_balance'];
        if (array_key_exists('sort_order', $validated)) $cashbox->sort_order = $validated['sort_order'];

        // No permitir desactivar la caja default.
        if (array_key_exists('active', $validated) && $validated['active'] === false) {
            if ($cashbox->is_default) {
                return response()->json(['message' => 'No se puede desactivar la caja principal.'], 422);
            }
            $cashbox->active = false;
        } elseif (array_key_exists('active', $validated)) {
            $cashbox->active = (bool) $validated['active'];
        }

        $cashbox->save();

        return response()->json(['message' => 'Caja actualizada', 'data' => $cashbox]);
    }

    /**
     * Desactivar (no eliminar) una caja. La default no se puede tocar; una caja
     * con movimientos se conserva por trazabilidad, solo se marca inactiva.
     */
    public function destroy(Request $request, int $id)
    {
        $companyId = (int) $request->company_id;

        $cashbox = CollectionCashbox::where('company_id', $companyId)->find($id);
        if (!$cashbox) {
            return response()->json(['message' => 'Caja no encontrada'], 404);
        }
        if ($cashbox->is_default) {
            return response()->json(['message' => 'No se puede eliminar la caja principal.'], 422);
        }

        $hasMovements = CollectionDailyRecord::where('company_id', $companyId)
            ->where(function ($q) use ($id) {
                $q->where('cashbox_id', $id)->orWhere('cashbox_to_id', $id);
            })->exists();

        if ($hasMovements) {
            $cashbox->active = false;
            $cashbox->save();
            return response()->json(['message' => 'La caja tiene movimientos; se desactivó (se conserva el historial).']);
        }

        $cashbox->delete();
        return response()->json(['message' => 'Caja eliminada']);
    }

    /**
     * Extracto diario de UNA caja (estado de cuenta estilo bancario).
     *
     * Existe porque el resumen de cierre (/cash-closures) es de la EMPRESA y se
     * acota por país, no por caja: al abrir el reporte con una caja elegida, el
     * corte hablaba de un universo (país) y la bitácora de otro (caja), y las
     * cifras se contradecían dentro del mismo archivo. Aquí todo —saldo
     * anterior, movimientos y saldo final— sale de la MISMA caja, así que
     * cuadra por construcción:
     *
     *   saldo_anterior + abonos − cargos = saldo_final
     *
     * El saldo anterior se deriva del histórico (opening_balance de la caja +
     * todos sus movimientos con business_date < fecha) y NO del último cierre:
     * los cierres son por empresa/país y no saben de cajas.
     */
    public function statement(Request $request, int $id)
    {
        $companyId = (int) $request->company_id;

        $cashbox = CollectionCashbox::where('company_id', $companyId)->find($id);
        if (!$cashbox) {
            return response()->json(['message' => 'Caja no encontrada'], 404);
        }

        $tz = \App\Models\Company::find($companyId)?->timezone ?: 'America/Bogota';
        $date = $request->query('date') ?: \Carbon\Carbon::now($tz)->toDateString();

        $before = $this->movementTotals($companyId, (int) $cashbox->id, null, $date);
        $day = $this->movementTotals($companyId, (int) $cashbox->id, $date, null);

        $openingBalance = round((float) $cashbox->opening_balance + $before['neto'], 2);
        $closingBalance = round($openingBalance + $day['neto'], 2);

        return response()->json(['data' => [
            'cashbox' => [
                'id'              => (int) $cashbox->id,
                'name'            => $cashbox->name,
                'currency'        => $cashbox->currency,
                'country_code'    => $cashbox->country_code,
                'opening_balance' => (float) $cashbox->opening_balance,
            ],
            'date'            => $date,
            'timezone'        => $tz,
            // Saldo con el que la caja abrió el día (arrastre de todo lo anterior).
            'opening_balance' => $openingBalance,
            'totals'          => $day,
            'closing_balance' => $closingBalance,
        ]]);
    }

    /**
     * Suma los movimientos de una caja en un rango de fecha contable.
     * $onDate: un solo día. $beforeDate: todo lo anterior a esa fecha.
     *
     * Signo desde la óptica de la caja: ingreso y transferencia recibida
     * ABONAN; gasto y transferencia enviada CARGAN. Es el mismo criterio que
     * balancesFor(), para que el saldo final del último día coincida con el
     * saldo que muestran los chips de la pantalla.
     */
    protected function movementTotals(int $companyId, int $cashboxId, ?string $onDate, ?string $beforeDate): array
    {
        $scope = function ($q) use ($onDate, $beforeDate) {
            if ($onDate) $q->where('business_date', $onDate);
            if ($beforeDate) $q->where('business_date', '<', $beforeDate);
        };

        $own = CollectionDailyRecord::where('company_id', $companyId)
            ->where('cashbox_id', $cashboxId)
            ->where($scope)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'ingreso' THEN amount ELSE 0 END), 0) AS ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'gasto' THEN amount ELSE 0 END), 0) AS gastos")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'transferencia' THEN amount ELSE 0 END), 0) AS transferencias_salida")
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        $incoming = CollectionDailyRecord::where('company_id', $companyId)
            ->where('cashbox_to_id', $cashboxId)
            ->where('type', 'transferencia')
            ->where($scope)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        $ingresos = round((float) $own->ingresos, 2);
        $gastos = round((float) $own->gastos, 2);
        $transferSalida = round((float) $own->transferencias_salida, 2);
        $transferEntrada = round((float) $incoming->total, 2);

        return [
            'ingresos'               => $ingresos,
            'gastos'                 => $gastos,
            'transferencias_salida'  => $transferSalida,
            'transferencias_entrada' => $transferEntrada,
            'abonos'                 => round($ingresos + $transferEntrada, 2),
            'cargos'                 => round($gastos + $transferSalida, 2),
            'neto'                   => round($ingresos + $transferEntrada - $gastos - $transferSalida, 2),
            'movimientos'            => (int) $own->cnt + (int) $incoming->cnt,
        ];
    }
    // ── Habilitar / inhabilitar una caja ───────────────────────────────────

    /**
     * Enciende o apaga una caja, dejando traza de quién, cuándo y por qué.
     *
     * Una caja es el contenedor del dinero: apagarla saca del alta una cuenta
     * que quizá todavía tiene plata adentro, y eso después se lee como un
     * descuadre sin explicación. Por eso:
     *
     *  - el motivo es OBLIGATORIO al inhabilitar (queda en la traza);
     *  - si la caja tiene saldo distinto de cero se responde 409 con el saldo,
     *    para que el cliente pida confirmación explícita (`confirm_balance`);
     *  - no se permite apagar la última caja activa: la empresa se quedaría sin
     *    dónde registrar movimientos;
     *  - el saldo al momento de apagarla se CONGELA en la traza, porque el
     *    saldo es derivado y un ajuste posterior cambiaría retroactivamente
     *    "con cuánto se cerró".
     */
    public function setActive(Request $request, int $id)
    {
        $companyId = (int) $request->company_id;

        $cashbox = CollectionCashbox::where('company_id', $companyId)->find($id);
        if (!$cashbox) {
            return response()->json(['message' => 'Caja no encontrada'], 404);
        }

        $validated = $request->validate([
            'active'          => 'required|boolean',
            'reason'          => 'nullable|string|max:500',
            'confirm_balance' => 'nullable|boolean',
        ]);

        $target = (bool) $validated['active'];
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ((bool) $cashbox->active === $target) {
            return response()->json([
                'message' => $target ? 'La caja ya está habilitada.' : 'La caja ya está inhabilitada.',
            ], 422);
        }

        $balances = $this->balancesFor($companyId);
        $balance = round(
            (float) $cashbox->opening_balance + (float) ($balances[$cashbox->id]['balance'] ?? 0),
            2
        );

        if (!$target) {
            // El motivo es lo único que explica la baja seis meses después.
            if ($reason === '') {
                return response()->json([
                    'message' => 'Indicá por qué se inhabilita la caja.',
                    'errors'  => ['reason' => ['El motivo es obligatorio.']],
                ], 422);
            }

            // Se PERMITE apagar la última caja activa: la empresa queda sin
            // ninguna, y esa es una situación válida (se rearma el esquema de
            // cajas desde cero, sin arrastrar una "principal" heredada). El
            // alta de movimientos lo detecta y pide crear o habilitar una, en
            // vez de que este switch decida por el usuario.

            // Saldo pendiente: no se bloquea, se pide confirmación. Puede ser
            // legítimo (la caja se cierra con plata que se entrega en mano),
            // pero tiene que ser una decisión consciente y queda en la traza.
            if (abs($balance) > 0.01 && !($validated['confirm_balance'] ?? false)) {
                return response()->json([
                    'message'          => 'La caja tiene saldo pendiente.',
                    'requires_confirm' => true,
                    'balance'          => $balance,
                    'currency'         => $cashbox->currency,
                ], 409);
            }
        }

        $before = $this->snapshot($cashbox);
        $cashbox->active = $target;
        $cashbox->save();

        $this->audit($companyId, $cashbox, $target ? 'reactivated' : 'deactivated', [
            'reason'          => $reason !== '' ? $reason : null,
            'closing_balance' => $balance,
            'currency'        => $cashbox->currency,
            'old'             => $before,
            'new'             => $this->snapshot($cashbox),
        ]);

        $toggle = $this->lastToggleFor($companyId, [(int) $cashbox->id]);

        return response()->json([
            'message' => $target ? 'Caja habilitada' : 'Caja inhabilitada',
            'data'    => $this->presentRow(
                $cashbox,
                $balances[$cashbox->id] ?? ['balance' => 0, 'count' => 0],
                $toggle[(int) $cashbox->id] ?? null
            ),
        ]);
    }

    // ── Auditoría ──────────────────────────────────────────────────────────

    /** Estado auditable de la caja en un momento dado. */
    private function snapshot(CollectionCashbox $cashbox): array
    {
        return [
            'name'            => $cashbox->name,
            'opening_balance' => (float) $cashbox->opening_balance,
            'currency'        => $cashbox->currency,
            'country_code'    => $cashbox->country_code,
            'icon'            => $cashbox->icon,
            'color'           => $cashbox->color,
            'active'          => (bool) $cashbox->active,
            'is_default'      => (bool) $cashbox->is_default,
        ];
    }

    private function audit(int $companyId, CollectionCashbox $cashbox, string $action, array $changes = []): void
    {
        // El nombre se repite fuera de old/new a propósito: es lo que permite
        // titular el evento cuando la caja ya no existe.
        $changes['cashbox_name'] = $cashbox->name;

        CollectionCashboxAudit::query()->create([
            'company_id' => $companyId,
            'cashbox_id' => $cashbox->id,
            'action'     => $action,
            'user_id'    => Auth::id(),
            'ip_address' => request()->ip(),
            'changes'    => $changes,
        ]);
    }

    /**
     * Último evento de baja/alta por caja, para poder mostrar en la tarjeta
     * quién la inhabilitó, cuándo y por qué. Se resuelve en batch: los nombres
     * de usuario viven en MySQL (core) y las cajas en collection_pgsql.
     *
     * @param  array<int> $cashboxIds
     * @return array<int, array{action:string,at:?string,by:?string,reason:?string}>
     */
    protected function lastToggleFor(int $companyId, array $cashboxIds): array
    {
        if (empty($cashboxIds)) return [];

        // DISTINCT ON trae UNA fila por caja (la última) desde el motor. Antes
        // se traían TODOS los eventos de encendido/apagado y se descartaban en
        // PHP: con muchas cajas que se prenden y apagan seguido, la consulta
        // crecía sin techo para mostrar un renglón por tarjeta.
        // Collection es PostgreSQL-only, así que DISTINCT ON es seguro acá.
        $audits = CollectionCashboxAudit::query()
            ->select('cashbox_id', 'action', 'user_id', 'changes', 'created_at')
            ->fromRaw('(
                SELECT DISTINCT ON (cashbox_id)
                       cashbox_id, action, user_id, changes, created_at
                FROM collection_cashbox_audits
                WHERE company_id = ?
                  AND action IN (?, ?)
                  AND cashbox_id IN (' . implode(',', array_map('intval', $cashboxIds)) . ')
                ORDER BY cashbox_id, id DESC
            ) AS collection_cashbox_audits', [$companyId, 'deactivated', 'reactivated'])
            ->get();

        $userIds = $audits->pluck('user_id')->filter()->unique()->all();
        $names = empty($userIds)
            ? collect()
            : \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');

        $out = [];
        foreach ($audits as $a) {
            $cid = (int) $a->cashbox_id;
            if (isset($out[$cid])) continue; // ya tenemos el más reciente
            $changes = is_array($a->changes) ? $a->changes : [];
            $out[$cid] = [
                'action' => $a->action,
                // Formato que pidió el negocio: DD/MM/AAAA HH:MM:SS.
                'at'     => $a->created_at ? $a->created_at->format('d/m/Y H:i:s') : null,
                'by'     => $names[$a->user_id] ?? null,
                'reason' => $changes['reason'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Primer día con movimiento de cada caja de la empresa, en una sola pasada.
     *
     * @return array<int, string> cashbox_id => 'YYYY-MM-DD'
     */
    protected function firstMovementFor(int $companyId): array
    {
        return CollectionDailyRecord::where('company_id', $companyId)
            ->whereNotNull('cashbox_id')
            ->groupBy('cashbox_id')
            ->selectRaw('cashbox_id')
            ->selectRaw('MIN(business_date) AS primero')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (int) $r->cashbox_id => \Carbon\Carbon::parse($r->primero)->toDateString(),
            ])
            ->all();
    }

    /**
     * Desde cuándo opera la caja (dd/mm/aaaa).
     *
     * NO es `created_at` a secas: el backfill de multi-caja creó las cajas
     * DESPUÉS y les asignó los movimientos que ya existían, así que la ficha
     * puede ser posterior a su propio historial (GUSTAVO GARCIA se creó el
     * 05/08 y tiene movimientos desde el 04/08). Mostrar la fecha de la ficha
     * contradecía al calendario, que sí mira los movimientos.
     *
     * Se toma la MENOR entre la creación y el primer movimiento. Si la caja
     * todavía no tiene movimientos, queda la de creación, que es lo único que
     * hay. La zona es la del PAÍS de la caja, igual que su día contable.
     */
    private function openedOn(CollectionCashbox $c, ?string $primerMovimiento = null): ?string
    {
        $tz = \App\Helpers\TimezoneHelper::timezoneForCountryCode($c->country_code)
            ?: (\App\Models\Company::find($c->company_id)?->timezone ?: 'America/Bogota');

        $candidatos = [];

        if ($c->created_at) {
            try {
                $candidatos[] = \Carbon\Carbon::parse($c->created_at)->setTimezone($tz)->toDateString();
            } catch (\Throwable $e) {
                $candidatos[] = \Carbon\Carbon::parse($c->created_at)->toDateString();
            }
        }
        // El primer movimiento ya viene como fecha contable (business_date):
        // no se convierte de zona, que es justamente el punto de congelarla.
        if ($primerMovimiento) $candidatos[] = $primerMovimiento;

        if (empty($candidatos)) return null;
        sort($candidatos);

        return \Carbon\Carbon::parse($candidatos[0])->format('d/m/Y');
    }

    /** Fila de caja tal como la consume el frontend. */
    protected function presentRow(
        CollectionCashbox $c,
        array $b,
        ?array $toggle = null,
        ?string $primerMovimiento = null
    ): array
    {
        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'icon'            => $c->icon,
            'color'           => $c->color,
            'currency'        => $c->currency,
            'country_code'    => $c->country_code,
            'opening_balance' => (float) $c->opening_balance,
            'is_default'      => (bool) $c->is_default,
            'active'          => (bool) $c->active,
            'sort_order'      => (int) $c->sort_order,
            'balance'         => round((float) $c->opening_balance + ($b['balance'] ?? 0), 2),
            'movements_count' => $b['count'] ?? 0,
            // Desde cuándo existe la caja. Se manda ya formateado y en la zona
            // del PAÍS de la caja —la misma con la que se calcula su día
            // contable—, no en la del aparato: una caja creada a las 22:00 en
            // Lima no puede figurar como abierta "al día siguiente" porque el
            // teléfono de quien mira está en otro huso.
            'opened_on'       => $this->openedOn($c, $primerMovimiento),
            // Traza del último encendido/apagado (null si nunca se tocó).
            'toggle_action'   => $toggle['action'] ?? null,
            'toggled_at'      => $toggle['at'] ?? null,
            'toggled_by'      => $toggle['by'] ?? null,
            'toggle_reason'   => $toggle['reason'] ?? null,
        ];
    }
}
