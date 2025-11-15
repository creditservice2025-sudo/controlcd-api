<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\LoginService;

class AuthController extends Controller
{

    use ApiResponse;

    protected $loginService;

    public function __construct(LoginService $loginService)
    {
        $this->loginService = $loginService;
    }

    public function login(Request $request)
    {
        try {
            $credentials = $request->only(['email', 'password']);
            return $this->loginService->login($credentials);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al iniciar sesión', 500);
        }
    }

    public function logout()
    {
        try {
            return $this->loginService->logout();

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            return $this->loginService->changePassword($request->all());

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            return $this->loginService->sendPasswordResetLink($request->email);

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {

            return $this->loginService->resetPassword($request->all());

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

}
