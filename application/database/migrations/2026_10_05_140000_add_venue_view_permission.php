<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = [];

        foreach (['admin', 'organizer'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId === null) {
                throw new RuntimeException("Role {$roleName} not found.");
            }

            $roleIds[] = $roleId;
        }

        DB::table('permissions')->insertOrIgnore(['name' => 'venues.view']);
        $permissionId = DB::table('permissions')->where('name', 'venues.view')->value('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
