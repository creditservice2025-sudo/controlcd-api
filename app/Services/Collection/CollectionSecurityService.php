<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionAuthCode;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionSecurityService
{
    use ApiResponse;

    public function generateDeletionToken(array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $requestId = 'DEL-' . strtoupper(Str::random(4));
        $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        // Manual ID handled by project pattern
        $newId = (int) CollectionAuthCode::where('company_id', $companyId)->max('id') + 1;

        $auth = CollectionAuthCode::create([
            'id' => $newId,
            'company_id' => $companyId,
            'request_id' => $requestId,
            'code' => $code,
            'action_type' => 'delete_payment',
            'metadata' => [
                'installment_id' => $payload['installment_id'] ?? null,
                'collector_id' => Auth::id(),
                'collector_name' => Auth::user()->name ?? 'Desconocido',
            ],
            'status' => 'pending',
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now(),
        ]);

        return $this->successResponse([
            'request_id' => $auth->request_id,
            'expires_in' => 15, // minutes
            'manager_phone' => '584142204895', // User's requested test phone
        ]);
    }

    public function validateToken(string $requestId, string $code, int $companyId)
    {
        $auth = CollectionAuthCode::where('company_id', $companyId)
            ->where('request_id', $requestId)
            ->where('code', $code)
            ->where('status', 'pending')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$auth) return false;

        $auth->update(['status' => 'used']);
        return true;
    }

    public function getPendingTokens($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        return CollectionAuthCode::where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function resolveCompanyId($requestedId)
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        return $user->company->id ?? $user->seller->company_id ?? null;
    }
}
