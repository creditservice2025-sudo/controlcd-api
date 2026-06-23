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
            ->select(
                'collection_installments.id as installment_id',
                'collection_installments.installment_number',
                'collection_installments.due_date',
                'collection_installments.amount',
                'collection_installments.paid_amount',
                'collection_credits.id as credit_id',
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
            'total_amount' => (float) $installments->sum(fn($i) => (float) $i->amount - (float) $i->paid_amount),
            'clients' => $installments->map(function ($i) use ($sentToday, $todayCarbon) {
                $dueDate = Carbon::parse($i->due_date);
                // Positivo si ya vencio, 0 si vence hoy, negativo si es a futuro.
                $daysOverdue = $dueDate->diffInDays($todayCarbon, false);
                return [
                    'installment_id' => $i->installment_id,
                    'installment_number' => $i->installment_number,
                    'credit_id' => $i->credit_id,
                    'client_id' => $i->client_id,
                    'client_name' => $i->client_name,
                    'client_dni' => $i->client_dni,
                    'client_phone' => $i->client_phone,
                    'client_address' => $i->client_address,
                    'due_date' => $i->due_date,
                    'days_overdue' => (int) $daysOverdue,
                    'is_overdue' => $daysOverdue > 0,
                    'pending_amount' => round((float) $i->amount - (float) $i->paid_amount, 2),
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
