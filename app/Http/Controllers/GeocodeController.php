<?php

namespace App\Http\Controllers;

use App\Models\ClientGeolocationHistory;
use App\Services\ReverseGeocodeService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Traducción de coordenadas a dirección legible, BAJO DEMANDA.
 *
 * Por qué bajo demanda y no al registrar el pago: se registran unos 4.000
 * pagos diarios y traducir cada punto a texto exige consultar un servicio
 * externo (~1 segundo por consulta). Hacerlo en el momento del cobro
 * penalizaría la operación más repetida del sistema, y con key de Google
 * costaría cientos de dólares al mes para traducir ubicaciones que casi nadie
 * consulta.
 *
 * Así, solo se resuelve lo que alguien realmente mira. La respuesta se guarda
 * en el historial, de modo que la próxima vez que se abra esa fila ya viene
 * resuelta y no se consulta de nuevo.
 */
class GeocodeController extends Controller
{
    use ApiResponse;

    public function reverse(Request $request, ReverseGeocodeService $reverseGeocode)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            // Opcionales: si vienen, la dirección resuelta se persiste en el
            // historial para no volver a consultarla nunca más.
            'action_type' => 'nullable|string|max:50',
            'action_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $latitude = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');

        $address = $reverseGeocode->resolve($latitude, $longitude);

        if ($address && $request->filled('action_type') && $request->filled('action_id')) {
            ClientGeolocationHistory::where('action_type', $request->input('action_type'))
                ->where('action_id', $request->input('action_id'))
                ->whereNull('address')
                ->update(['address' => $address]);
        }

        return $this->successResponse([
            'success' => true,
            'data' => [
                'address' => $address,
                // false = el proveedor no supo resolverlo; el front deja las
                // coordenadas, que siguen siendo verificables en el mapa.
                'resolved' => !empty($address),
            ],
        ]);
    }
}
