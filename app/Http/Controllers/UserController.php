<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\UserService;
use App\Http\Requests\User\UserRequest;
use App\Http\Requests\User\ToggleStatusRequest;

class UserController extends Controller
{

    use ApiResponse;

    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        /* $this->middleware('permission:ver_usuarios')->only('index');
        $this->middleware('permission:crear_usuarios')->only('create');
        $this->middleware('permission:editar_usuarios')->only('update');
        $this->middleware('permission:eliminar_usuarios')->only('delete'); */
    }

    public function create(UserRequest $request)
    {
        try {
            return $this->userService->create($request->all());
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(Request $request, $userId)
    {
        try {
            $params = $request->all();
            return $this->userService->update($userId, $params);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($userId)
    {
        try {
            return $this->userService->delete($userId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            $companyId = $request->get('company_id');
            return $this->userService->getUsers($search, $perPage, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($userId)
    {
        try {
            return $this->userService->getUser($userId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getUsersSelect(Request $request)
    {
        try {
            $companyId = $request->get('company_id');
            return $this->userService->getUsersSelect($request, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getSellersSelect(Request $request)
    {
        try {
            $companyId = $request->get('company_id');
            return $this->userService->getVendorsSelect($request, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleStatus(ToggleStatusRequest $request, $userId)
    {
        try {
            return $this->userService->toggleStatus($userId, $request->input('status'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
      public function me(Request $request)
    {
        try {
            return $this->userService->me();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
