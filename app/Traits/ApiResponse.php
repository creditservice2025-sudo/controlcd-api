<?php

namespace App\Traits;

use Illuminate\Http\Response;
use Exception;

trait ApiResponse
{
    public function successResponse($data, $code = Response::HTTP_OK)
    {
        return response($data, $code)->header('Content-Type', 'application/json');
    }

    public function successCreatedResponse($data)
    {
        return $this->successResponse($data, Response::HTTP_CREATED);
    }

    public function errorResponse($message, $code)
    {
        return response()->json(['message' => $message, 'code' => $code], $code);
    }

    public function errorMessage($message, $code)
    {
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
