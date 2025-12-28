<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class CheckCompanyStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user) {
            $company = null;

            // 1. Si es el dueño de la empresa
            if ($user->company) {
                $company = $user->company;
            } 
            // 2. Si es un vendedor
            elseif ($user->seller && $user->seller->company) {
                $company = $user->seller->company;
            }
            // 3. Si es un miembro (vinculado al dueño vía parent_id)
            elseif ($user->parent && $user->parent->company) {
                $company = $user->parent->company;
            }

            if ($company) {
                if ($company->status === 'expired') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Su suscripción ha vencido. Por favor contacte al administrador para renovar su plan.',
                        'error_code' => 'SUBSCRIPTION_EXPIRED'
                    ], 403);
                }

                if ($company->status === 'suspended') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Su cuenta ha sido suspendida. Por favor contacte soporte.',
                        'error_code' => 'ACCOUNT_SUSPENDED'
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
