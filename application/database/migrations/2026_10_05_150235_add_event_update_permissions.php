<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissionsByRole = [
            'admin' => ['events.update', 'events.update-any'],
            'organizer' => ['events.update'],
        ];

        foreach ($permissionsByRole as $roleName => $permissions) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->value('id');

            if ($roleId === null) {
                throw new RuntimeException("Role '$roleName' not found.");
            }

            foreach ($permissions as $permissionName) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $permissionName,
                ]);

                $permissionId = DB::table('permissions')
                    ->where('name', $permissionName)
                    ->value('id');

                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
