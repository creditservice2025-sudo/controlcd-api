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
            // Entra en la planilla lo que hay que cobrar Y lo que YA se cobró hoy.
            //
            // Antes solo lo primero: al registrar el pago la cuota quedaba en
            // cero exigible y desaparecía de la lista. El cobrador terminaba el
            // día sin poder ver lo que había cobrado —la fila se esfumaba— y no
            // tenía forma de revisar un cobro recién hecho. Ahora la cuota se
            // queda hasta el final del día con su estado a la vista, y recién
            // mañana sale de la planilla.
            ->where(function ($q) use ($targetDate, $today) {
                // 1) Lo exigible: cuota abierta y con saldo dentro del rango.
                $q->where(function ($abiertas) use ($targetDate) {
                    $abiertas
                        ->whereIn('collection_installments.status', ['pendiente', 'parcial'])
                        ->where('collection_installments.due_date', '<=', $targetDate)
                        // Lo exigible se mide POR COMPONENTES, no como
                        // `amount - paid_amount`.
                        //
                        // En el crédito de interés mensual la cuota lleva SOLO
                        // interés (principal_amount = 0), pero un abono a capital
                        // se imputa a esa misma cuota y engorda `paid_amount`.
                        // Restarlo del `amount` mezclaba las dos cuentas: una
                        // cuota con 201 de interés impago y 200 abonados a
                        // capital daba "1 por cobrar", y con 250 abonados daba
                        // negativo y desaparecía de la lista con la deuda de
                        // interés intacta.
                        //
                        // Con esta cuenta sale de la lista solo lo que de verdad
                        // no debe nada.
                        ->whereRaw(
                            '(GREATEST(collection_installments.interest_amount - COALESCE(collection_installments.interest_paid, 0), 0)
                            + GREATEST(collection_installments.principal_amount - COALESCE(collection_installments.principal_paid, 0), 0)) > 0'
                        );
                })
                // 2) Lo cobrado hoy, tenga o no saldo. Se busca por el pago y no
                //    por el estado de la cuota: una 'parcial' abonada hoy ya
                //    entra por (1), y lo que agrega esta rama es la que quedó
                //    saldada. `payment_date` es la fecha de negocio del pago —no
                //    `recorded_at`, que es UTC y a la noche cae en el día
                //    siguiente—. La cuota se identifica por (crédito, número):
                //    collection_payments no guarda el id de la cuota.
                ->orWhereExists(function ($pagosDeHoy) use ($today) {
                    $pagosDeHoy
                        ->select(DB::connection('collection_pgsql')->raw(1))
                        ->from('collection_payments')
                        ->whereColumn('collection_payments.company_id', 'collection_installments.company_id')
                        ->whereColumn('collection_payments.credit_id', 'collection_installments.credit_id')
                        ->whereColumn('collection_payments.installment_number', 'collection_installments.installment_number')
                        ->where('collection_payments.payment_date', $today)
                        ->whereNull('collection_payments.deleted_at');
                });
            })
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
                'collection_clients.address as client_address',
                // Descripción de la cuota: el texto que quedó al registrar el
                // pago (referencia o nota). Es lo único escrito a mano que
                // tiene la cuota, y explica un cobro cuando el monto solo no
                // alcanza.
                'collection_installments.notes',
                // Comprobante del cobro: se guarda en la cuota al registrar el
                // pago. Es la prueba de lo cobrado y hasta ahora solo se podia
                // ver entrando al detalle del credito.
                'collection_installments.voucher_path',
                // Con qué se cobró: efectivo, transferencia, Yape… Se elige al
                // registrar el pago y no salía del backend, así que la planilla
                // no podía decir cómo entró la plata.
                'collection_installments.payment_method'
            )
            /*
             * Lo cobrado HOY de esta cuota, ABIERTO EN INTERÉS Y CAPITAL.
             *
             * El total solo no alcanza: en el crédito de interés mensual un
             * abono puede ir entero a capital, y la fila mostraría "cobrado
             * $200" junto a una cuota de interés impaga, sin manera de saber que
             * esos 200 bajaron el préstamo y no la cuota.
             *
             * Son subconsultas y no un join: con dos pagos en el mismo día un
             * join duplicaría la fila de la cuota y todos los totales de la
             * planilla quedarían al doble.
             */
            ->selectRaw(
                '(SELECT COALESCE(SUM(p.amount_paid), 0)
                    FROM collection_payments p
                   WHERE p.company_id = collection_installments.company_id
                     AND p.credit_id = collection_installments.credit_id
                     AND p.installment_number = collection_installments.installment_number
                     AND p.payment_date = ?
                     AND p.deleted_at IS NULL) as paid_today_amount,
                 (SELECT COALESCE(SUM(p.interest_paid), 0)
                    FROM collection_payments p
                   WHERE p.company_id = collection_installments.company_id
                     AND p.credit_id = collection_installments.credit_id
                     AND p.installment_number = collection_installments.installment_number
                     AND p.payment_date = ?
                     AND p.deleted_at IS NULL) as paid_today_interest,
                 (SELECT COALESCE(SUM(p.principal_paid), 0)
                    FROM collection_payments p
                   WHERE p.company_id = collection_installments.company_id
                     AND p.credit_id = collection_installments.credit_id
                     AND p.installment_number = collection_installments.installment_number
                     AND p.payment_date = ?
                     AND p.deleted_at IS NULL) as paid_today_principal',
                [$today, $today, $today]
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
            // Cuenta SOLO lo que falta cobrar: la lista ahora arrastra también
            // las cuotas saldadas hoy, y sumarlas acá inflaría el pendiente.
            'total_clients' => $installments->filter(
                fn($i) => (max(0, (float) $i->interest_amount - (float) ($i->interest_paid ?? 0))
                    + max(0, (float) $i->principal_amount - (float) ($i->principal_paid ?? 0))) > 0
            )->count(),
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
                    // Descripción escrita de la cuota: referencia o nota que se
                    // cargó al registrar el pago.
                    'notes' => $i->notes,
                    // Comprobante del cobro. Estaba guardado en la cuota pero no
                    // salía del backend: la prueba del pago solo se veía entrando
                    // al detalle del crédito.
                    'voucher_path' => $i->voucher_path,
                    'payment_method' => $i->payment_method,
                    // Lo cobrado hoy de esta cuota. Mayor a cero significa que la
                    // fila sigue en la lista porque se cobró hoy, aunque ya no
                    // deba nada.
                    'paid_today_amount' => round((float) ($i->paid_today_amount ?? 0), 2),
                    'paid_today' => round((float) ($i->paid_today_amount ?? 0), 2) > 0,
                    // Abierto por destino: sin esto no hay forma de distinguir un
                    // cobro de la cuota de un abono a capital.
                    'paid_today_interest' => round((float) ($i->paid_today_interest ?? 0), 2),
                    'paid_today_principal' => round((float) ($i->paid_today_principal ?? 0), 2),
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
