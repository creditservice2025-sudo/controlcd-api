<?php

namespace App\Http\Controllers;

use App\Services\RoleService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class RoleController extends Controller
{
    use ApiResponse;

    protected $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function index(Request $request)
    {
        try {
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            return  $this->roleService->getAllRoles($search, $perPage);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        $role = $this->roleService->getRoleById($id);
        return response()->json($role);
    }

    public function store(Request $request)
    {
        $role = $this->roleService->createRole($request->all());
        return response()->json($role, 201);
    }

    public function update(Request $request, $id)
    {
        $role = $this->roleService->updateRole($id, $request->all());
        return response()->json($role);
    }

    public function destroy($id)
    {
        $role = $this->roleService->deleteRole($id);
        return response()->json(null, 204);
    }
}
