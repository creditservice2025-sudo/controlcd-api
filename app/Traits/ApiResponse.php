<?php

namespace App\Traits;

use Illuminate\Http\Response;
use Exception;

trait ApiResponse
{
    public function successResponse($data, $message = null, $code = Response::HTTP_OK)
    {
        // Asegurar que el código sea un entero
        $code = (int) $code;
        
        // Si hay un mensaje, envolver los datos en un objeto con mensaje
        if ($message !== null) {
            return response()->json([
                'data' => $data,
                'message' => $message
            ], $code);
        }
        
        return response()->json($data, $code);
    }

    public function successCreatedResponse($data)
    {
        return $this->successResponse($data, null, Response::HTTP_CREATED);
    }

    public function errorResponse($message, $code)
    {
        // Asegurar que el código sea un entero
        $code = (int) $code;
        return response()->json(['message' => $message, 'code' => $code], $code);
    }

    public function errorMessage($message, $code)
    {
        $code = (int) $code;
        return response($message, $code)->header('Content-Type', 'application/json');
    }

    public function errorUnprocessableEntityResponse($message)
    {
        return $this->errorResponse($message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function errorNotFoundResponse($message = '404 Not Found')
    {
        return $this->errorResponse($message, Response::HTTP_NOT_FOUND);
    }

    public function errorForbiddenResponse($message = '403 Requesting the URL is prohibited')
    {
        return $this->errorResponse($message, Response::HTTP_FORBIDDEN);
    }

    public function handlerException($message, $code = Response::HTTP_INTERNAL_SERVER_ERROR) {
        return $this->errorResponse($message, $code);
    }
}
