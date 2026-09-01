<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CollectionReportsService
{
    use ApiResponse;

    /**
     * REPORTE 1: Caja Diaria
     * Saldo inicial, cobros, gastos, saldo final del dia.
     */
    public function cajaDiaria(int $companyId, ?string $date = null, ?string $currency = null)
    {
        $date = $date ?: Carbon::now()->toDateString();
        $currency = $currency ?: 'COP';

        // Saldo inicial: balance de wallet al cierre del dia anterior
        $wallet = DB::connection('collection_pgsql')
            ->table('collection_wallets')
            ->where('company_id', $companyId)
            ->where('currency', $currency)
            ->first();

        // Cobros del dia
        $payments = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get();

        $totalCobros = (float) $payments->sum('amount_paid');
        $countCobros = $payments->count();

        // Gastos del dia
        $expenses = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('recorded_at', $date)
            ->where('status', 'approved')
            ->orderBy('recorded_at')
            ->get();

        $totalGastos = (float) $expenses->sum('amount');
        $countGastos = $expenses->count();

        // Movimientos del ledger del dia (incluye inyecciones)
        $ledgerMovs = DB::connection('collection_pgsql')
            ->table('collection_ledger')
            ->where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        return [
            'date' => $date,
            'currency' => $currency,
            'balance_actual' => (float) ($wallet->balance ?? 0),
            'totales' => [
                'cobros_count' => $countCobros,
                'cobros_total' => $totalCobros,
                'gastos_count' => $countGastos,
                'gastos_total' => $totalGastos,
                'neto' => $totalCobros - $totalGastos,
            ],
            'cobros' => $payments->map(fn($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount_paid,
                'method' => $p->payment_method,
                'time' => Carbon::parse($p->recorded_at)->format('H:i'),
                'reference' => $p->receipt_number,
            ]),
            'gastos' => $expenses->map(fn($e) => [
                'id' => $e->id,
                'amount' => (float) $e->amount,
                'category' => $e->category,
                'description' => $e->description,
                'time' => Carbon::parse($e->recorded_at)->format('H:i'),
            ]),
            'movimientos_caja' => $ledgerMovs->map(fn($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'action_type' => $m->action_type,
                'amount' => (float) $m->amount,
                'balance_after' => (float) $m->balance_after,
                'description' => $m->description,
                'time' => Carbon::parse($m->created_at)->format('H:i'),
            ]),
        ];
    }

    /**
     * REPORTE 2: Morosidad
     */
    public function morosidad(int $companyId, ?int $minDays = 0)
    {
        $today = Carbon::now()->toDateString();

        $clients = DB::connection('collection_pgsql')
            ->table('collection_installments')
            ->join('collection_credits', function ($j) {
                $j->on('collection_installments.credit_id', '=', 'collection_credits.id')
                  ->on('collection_installments.company_id', '=', 'collection_credits.company_id');
            })
            ->join('collection_clients', function ($j) {
                $j->on('collection_credits.client_id', '=', 'collection_clients.id')
                  ->on('collection_credits.company_id', '=', 'collection_clients.company_id');
            })
            ->where('collection_installments.company_id', $companyId)
            ->where('collection_installments.status', 'pendiente')
            ->where('collection_installments.due_date', '<', $today)
            ->select(
                'collection_clients.id as client_id',
                'collection_clients.name as client_name',
                'collection_clients.dni as client_dni',
                'collection_clients.phone as client_phone',
                DB::raw('COUNT(collection_installments.id) as overdue_installments'),
                DB::raw('COALESCE(SUM(collection_installments.amount - collection_installments.paid_amount), 0) as overdue_amount'),
                DB::raw("MAX(CURRENT_DATE - collection_installments.due_date) as max_days_overdue")
            )
            ->groupBy('collection_clients.id', 'collection_clients.name', 'collection_clients.dni', 'collection_clients.phone')
            ->havingRaw("MAX(CURRENT_DATE - collection_installments.due_date) >= ?", [$minDays])
            ->orderByDesc('overdue_amount')
            ->get();

        $totalOverdue = (float) $clients->sum('overdue_amount');
        $totalClients = $clients->count();

        return [
            'date' => $today,
            'total_clients_overdue' => $totalClients,
            'total_overdue_amount' => $totalOverdue,
            'clients' => $clients->map(fn($c) => [
                'client_id' => $c->client_id,
                'name' => $c->client_name,
                'dni' => $c->client_dni,
                'phone' => $c->client_phone,
                'overdue_installments' => (int) $c->overdue_installments,
                'overdue_amount' => (float) $c->overdue_amount,
                'max_days_overdue' => (int) $c->max_days_overdue,
                'risk' => $this->calculateRisk((int) $c->max_days_overdue),
            ]),
        ];
    }

    private function calculateRisk(int $days): string
    {
        if ($days >= 30) return 'critico';
        if ($days >= 15) return 'alto';
        if ($days >= 7) return 'medio';
        return 'bajo';
    }

    /**
     * REPORTE 3: Recaudo
     */
    public function recaudo(int $companyId, string $fromDate, string $toDate)
    {
        // Pagos con join a creditos y clientes para enriquecer
        $payments = DB::connection('collection_pgsql')
            ->table('collection_payments')
            ->leftJoin('collection_credits', function ($j) {
                $j->on('collection_payments.credit_id', '=', 'collection_credits.id')
                  ->on('collection_payments.company_id', '=', 'collection_credits.company_id');
            })
            ->leftJoin('collection_clients', function ($j) {
                $j->on('collection_credits.client_id', '=', 'collection_clients.id')
                  ->on('collection_credits.company_id', '=', 'collection_clients.company_id');
            })
            ->where('collection_payments.company_id', $companyId)
            ->whereNull('collection_payments.deleted_at')
            ->whereBetween('collection_payments.recorded_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
            ->select(
                'collection_payments.id',
                'collection_payments.amount_paid',
                'collection_payments.payment_method',
                'collection_payments.installment_number',
                'collection_payments.recorded_at',
                'collection_payments.receipt_number',
                'collection_payments.credit_id',
                'collection_clients.name as client_name',
                'collection_clients.dni as client_dni'
            )
            ->orderByDesc('collection_payments.recorded_at')
            ->get();

        $total = (float) $payments->sum('amount_paid');
        $count = $payments->count();

        // Cobros por dia
        $byDay = $payments->groupBy(fn($p) => Carbon::parse($p->recorded_at)->toDateString())
            ->map(fn($g, $day) => [
                'date' => $day,
                'count' => $g->count(),
                'total' => (float) $g->sum('amount_paid'),
            ])
            ->sortBy('date')->values();

        // Cobros por metodo
        $byMethod = $payments->groupBy(fn($p) => $p->payment_method ?: 'Efectivo')
            ->map(fn($g, $method) => [
                'method' => $method,
                'count' => $g->count(),
                'total' => (float) $g->sum('amount_paid'),
            ])->values();

        // Detalle de cada pago
        $detail = $payments->map(fn($p) => [
            'id' => $p->id,
            'date' => Carbon::parse($p->recorded_at)->toDateString(),
            'time' => Carbon::parse($p->recorded_at)->format('H:i'),
            'client_name' => $p->client_name ?: 'Cliente eliminado',
            'client_dni' => $p->client_dni,
            'credit_ref' => $p->credit_id ? "CR-{$p->credit_id}" : '-',
            'installment_number' => $p->installment_number,
            'method' => $p->payment_method ?: 'Efectivo',
            'reference' => $p->receipt_number,
            'amount' => (float) $p->amount_paid,
        ]);

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_amount' => $total,
            'total_count' => $count,
            'by_day' => $byDay,
            'by_method' => $byMethod,
            'detail' => $detail,
        ];
    }

    /**
     * REPORTE 4: Gastos
     */
    public function gastos(int $companyId, string $fromDate, string $toDate)
    {
        $expenses = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->whereBetween('recorded_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
            ->orderByDesc('recorded_at')
            ->get();

        $total = (float) $expenses->sum('amount');
        $count = $expenses->count();

        // Por categoria
        $byCategory = $expenses->groupBy('category')
            ->map(fn($g, $cat) => [
                'category' => $cat ?: 'Sin categoria',
                'count' => $g->count(),
                'total' => (float) $g->sum('amount'),
                'percentage' => 0, // se calcula despues
            ])
            ->sortByDesc('total')->values();

        if ($total > 0) {
            $byCategory = $byCategory->map(function ($c) use ($total) {
                $c['percentage'] = round(($c['total'] / $total) * 100, 1);
                return $c;
            });
        }

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_amount' => $total,
            'total_count' => $count,
            'by_category' => $byCategory,
            'detail' => $expenses->map(fn($e) => [
                'id' => $e->id,
                'amount' => (float) $e->amount,
                'category' => $e->category,
                'description' => $e->description,
                'date' => Carbon::parse($e->recorded_at)->toDateString(),
                'time' => Carbon::parse($e->recorded_at)->format('H:i'),
            ]),
        ];
    }

    /**
     * REPORTE 5: Cartera
     */
    public function cartera(int $companyId)
    {
        $today = Carbon::now()->toDateString();

        $totalActive = CollectionCredit::where('company_id', $companyId)
            ->where('status', 'active')->count();

        $totalDisbursed = (float) CollectionCredit::where('company_id', $companyId)
            ->where('status', 'active')->sum('amount');

        $totalPending = (float) CollectionInstallment::where('company_id', $companyId)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->sum(DB::raw('amount - paid_amount'));

        $totalCollected = (float) CollectionInstallment::where('company_id', $companyId)
            ->sum('paid_amount');

        // Distribucion
        $alDia = CollectionInstallment::where('company_id', $companyId)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->where('due_date', '>=', $today)->count();

        $vencidas = CollectionInstallment::where('company_id', $companyId)
            ->where('status', 'pendiente')
            ->where('due_date', '<', $today)->count();

        $criticas = CollectionInstallment::where('company_id', $companyId)
            ->where('status', 'pendiente')
            ->where('due_date', '<', Carbon::now()->subDays(7)->toDateString())->count();

        return [
            'date' => $today,
            'total_active_credits' => $totalActive,
            'total_disbursed' => $totalDisbursed,
            'total_pending' => $totalPending,
            'total_collected' => $totalCollected,
            'recovery_rate' => $totalDisbursed > 0 ? round(($totalCollected / $totalDisbursed) * 100, 1) : 0,
            'distribution' => [
                'al_dia' => $alDia,
                'vencidas' => $vencidas,
                'criticas' => $criticas,
            ],
        ];
    }

    /**
     * REPORTE 6: Estado de Cuenta del Cliente
     */
    public function estadoCuenta(int $companyId, int $clientId)
    {
        $client = DB::connection('collection_pgsql')
            ->table('collection_clients')
            ->where('company_id', $companyId)
            ->where('id', $clientId)
            ->first();

        if (!$client) return null;

        // Un credito anulado no es cartera: no suma saldo ni cuotas. Se excluye
        // del reporte por la misma razon que la ficha del cliente lo saca de la
        // vista diaria (scope notCancelled).
        $credits = CollectionCredit::where('company_id', $companyId)
            ->where('client_id', $clientId)
            ->notCancelled()
            ->get();

        $creditsData = $credits->map(function ($credit) use ($companyId) {
            $installments = CollectionInstallment::where('company_id', $companyId)
                ->where('credit_id', $credit->id)
                ->orderBy('installment_number')
                ->get();

            return [
                'credit_id' => $credit->id,
                'amount' => (float) $credit->amount,
                'status' => $credit->status,
                'created_at' => $credit->created_at,
                'total_installments' => $credit->total_installments,
                'paid_amount' => (float) $installments->sum('paid_amount'),
                'pending_amount' => (float) $installments->sum(fn($i) => $i->amount - $i->paid_amount),
                'installments' => $installments->map(fn($i) => [
                    'number' => $i->installment_number,
                    'due_date' => $i->due_date?->toDateString(),
                    'amount' => (float) $i->amount,
                    'paid_amount' => (float) $i->paid_amount,
                    'status' => $i->status,
                ]),
            ];
        });

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'dni' => $client->dni,
                'phone' => $client->phone,
                'address' => $client->address,
            ],
            'credits' => $creditsData,
            'summary' => [
                'total_credits' => $credits->count(),
                'total_disbursed' => (float) $credits->sum('amount'),
                'total_paid' => (float) $creditsData->sum('paid_amount'),
                'total_pending' => (float) $creditsData->sum('pending_amount'),
            ],
        ];
    }
}
