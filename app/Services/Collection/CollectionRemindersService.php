<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionReminder;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionRemindersService
{
    use ApiResponse;

    /**
     * Zona horaria de negocio. El sistema opera en Venezuela; el servidor
     * puede estar en UTC y calcular mal el "hoy" si se usa Carbon::now() sin zona.
     * Ver CLAUDE.md: "nunca asumir UTC al calcular cortes diarios".
     */
    private const BUSINESS_TZ = 'America/Caracas';

    /**
     * Lista clientes con cuota pendiente hasta N dias en el futuro.
     * Incluye cuotas vencidas (overdue) — los cobradores necesitan recordarlas igual.
     * Por defecto: hasta manana (1 dia de anticipacion).
     */
    public function getUpcoming(int $companyId, int $daysAhead = 1)
    {
        $today = Carbon::now(self::BUSINESS_TZ)->toDateString();
        $targetDate = Carbon::now(self::BUSINESS_TZ)->addDays($daysAhead)->toDateString();

        $installments = DB::connection('collection_pgsql')
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
            ->whereIn('collection_installments.status', ['pendiente', 'parcial'])
            ->where('collection_installments.due_date', '<=', $targetDate)
            // Lo exigible se mide POR COMPONENTES, no como `amount - paid_amount`.
            //
            // En el crédito de interés mensual la cuota lleva SOLO interés
            // (principal_amount = 0), pero un abono a capital se imputa a esa
            // misma cuota y engorda `paid_amount`. Restarlo del `amount` mezclaba
            // las dos cuentas: una cuota con 201 de interés impago y 200 abonados
            // a capital daba "1 por cobrar", y con 250 abonados daba negativo y
            // desaparecía de la lista con la deuda de interés intacta.
            //
            // Con esta cuenta sale de la lista solo lo que de verdad no debe nada.
            ->whereRaw(
                '(GREATEST(collection_installments.interest_amount - COALESCE(collection_installments.interest_paid, 0), 0)
                + GREATEST(collection_installments.principal_amount - COALESCE(collection_installments.principal_paid, 0), 0)) > 0'
            )
            ->select(
                'collection_installments.id as installment_id',
                'collection_installments.installment_number',
                'collection_installments.due_date',
                'collection_installments.amount',
                'collection_installments.paid_amount',
                // El interés de la cuota va aparte del total: una planilla de
                // cobranza necesita ver capital e interés separados, no la suma.
                // Estado de la cuota. La planilla solo trae abiertas, pero una
                // 'parcial' ya tiene algo cobrado y eso cambia lo que se cobra.
                'collection_installments.status',
                'collection_installments.interest_amount',
                'collection_installments.interest_paid',
                'collection_installments.principal_amount',
                'collection_installments.principal_paid',
                'collection_installments.principal_base',
                'collection_credits.id as credit_id',
                'collection_credits.amount as credit_amount',
                'collection_credits.interest_rate',
                'collection_credits.route_name',
                // La moneda del crédito: la pantalla formateaba todo en pesos
                // colombianos fijos y a un crédito en soles le mostraba "$" con
                // separadores de otro país.
                'collection_credits.currency as currency',
                // Para la bandera del grupo de moneda en la planilla.
                'collection_credits.country_code as country_code',
                // Capital VIVO del crédito hoy: lo colocado menos lo amortizado.
                // Es el "monto neto" de la planilla, y no sale de la cuota —en
                // este modelo la cuota solo lleva interés (principal_amount = 0)—.
                DB::connection('collection_pgsql')->raw(
                    '(collection_credits.amount - COALESCE((
                        SELECT SUM(pi.principal_paid) FROM collection_installments pi
                        WHERE pi.credit_id = collection_credits.id
                          AND pi.company_id = collection_credits.company_id
                          AND pi.deleted_at IS NULL
                     ), 0)) as remaining_principal'
                ),
                'collection_clients.id as client_id',
                'collection_clients.name as client_name',
                'collection_clients.dni as client_dni',
                'collection_clients.phone as client_phone',
                'collection_clients.address as client_address'
            )
            ->orderBy('collection_installments.due_date')
            ->orderBy('collection_clients.name')
            ->get();

        // Enriquecer con informacion de si ya fue notificado hoy
        $installmentIds = $installments->pluck('installment_id')->toArray();
        $sentToday = [];
        if (!empty($installmentIds)) {
            $sentToday = CollectionReminder::where('company_id', $companyId)
                ->whereIn('installment_id', $installmentIds)
                ->whereDate('sent_at', $today)
                ->get()
                ->groupBy('installment_id')
                ->map(fn($g) => [
                    'count' => $g->count(),
                    'last_sent_at' => $g->max('sent_at'),
                ])
                ->toArray();
        }

        $todayCarbon = Carbon::parse($today);

        return [
            'target_date' => $targetDate,
            'days_ahead' => $daysAhead,
            'today' => $today,
            'total_clients' => $installments->count(),
            // Idem: por componentes. Sumando `amount - paid_amount` el total del
            // día quedaba por debajo del interés que realmente hay que cobrar.
            'total_amount' => round((float) $installments->sum(
                fn($i) => max(0, (float) $i->interest_amount - (float) ($i->interest_paid ?? 0))
                    + max(0, (float) $i->principal_amount - (float) ($i->principal_paid ?? 0))
            ), 2),
            'clients' => $installments->map(function ($i) use ($sentToday, $todayCarbon) {
                $dueDate = Carbon::parse($i->due_date);
                // Positivo si ya vencio, 0 si vence hoy, negativo si es a futuro.
                $daysOverdue = $dueDate->diffInDays($todayCarbon, false);
                return [
                    'installment_id' => $i->installment_id,
                    'installment_number' => $i->installment_number,
                    'credit_id' => $i->credit_id,
                    'currency' => $i->currency ?: 'COP',
                    'country_code' => $i->country_code ?: null,
                    'route_name' => $i->route_name,
                    // Capital: el neto vivo del crédito, no el colocado original.
                    'credit_amount' => round((float) $i->credit_amount, 2),
                    'remaining_principal' => round((float) $i->remaining_principal, 2),
                    // Interés del período, y lo que falta cobrar de él.
                    'status' => $i->status,
                    'interest_rate' => (float) $i->interest_rate,
                    'interest_amount' => round((float) $i->interest_amount, 2),
                    // Lo ya cobrado de esta cuota: es lo que distingue una
                    // 'parcial' de una intacta.
                    'interest_paid_amount' => round((float) ($i->interest_paid ?? 0), 2),
                    'principal_paid_amount' => round((float) ($i->principal_paid ?? 0), 2),
                    // Capital sobre el que se calculó ESTE interés. No siempre
                    // coincide con el vivo de hoy: si hubo abonos después, el
                    // interés del período ya estaba devengado sobre el anterior.
                    'principal_base' => $i->principal_base !== null
                        ? round((float) $i->principal_base, 2)
                        : null,
                    'pending_interest' => round(
                        max(0, (float) $i->interest_amount - (float) ($i->interest_paid ?? 0)),
                        2
                    ),
                    'client_id' => $i->client_id,
                    'client_name' => $i->client_name,
                    'client_dni' => $i->client_dni,
                    'client_phone' => $i->client_phone,
                    'client_address' => $i->client_address,
                    'due_date' => $i->due_date,
                    'days_overdue' => (int) $daysOverdue,
                    'is_overdue' => $daysOverdue > 0,
                    // Lo exigible = interés impago + capital impago DE LA CUOTA.
                    // Ver la nota del filtro: `amount - paid_amount` mezclaba el
                    // abono a capital con el interés y daba cifras irreales.
                    'pending_amount' => round(
                        max(0, (float) $i->interest_amount - (float) ($i->interest_paid ?? 0))
                        + max(0, (float) $i->principal_amount - (float) ($i->principal_paid ?? 0)),
                        2
                    ),
                    'installment_amount' => (float) $i->amount,
                    'notified_today' => isset($sentToday[$i->installment_id]),
                    'notifications_count' => $sentToday[$i->installment_id]['count'] ?? 0,
                    'last_notified_at' => $sentToday[$i->installment_id]['last_sent_at'] ?? null,
                ];
            }),
        ];
    }

    /**
     * Marcar que un recordatorio fue enviado al cliente.
     */
    public function markAsSent(int $companyId, int $installmentId, string $channel = 'whatsapp', ?string $notes = null)
    {
        $installment = DB::connection('collection_pgsql')
            ->table('collection_installments')
            ->join('collection_credits', function ($j) {
                $j->on('collection_installments.credit_id', '=', 'collection_credits.id')
                  ->on('collection_installments.company_id', '=', 'collection_credits.company_id');
            })
            ->where('collection_installments.id', $installmentId)
            ->where('collection_installments.company_id', $companyId)
            ->select(
                'collection_installments.id',
                'collection_credits.id as credit_id',
                'collection_credits.client_id'
            )
            ->first();

        if (!$installment) {
            return $this->errorResponse('Cuota no encontrada', 404);
        }

        $reminder = CollectionReminder::create([
            'company_id' => $companyId,
            'installment_id' => $installmentId,
            'credit_id' => $installment->credit_id,
            'client_id' => $installment->client_id,
            'channel' => $channel,
            'sent_by_user_id' => Auth::id() ?? 0,
            'sent_at' => Carbon::now(),
            'notes' => $notes,
            'created_at' => Carbon::now(),
        ]);

        return $this->successResponse([
            'message' => 'Recordatorio registrado',
            'reminder_id' => $reminder->id,
            'sent_at' => $reminder->sent_at,
        ]);
    }

    /**
     * Historial de recordatorios enviados.
     */
    public function getHistory(int $companyId, int $limit = 50)
    {
        return DB::connection('collection_pgsql')
            ->table('collection_reminders')
            ->leftJoin('collection_clients', function ($j) {
                $j->on('collection_reminders.client_id', '=', 'collection_clients.id')
                  ->on('collection_reminders.company_id', '=', 'collection_clients.company_id');
            })
            ->where('collection_reminders.company_id', $companyId)
            ->orderByDesc('collection_reminders.sent_at')
            ->limit($limit)
            ->select(
                'collection_reminders.id',
                'collection_reminders.installment_id',
                'collection_reminders.credit_id',
                'collection_reminders.channel',
                'collection_reminders.sent_at',
                'collection_reminders.notes',
                'collection_clients.name as client_name',
                'collection_clients.dni as client_dni'
            )
            ->get();
    }
}
