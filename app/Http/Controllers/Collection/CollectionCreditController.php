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
            // Fecha contable del crédito (desembolso). Se valida no-futura en el
            // servicio según la zona horaria del país del crédito.
            'credit_date' => 'nullable|date',
            // Nombre de la ruta y descripción: obligatorios para todo crédito nuevo.
            'route_name' => 'required|string|max:150',
            'description' => 'required|string|max:1000',
            // Credito abierto: N no aplica; se genera 1 cuota y las siguientes al pagar.
            // Se deja nullable para soportar el unico modo actual (monthly_interest_open).
            'number_installments' => 'nullable|integer|min:1|max:1000',
            'payment_frequency' => 'nullable|string|max:50',
            'first_installment_date' => 'nullable|date',
            'installment_distribution_mode' => 'nullable|in:monthly_interest_open',
            'transfer_bank_name' => 'nullable|string|max:150',
            'transfer_reference_number' => 'nullable|string|max:120',
            'excluded_days' => 'nullable|array',
            'excluded_days.*' => 'string|max:30',
            'images' => 'nullable|array',
            'images.*.file' => 'nullable|file|image|max:4096',
            'images.*.type' => 'nullable|string|max:80',
        ], [
            'route_name.required' => 'El nombre de la ruta es obligatorio.',
            'route_name.max' => 'El nombre de la ruta no puede superar los 150 caracteres.',
            'description.required' => 'La descripción es obligatoria.',
            'description.max' => 'La descripción no puede superar los 1000 caracteres.',
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

    /**
     * Corrección de un crédito dentro de la ventana del mismo día.
     * Las reglas duras (día contable, cierre de caja, abonos, adiciones) las
     * aplica el servicio: acá solo se valida la forma de los datos.
     */
    public function update(Request $request, int $creditId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'credit_value' => 'nullable|numeric|min:0.01',
            'interest_rate' => 'nullable|numeric|min:0',
            'first_installment_date' => 'nullable|date',
            // Fecha contable del desembolso. El servicio valida que no sea
            // futura ni caiga en un día con la caja cerrada.
            'credit_date' => 'nullable|date',
            'route_name' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:1000',
            'transfer_bank_name' => 'nullable|string|max:150',
            'transfer_reference_number' => 'nullable|string|max:120',
            'transfer_voucher_photo' => 'nullable|file|image|max:4096',
            'transfer_support_photo' => 'nullable|file|image|max:4096',
        ]);
        $validated['company_id'] = $companyId;

        // Las imágenes anteriores no se borran: la auditoría guarda su ruta y
        // el historial las sigue mostrando.
        if ($request->hasFile('transfer_voucher_photo')) {
            $validated['transfer_voucher_photo'] = $request->file('transfer_voucher_photo')
                ->store('collection/credits/voucher', 'public');
        }

        if ($request->hasFile('transfer_support_photo')) {
            $validated['transfer_support_photo'] = $request->file('transfer_support_photo')
                ->store('collection/credits/support', 'public');
        }

        return $this->collectionCreditService->update($creditId, $validated);
    }

    /**
     * Historial de cambios del crédito y de sus adiciones de capital.
     */
    public function history(Request $request, int $creditId)
    {
        try {
            $companyId = $this->resolveOwnCompanyId($request);
            if (!is_int($companyId)) return $companyId;

            return $this->collectionCreditService->history($creditId, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse('Error en el servidor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Anula un crédito dentro de la ventana del mismo día. No borra la fila:
     * la marca como anulada, reintegra la caja y deja traza.
     */
    public function destroy(Request $request, int $creditId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'Indicá el motivo de la anulación.',
        ]);
        $validated['company_id'] = $companyId;

        return $this->collectionCreditService->destroy($creditId, $validated);
    }

    /** Datos del cartón digital del crédito (pantalla e imagen). */
    public function cardboard(Request $request, int $creditId)
    {
        try {
            $companyId = $this->resolveOwnCompanyId($request);
            if (!is_int($companyId)) return $companyId;

            return $this->collectionCreditService->cardboard($creditId, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse('Error en el servidor: ' . $e->getMessage(), 500);
        }
    }

    /** El mismo cartón, en PDF. */
    public function cardboardPdf(Request $request, int $creditId)
    {
        try {
            $companyId = $this->resolveOwnCompanyId($request);
            if (!is_int($companyId)) return $companyId;

            return $this->collectionCreditService->cardboardPdf($creditId, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse('Error en el servidor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Corrección de una adición de capital dentro de la ventana del mismo día.
     */
    public function updateCapitalAddition(Request $request, int $additionId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'business_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:60',
            'reference_number' => 'nullable|string|max:120',
            'bank_name' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:1000',
            'voucher_photo' => 'nullable|file|image|max:4096',
        ]);
        $validated['company_id'] = $companyId;

        if ($request->hasFile('voucher_photo')) {
            $validated['voucher_photo'] = $request->file('voucher_photo')
                ->store('collection/credits/capital-additions', 'public');
        }

        return $this->collectionCreditService->updateCapitalAddition($additionId, $validated);
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

    /**
     * Liquida un credito abierto: cobra interes pendiente + capital restante,
     * cierra la cuota actual y marca el credito como pagado.
     */
    public function settle(Request $request, int $creditId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'payment_date' => 'nullable|date',
            'timezone' => 'nullable|string|max:80',
            'voucher' => 'nullable|file|image|max:4096',
        ]);

        if ($request->hasFile('voucher')) {
            $validated['voucher_path'] = $request->file('voucher')->store('collection/payments/voucher', 'public');
        }

        return $this->collectionCreditService->settle($companyId, $creditId, $validated);
    }

    /**
     * Agrega capital a un credito existente (modo monthly_interest_open).
     * La cuota vigente no se toca; las siguientes usan el nuevo principal.
     */
    public function addCapital(Request $request, int $creditId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'business_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:120',
            'bank_name' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:500',
            'voucher' => 'nullable|file|image|max:4096',
        ]);

        if ($request->hasFile('voucher')) {
            $validated['voucher_photo'] = $request->file('voucher')->store('collection/capital_additions/voucher', 'public');
        }

        $validated['company_id'] = $companyId;

        return $this->collectionCreditService->addCapital($creditId, $validated);
    }

    /**
     * Historial de adiciones de capital sobre un credito.
     */
    public function listCapitalAdditions(Request $request, int $creditId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->collectionCreditService->listCapitalAdditions($creditId, $companyId);
    }
}
