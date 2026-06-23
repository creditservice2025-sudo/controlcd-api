<?php

namespace App\Services;

use App\Models\CompanySubscription;
use App\Models\Plan;
use App\Models\SubscriptionAudit;
use App\Models\SubscriptionPayment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestor de planes y suscripciones.
 *
 * Centraliza:
 *   - CRUD de planes
 *   - Asignar/cambiar suscripción a una empresa
 *   - Registrar pagos y extender el ciclo
 *   - Cambios de status (cancel, suspend, reactivate)
 *   - Auditoría de TODOS los cambios (quién, cuándo, qué)
 *
 * Toda operación que muta una suscripción genera un registro en
 * subscription_audits — fuente de verdad para soporte y compliance.
 */
class SubscriptionService
{
    use ApiResponse;

    // === Planes ===========================================================

    public function listPlans(bool $onlyActive = false)
    {
        try {
            $q = Plan::query()->orderBy('sort_order')->orderBy('id');
            if ($onlyActive) {
                $q->where('is_active', true);
            }
            return $this->successResponse([
                'success' => true,
                'data' => $q->get(),
            ]);
        } catch (\Exception $e) {
            Log::error('[subscription.listPlans] ' . $e->getMessage());
            return $this->errorResponse('Error al obtener los planes', 500);
        }
    }

    public function createPlan(array $data)
    {
        try {
            $plan = Plan::create($data);
            return $this->successResponse([
                'success' => true,
                'message' => 'Plan creado',
                'data' => $plan,
            ]);
        } catch (\Exception $e) {
            Log::error('[subscription.createPlan] ' . $e->getMessage());
            return $this->errorResponse('Error al crear el plan', 500);
        }
    }

    public function updatePlan(int $planId, array $data)
    {
        try {
            $plan = Plan::findOrFail($planId);
            $plan->update($data);
            return $this->successResponse([
                'success' => true,
                'message' => 'Plan actualizado',
                'data' => $plan,
            ]);
        } catch (\Exception $e) {
            Log::error('[subscription.updatePlan] ' . $e->getMessage());
            return $this->errorResponse('Error al actualizar el plan', 500);
        }
    }

    public function deletePlan(int $planId)
    {
        try {
            $plan = Plan::findOrFail($planId);
            // Guardrail: no permitir borrar planes con suscripciones activas.
            $hasSubs = CompanySubscription::where('plan_id', $planId)
                ->whereIn('status', CompanySubscription::OPERABLE_STATUSES)
                ->exists();
            if ($hasSubs) {
                return $this->errorResponse(
                    'No se puede eliminar: hay suscripciones activas usando este plan. Desactívelo primero.',
                    422
                );
            }
            $plan->delete();
            return $this->successResponse(['success' => true, 'message' => 'Plan eliminado']);
        } catch (\Exception $e) {
            Log::error('[subscription.deletePlan] ' . $e->getMessage());
            return $this->errorResponse('Error al eliminar el plan', 500);
        }
    }

    // === Suscripciones ===================================================

