<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionClientService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

class CollectionClientController extends Controller
{
    use ApiResponse;
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionClientService $collectionClientService)
    {
    }

    public function index(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->collectionClientService->list([
            'search' => (string) $request->input('search', ''),
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 10),
            'company_id' => $companyId,
            'country_code' => $request->input('country_code'),
        ]);
    }

    public function show(Request $request, int $clientId)
    {
        try {
            $companyId = $this->resolveOwnCompanyId($request);
            if (!is_int($companyId)) return $companyId;

            $creditId = $request->input('credit_id');
            return $this->collectionClientService->get(
                $clientId,
                $companyId,
                $creditId ? (int) $creditId : null
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Error en el servidor: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dni' => ['required', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:1000',
            'reference' => 'nullable|string|max:500',
            'company_name' => 'nullable|string|max:255',
            'profile_photo' => 'nullable|file|image|max:4096',
            'document_photo' => 'nullable|file|image|max:4096',
        ]);
        $validated['company_id'] = $companyId;

        if ($request->hasFile('profile_photo')) {
            $validated['profile_photo'] = $request->file('profile_photo')->store('collection/clients/profile', 'public');
        }

        if ($request->hasFile('document_photo')) {
            $validated['document_photo'] = $request->file('document_photo')->store('collection/clients/document', 'public');
        }

        return $this->collectionClientService->create($validated);
    }

    public function update(Request $request, int $clientId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dni' => ['required', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:1000',
            'reference' => 'nullable|string|max:500',
            'company_name' => 'nullable|string|max:255',
            'profile_photo' => 'nullable|file|image|max:4096',
            'document_photo' => 'nullable|file|image|max:4096',
        ]);
        $validated['company_id'] = $companyId;

        if ($request->hasFile('profile_photo')) {
            $validated['profile_photo'] = $request->file('profile_photo')->store('collection/clients/profile', 'public');
        }

        if ($request->hasFile('document_photo')) {
            $validated['document_photo'] = $request->file('document_photo')->store('collection/clients/document', 'public');
        }

        return $this->collectionClientService->update($clientId, $validated);
    }

    public function destroy(Request $request, int $clientId)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->collectionClientService->delete($clientId, $companyId);
    }
}
