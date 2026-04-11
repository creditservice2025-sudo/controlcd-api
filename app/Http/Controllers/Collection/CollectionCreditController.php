<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionCreditService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionCreditController extends Controller
{
    use ApiResponse;
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionCreditService $collectionCreditService)
    {
    }

    public function store(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'client_id' => 'required|integer|min:1',
            'credit_value' => 'required|numeric|min:0.01',
            'interest_rate' => 'nullable|numeric|min:0',
            'number_installments' => 'required|integer|min:1|max:1000',
            'payment_frequency' => 'nullable|string|max:50',
            'first_installment_date' => 'nullable|date',
            'transfer_bank_name' => 'nullable|string|max:150',
            'transfer_reference_number' => 'nullable|string|max:120',
            'excluded_days' => 'nullable|array',
            'excluded_days.*' => 'string|max:30',
            'images' => 'nullable|array',
            'images.*.file' => 'nullable|file|image|max:4096',
            'images.*.type' => 'nullable|string|max:80',
        ]);

        if ($request->hasFile('images.0.file')) {
            $validated['transfer_voucher_photo'] = $request->file('images.0.file')->store('collection/credits/voucher', 'public');
        }

        if ($request->hasFile('images.1.file')) {
            $validated['transfer_support_photo'] = $request->file('images.1.file')->store('collection/credits/support', 'public');
        }

        $validated['company_id'] = $companyId;

        return $this->collectionCreditService->create($validated);
    }

    public function destroyInstallment(Request $request, int $id)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $securityToken = [
            'request_id' => $request->input('request_id'),
            'code' => $request->input('code'),
        ];
        return $this->collectionCreditService->deleteInstallment(
            $id,
            $securityToken,
            $companyId
        );
    }
}
