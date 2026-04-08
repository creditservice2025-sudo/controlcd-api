<?php
$users = App\Models\User::where('name', 'like', '%jesus%')->get();
foreach($users as $u) {
    echo "ID: $u->id, Name: $u->name, Role: $u->role_id, Company_User: ".($u->company ? $u->company->id : 'none')."\n";
}