    /**
     * Asigna un plan a una empresa creando una nueva suscripción.
     * Si la empresa tiene una activa, la cancela primero.
     */
    public function subscribe(int $companyId, int $planId, array $opts = [])
    {
        DB::beginTransaction();
        try {
            $plan = Plan::findOrFail($planId);
            $cycle = $opts['billing_cycle'] ?? 'monthly';
            $price = $plan->priceFor($cycle);

            if ($price === null) {
                return $this->errorResponse(
                    "El plan '{$plan->name}' no ofrece ciclo '{$cycle}'.",
                    422
                );
            }

            // Cancelar suscripción anterior si existe
            $previous = CompanySubscription::where('company_id', $companyId)
                ->whereIn('status', CompanySubscription::OPERABLE_STATUSES)
                ->first();
            if ($previous) {
                $previous->update([
                    'status' => CompanySubscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'auto_renew' => false,
                ]);
                $this->audit($previous, 'cancelled_for_replacement', [
                    'reason' => 'Replaced by new subscription',
                ]);
            }

            $today = Carbon::today();
            $useTrial = $opts['use_trial'] ?? ($plan->trial_days > 0);
            $trialEndsAt = $useTrial ? $today->copy()->addDays((int) $plan->trial_days) : null;
            $endDate = $useTrial
                ? $trialEndsAt
                : $this->computeEndDate($today, $cycle);

            $sub = CompanySubscription::create([
                'company_id' => $companyId,
                'plan_id' => $planId,
                'status' => $useTrial
                    ? CompanySubscription::STATUS_TRIAL
                    : CompanySubscription::STATUS_ACTIVE,
                'billing_cycle' => $cycle,
                'amount' => $price,
                'currency' => $plan->currency,
                'start_date' => $today,
                'end_date' => $endDate,
                'trial_ends_at' => $trialEndsAt,
                'auto_renew' => $opts['auto_renew'] ?? true,
                'notes' => $opts['notes'] ?? null,
            ]);

            $this->audit($sub, 'created', [
                'plan_id' => $planId,
                'plan_name' => $plan->name,
                'billing_cycle' => $cycle,
                'amount' => $price,
                'currency' => $plan->currency,
                'with_trial' => $useTrial,
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Suscripción creada',
                'data' => $sub->load('plan', 'company'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[subscription.subscribe] ' . $e->getMessage());
            return $this->errorResponse('Error al crear la suscripción', 500);
        }
    }

    public function cancel(int $subscriptionId, ?string $reason = null)
    {
        DB::beginTransaction();
        try {
            $sub = CompanySubscription::findOrFail($subscriptionId);
            $oldStatus = $sub->status;

            $sub->update([
                'status' => CompanySubscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'auto_renew' => false,
            ]);

            $this->audit($sub, 'cancelled', [
                'old_status' => $oldStatus,
                'reason' => $reason,
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Suscripción cancelada',
                'data' => $sub->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[subscription.cancel] ' . $e->getMessage());
            return $this->errorResponse('Error al cancelar la suscripción', 500);
        }
    }

    public function suspend(int $subscriptionId, ?string $reason = null)
    {
        DB::beginTransaction();
        try {
            $sub = CompanySubscription::findOrFail($subscriptionId);
            $oldStatus = $sub->status;

            $sub->update([
                'status' => CompanySubscription::STATUS_SUSPENDED,
                'suspended_at' => now(),
            ]);

            $this->audit($sub, 'suspended', [
                'old_status' => $oldStatus,
                'reason' => $reason,
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Suscripción suspendida',
                'data' => $sub->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[subscription.suspend] ' . $e->getMessage());
            return $this->errorResponse('Error al suspender la suscripción', 500);
        }
    }

    public function reactivate(int $subscriptionId)
    {
        DB::beginTransaction();
        try {
            $sub = CompanySubscription::findOrFail($subscriptionId);
            $oldStatus = $sub->status;

            $sub->update([
                'status' => CompanySubscription::STATUS_ACTIVE,
                'suspended_at' => null,
                'cancelled_at' => null,
            ]);

            $this->audit($sub, 'reactivated', [
                'old_status' => $oldStatus,
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Suscripción reactivada',
                'data' => $sub->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[subscription.reactivate] ' . $e->getMessage());
            return $this->errorResponse('Error al reactivar la suscripción', 500);
        }
    }

    // === Pagos ============================================================

    /**
     * Registra un pago y extiende end_date según el ciclo.
     */
    public function recordPayment(int $subscriptionId, array $data)
    {
        DB::beginTransaction();
        try {
            $sub = CompanySubscription::findOrFail($subscriptionId);

            // Idempotency: si se reenvió el mismo pago, retornar el existente.
            if (!empty($data['idempotency_key'])) {
                $existing = SubscriptionPayment::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    DB::rollBack();
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Pago ya registrado (idempotent)',
                        'data' => $existing,
                    ]);
                }
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::today();
            $periodStart = $sub->end_date->copy(); // continúa desde donde terminaba
            $periodEnd = $this->computeEndDate($periodStart, $sub->billing_cycle);

            $payment = SubscriptionPayment::create([
                'subscription_id' => $sub->id,
                'amount' => $data['amount'] ?? $sub->amount,
                'currency' => $data['currency'] ?? $sub->currency,
                'method' => $data['method'] ?? 'transfer',
                'reference' => $data['reference'] ?? null,
                'receipt_url' => $data['receipt_url'] ?? null,
                'paid_at' => $paidAt,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => Auth::id(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // Extender ciclo y pasar a active
            $oldStatus = $sub->status;
            $sub->update([
                'status' => CompanySubscription::STATUS_ACTIVE,
                'end_date' => $periodEnd,
                'suspended_at' => null,
            ]);

            $this->audit($sub, 'payment_recorded', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'old_status' => $oldStatus,
                'new_end_date' => $periodEnd->toDateString(),
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Pago registrado',
                'data' => [
                    'payment' => $payment,
                    'subscription' => $sub->fresh(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[subscription.recordPayment] ' . $e->getMessage());
            return $this->errorResponse('Error al registrar el pago', 500);
        }
    }

    // === Lectura =========================================================

    /**
     * Resumen de la suscripción actual de una empresa + historial.
     */
    public function summaryForCompany(int $companyId)
    {
        try {
            $current = CompanySubscription::where('company_id', $companyId)
                ->whereIn('status', [
                    CompanySubscription::STATUS_TRIAL,
                    CompanySubscription::STATUS_ACTIVE,
                    CompanySubscription::STATUS_PAST_DUE,
                    CompanySubscription::STATUS_SUSPENDED,
                ])
                ->with('plan')
                ->latest('id')
                ->first();

            $history = CompanySubscription::where('company_id', $companyId)
                ->with('plan')
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            $payments = $current
                ? $current->payments()->with('recordedBy:id,name')->orderByDesc('paid_at')->limit(20)->get()
                : collect();

            $audits = $current
                ? $current->audits()->orderByDesc('id')->limit(30)->get()
                : collect();

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'current' => $current,
                    'history' => $history,
                    'payments' => $payments,
                    'audits' => $audits,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[subscription.summaryForCompany] ' . $e->getMessage());
            return $this->errorResponse('Error al obtener la suscripción de la empresa', 500);
        }
    }

    // === Helpers internos ================================================

    private function computeEndDate(Carbon $start, string $cycle): Carbon
    {
        return match ($cycle) {
            'weekly'   => $start->copy()->addWeek(),
            'biweekly' => $start->copy()->addWeeks(2),
            'annual'   => $start->copy()->addYear(),
            default    => $start->copy()->addMonth(),
        };
    }

    private function audit(CompanySubscription $sub, string $event, array $changes = []): void
    {
        try {
            $user = Auth::user();
            SubscriptionAudit::create([
                'subscription_id' => $sub->id,
                'event' => $event,
                'changes' => $changes,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // La auditoría es "nice to have", no rompe la operación principal.
            Log::warning('[subscription.audit] no se pudo registrar', [
                'subscription_id' => $sub->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
