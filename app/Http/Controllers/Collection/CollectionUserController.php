<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Models\Collection\CollectionUserProfile;
use App\Services\Collection\CollectionUserService;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionUserController extends Controller
{
    use ResolvesCollectionCompany;

    public function __construct(private readonly CollectionUserService $userService)
    {
    }

    public function index(Request $request)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->userService->getCompanyUsers($companyId);
    }

    public function store(Request $request)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
            'role' => 'required|string|in:' . implode(',', array_keys(CollectionUserProfile::ROLES)),
        ]);

        return $this->userService->createUser(
            $request->only(['name', 'email', 'password', 'phone', 'role']),
            $companyId,
            Auth::id()
        );
    }

    public function updateRole(Request $request, int $userId)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'role' => 'required|string|in:' . implode(',', array_keys(CollectionUserProfile::ROLES)),
        ]);

        return $this->userService->updateRole($userId, $companyId, $request->input('role'));
    }

    public function toggleAccess(Request $request, int $userId)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->userService->toggleAccess($userId, $companyId);
    }

    public function activity(Request $request, int $userId)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->userService->getUserActivity($userId, $companyId);
    }

    public function updatePermissions(Request $request, int $userId)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        return $this->userService->updatePermissions(
            $userId,
            $companyId,
            $request->input('permissions', [])
        );
    }

    public function availablePermissions()
    {
        return response()->json(['permissions' => \App\Services\Collection\CollectionUserService::ALL_PERMISSIONS]);
    }

    public function resetPassword(Request $request, int $userId)
    {
        $this->guardAdmin();
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        return $this->userService->resetPassword($userId, $companyId);
    }

    public function roles()
    {
        return response()->json(['roles' => CollectionUserProfile::ROLES]);
    }

    private function guardAdmin()
    {
        $user = Auth::user();
        if (!in_array((int) $user->role_id, [1, 2])) {
            abort(403, 'Solo administradores pueden gestionar usuarios');
        }
    }
}
