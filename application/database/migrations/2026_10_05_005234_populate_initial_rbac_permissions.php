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
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($adminRoleId === null) {
            throw new RuntimeException('The admin role must exist before assigning permissions.');
        }

        $permissions = [
            'users.view',
            'users.update-role',
            'users.block',
            'users.unblock',
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
        ];

        foreach ($permissions as $permissionName) {
            DB::table('permissions')->insertOrIgnore(['name' => $permissionName]);

            $permissionId = DB::table('permissions')
                ->where('name', $permissionName)
                ->value('id');

            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Автоматический откат не предусмотрен:
        // нельзя отличить существовавшие права и связи от добавленных миграцией.
    }
};
