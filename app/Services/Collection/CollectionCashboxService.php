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

        $rows = $cashboxes->map(function (CollectionCashbox $c) use ($balances) {
            $b = $balances[$c->id] ?? ['balance' => 0, 'count' => 0];
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
                'balance'         => round((float) $c->opening_balance + $b['balance'], 2),
                'movements_count' => $b['count'],
                // Fecha de creación de la caja: antes de ese día no puede tener
                // movimientos. El front la usa para rotular desde cuándo hay
                // datos en la vista, en lugar del inicio del módulo (que es de
                // la empresa y es igual para todas las cajas).
                'created_at'      => optional($c->created_at)->toDateString(),
            ];
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

        // Si la empresa todavía no tiene caja principal, esta lo es. Antes se
        // fijaba `false` a secas: el backfill sólo recorre empresas que YA
        // tenían registros diarios, así que una empresa nueva podía terminar
        // con varias cajas y ninguna marcada como principal. Sin default se
        // caen las tres protecciones que cuelgan de esa marca — no poder
        // eliminarla, no poder desactivarla, e incluir lo de alcance empresa en
        // los reportes— y la empresa podía quedarse sin ninguna caja.
        // También sirve de autocuración para las empresas que ya están así.
        $hasDefault = CollectionCashbox::where('company_id', $companyId)
            ->where('is_default', true)
            ->exists();

        $cashbox = CollectionCashbox::create([
            'company_id'      => $companyId,
            'name'            => trim($validated['name']),
            'icon'            => $validated['icon'] ?? null,
            'color'           => $validated['color'] ?? null,
            'currency'        => $validated['currency'] ?? 'COP',
            'country_code'    => $validated['country_code'] ?? null,
            'opening_balance' => $validated['opening_balance'] ?? 0,
            'is_default'      => !$hasDefault,
            'active'          => true,
            'sort_order'      => $validated['sort_order'] ?? 0,
            'created_by'      => Auth::id(),
        ]);

        $this->audit($companyId, $cashbox, 'created', [
            'new' => $this->snapshot($cashbox),
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

        // Una caja eliminada es solo lectura: su saldo de cierre y su nombre son
        // la referencia de todos los movimientos que quedaron colgando de ella.
        // El front ya no ofrece el botón de editar; esto cierra la puerta de
        // atrás para que no se pueda renombrar por API.
        if (!$cashbox->active) {
            return response()->json([
                'message' => 'La caja está eliminada: no se puede editar ni reabrir.',
            ], 422);
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

        // Estado previo para la traza: se toma ANTES de mutar el modelo.
        $before = $this->snapshot($cashbox);

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
        if (array_key_exists('currency', $validated)) $cashbox->currency = $validated['currency'] ?? $cashbox->currency;
        if (array_key_exists('country_code', $validated)) $cashbox->country_code = $validated['country_code'];
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

        // Solo se audita si algo cambió de verdad: guardar un evento por cada
        // "Guardar cambios" sin cambios llenaría el historial de ruido.
        $after = $this->snapshot($cashbox);
        if ($after !== $before) {
            $this->audit($companyId, $cashbox, 'updated', [
                'old' => $before,
                'new' => $after,
            ]);
        }

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
        // Cerrar dos veces la misma caja duplicaría el evento en el historial y
        // pisaría el saldo de cierre original con el mismo número.
        if (!$cashbox->active) {
            return response()->json(['message' => 'La caja ya está eliminada.'], 422);
        }

        $hasMovements = CollectionDailyRecord::where('company_id', $companyId)
            ->where(function ($q) use ($id) {
                $q->where('cashbox_id', $id)->orWhere('cashbox_to_id', $id);
            })->exists();

        $before = $this->snapshot($cashbox);

        // Saldo con el que se cierra la caja, congelado en la traza. El saldo
        // es derivado de los movimientos, así que hoy coincide con el
        // calculado; guardarlo evita que un backfill o un ajuste posterior
        // cambien retroactivamente "con cuánto se cerró".
        $balances = $this->balancesFor($companyId);
        $closingBalance = round(
            (float) $cashbox->opening_balance
            + (float) ($balances[$cashbox->id]['balance'] ?? 0),
            2
        );
        $closingMeta = [
            'closing_balance' => $closingBalance,
            'currency'        => $cashbox->currency,
        ];

        if ($hasMovements) {
            $cashbox->active = false;
            $cashbox->save();
            $this->audit($companyId, $cashbox, 'deactivated', array_merge($closingMeta, [
                'old' => $before,
                'new' => $this->snapshot($cashbox),
            ]));
            return response()->json(['message' => 'La caja tiene movimientos; se conserva el historial y queda marcada como eliminada.']);
        }

        // La traza se escribe ANTES del delete: después el modelo ya no está
        // disponible para leerle el nombre. Igual es soft-delete, pero no se
        // depende de eso para que el historial siga siendo legible.
        $this->audit($companyId, $cashbox, 'deleted', array_merge($closingMeta, ['old' => $before]));

        $cashbox->delete();
        return response()->json(['message' => 'Caja eliminada']);
    }

    // ── Auditoría ──────────────────────────────────────────────────────────

    /**
     * Etiquetas legibles de los campos auditados. Solo se listan los que le
     * dicen algo al usuario: `sort_order` o `created_by` cambiarían la fila sin
     * significar nada para quien lee el historial.
     */
    private const AUDIT_FIELD_LABELS = [
        'name'            => 'Nombre',
        'opening_balance' => 'Saldo inicial',
        'currency'        => 'Moneda',
        'country_code'    => 'País',
        'icon'            => 'Ícono',
        'color'           => 'Color',
        'active'          => 'Activa',
        'is_default'      => 'Caja principal',
    ];

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
     * Historial de cambios de las cajas de la empresa. Es company-wide y no por
     * caja: una caja eliminada desaparece del listado, y su traza es justamente
     * la que hay que poder consultar.
     */
    public function history(Request $request)
    {
        $companyId = (int) $request->company_id;
        $limit = (int) ($request->query('limit') ?: 100);
        $limit = max(1, min($limit, 300));

        $audits = CollectionCashboxAudit::where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Los nombres de usuario viven en MySQL (core), no en collection_pgsql:
        // se resuelven aparte, igual que en el historial de clientes.
        $userIds = $audits->pluck('user_id')->filter()->unique()->all();
        $names = empty($userIds)
            ? collect()
            : \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');

        $data = $audits->map(function (CollectionCashboxAudit $audit) use ($names) {
            $changes = is_array($audit->changes) ? $audit->changes : [];

            return [
                'id'           => $audit->id,
                'cashbox_id'   => $audit->cashbox_id,
                'cashbox_name' => $changes['cashbox_name'] ?? null,
                // Solo viene en los cierres: el saldo con el que quedó la caja.
                'closing_balance' => array_key_exists('closing_balance', $changes)
                    ? (float) $changes['closing_balance']
                    : null,
                'currency'     => $changes['currency'] ?? null,
                'action'       => $audit->action,
                'user_id'      => $audit->user_id,
                'user_name'    => $names[$audit->user_id] ?? null,
                'ip_address'   => $audit->ip_address,
                'created_at'   => optional($audit->created_at)->toISOString(),
                'fields'       => $this->describeAuditChanges($changes),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Convierte {old:{...}, new:{...}} en la lista de campos que realmente
     * cambiaron. Un alta o una baja no traen comparación: devuelven [].
     */
    private function describeAuditChanges(array $changes): array
    {
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $new = is_array($changes['new'] ?? null) ? $changes['new'] : [];

        if (!$old || !$new) {
            return [];
        }

        $fields = [];
        foreach (self::AUDIT_FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $old) && !array_key_exists($field, $new)) {
                continue;
            }

            $oldValue = $this->normalizeAuditValue($old[$field] ?? null);
            $newValue = $this->normalizeAuditValue($new[$field] ?? null);

            if ($oldValue === $newValue) {
                continue;
            }

            $fields[] = [
                'field' => $field,
                'label' => $label,
                'old'   => $oldValue,
                'new'   => $newValue,
            ];
        }

        return $fields;
    }

    /** Normaliza a string|null para que "" y null no cuenten como cambio. */
    private function normalizeAuditValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
