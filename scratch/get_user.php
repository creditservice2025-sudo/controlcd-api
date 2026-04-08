<?php
$u = App\Models\User::where('name', 'like', '%jesus%')->first();
echo json_encode(['id' => $u->id, 'name' => $u->name, 'role_id' => $u->role_id, 'routes' => $u->userRoutes->pluck('seller_id')->toArray(), 'company' => $u->company_id]);
