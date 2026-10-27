<?php

namespace App\Http\Controllers;

use App\Services\RecaudoVendedorService;
use Illuminate\Support\Facades\Auth;

/**
 * Avance del cobrador hacia su meta de recaudo (la barra de su pantalla).
 *
 * Devuelve SIEMPRE los datos del vendedor autenticado: no recibe un seller_id
 * desde afuera, así nadie puede pedir el avance de otro cambiando la URL. Si la
 * empresa no lo tiene habilitado, responde available=false (lo valida el
 * servidor, no el APK).
 */
class SellerProgressController extends Controller
{
    public function __construct(private RecaudoVendedorService $recaudo)
    {
    }

    public function show()
    {
        try {
            $seller = optional(Auth::user())->seller;
            if (!$seller) {
                return response()->json(['success' => true, 'data' => ['available' => false, 'reason' => 'no_seller']]);
            }

            return response()->json(['success' => true, 'data' => $this->recaudo->progreso($seller)]);
        } catch (\Throwable $e) {
            \Log::error('SellerProgressController: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo obtener el avance.'], 500);
        }
    }
}
