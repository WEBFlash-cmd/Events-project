<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionsByRole = [
            'admin' => ['ticket-types.create', 'ticket-types.create-any', 'ticket-types.update', 'ticket-types.update-any'],
            'organizer' => ['ticket-types.create', 'ticket-types.update'],
        ];

        foreach ($permissionsByRole as $roleName => $permissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId === null) {
                throw new RuntimeException("Role {$roleName} not found.");
            }

            foreach ($permissions as $permissionName) {
                DB::table('permissions')->insertOrIgnore(['name' => $permissionName]);
                $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');

                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', [
            'ticket-types.create', 'ticket-types.create-any',
            'ticket-types.update', 'ticket-types.update-any',
        ])->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
