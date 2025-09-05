<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\UserService;
use App\Http\Requests\User\UserRequest;

class UserController extends Controller
{

    use ApiResponse;

    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
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
            return $this->userService->getUsers($search, $perPage);
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
            return $this->userService->getUsersSelect($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getSellersSelect(Request $request)
    {
        try {
            return $this->userService->getVendorsSelect($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleStatus(Request $request, $userId)
    {
        try {
            $params = $request->validate([
                'status' => 'required|string|in:active,inactive',
            ]);

            return $this->userService->toggleStatus($userId, $params['status']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
