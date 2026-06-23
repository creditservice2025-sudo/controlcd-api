<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(private SubscriptionService $service)
    {
    }

    // === Planes ===========================================================

    public function plansIndex(Request $request)
    {
        return $this->service->listPlans((bool) $request->boolean('only_active'));
    }

    public function plansStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'slug' => 'required|string|max:80|unique:plans,slug',
            'description' => 'nullable|string',
            'weekly_price' => 'nullable|numeric|min:0',
            'biweekly_price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'annual_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'grace_days' => 'nullable|integer|min:0|max:60',
            'max_sellers' => 'nullable|integer|min:0',
            'max_users' => 'nullable|integer|min:0',
            'max_credits_per_month' => 'nullable|integer|min:0',
            'max_active_credits' => 'nullable|integer|min:0',
            'max_clients' => 'nullable|integer|min:0',
            'features' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
        return $this->service->createPlan($data);
    }

    public function plansUpdate(Request $request, int $planId)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:80',
            'description' => 'nullable|string',
            'weekly_price' => 'nullable|numeric|min:0',
            'biweekly_price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'annual_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'grace_days' => 'nullable|integer|min:0|max:60',
            'max_sellers' => 'nullable|integer|min:0',
            'max_users' => 'nullable|integer|min:0',
            'max_credits_per_month' => 'nullable|integer|min:0',
            'max_active_credits' => 'nullable|integer|min:0',
            'max_clients' => 'nullable|integer|min:0',
            'features' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
        return $this->service->updatePlan($planId, $data);
    }

    public function plansDestroy(int $planId)
    {
        return $this->service->deletePlan($planId);
    }

    // === Suscripciones ===================================================

    public function subscribe(Request $request, int $companyId)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'billing_cycle' => 'nullable|in:weekly,biweekly,monthly,annual',
            'use_trial' => 'nullable|boolean',
            'auto_renew' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
        return $this->service->subscribe(
            $companyId,
            (int) $data['plan_id'],
            $data
        );
    }

    public function cancel(Request $request, int $subscriptionId)
    {
        $reason = $request->input('reason');
        return $this->service->cancel($subscriptionId, $reason);
    }

    public function suspend(Request $request, int $subscriptionId)
    {
        $reason = $request->input('reason');
        return $this->service->suspend($subscriptionId, $reason);
    }

    public function reactivate(int $subscriptionId)
    {
        return $this->service->reactivate($subscriptionId);
    }

    public function recordPayment(Request $request, int $subscriptionId)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'method' => 'nullable|in:transfer,card,cash,pse,mercado_pago,wompi,stripe,other',
            'reference' => 'nullable|string|max:120',
            'receipt_url' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:100',
        ]);
        return $this->service->recordPayment($subscriptionId, $data);
    }

    public function companySummary(int $companyId)
    {
        return $this->service->summaryForCompany($companyId);
    }
}
