<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCashbox;
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
}
