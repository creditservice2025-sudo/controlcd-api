<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class CollectionPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CollectionPaymentService $collectionPaymentService)
    {
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'credit_id' => 'required|integer',
                'company_id' => 'required|integer',
                'installment_numbers' => 'nullable|array',
                'installment_numbers.*' => 'integer',
                'payments' => 'nullable|string', // JSON on multipart
                'payment_date' => 'nullable|date',
                'amount_total' => 'nullable|numeric',
                'payment_method' => 'nullable|string',
                'reference' => 'nullable|string',
                'notes' => 'nullable|string',
                'voucher' => 'nullable|image|max:5120',
            ]);

            // Handle Multipart file upload
            if ($request->hasFile('voucher')) {
                $path = $request->file('voucher')->store('collection/vouchers', 'public');
                $validated['voucher_path'] = $path;
            }

            // If payments is a string (JSON from FormData), decode it
            if (isset($validated['payments']) && is_string($validated['payments'])) {
                $validated['payments'] = json_decode($validated['payments'], true);
            }

            return $this->collectionPaymentService->recordPayment($validated);
        } catch (\Exception $e) {
            \Log::error('Error al registrar pago Collection: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);
            return $this->errorResponse('Error al registrar pago: ' . $e->getMessage(), 500);
        }
    }
}
