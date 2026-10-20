<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($roleId === null) {
            throw new RuntimeException('Role admin not found.');
        }

        DB::table('permissions')->insertOrIgnore(['name' => 'tickets.view-any']);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => DB::table('permissions')->where('name', 'tickets.view-any')->value('id'),
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'tickets.view-any')->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
