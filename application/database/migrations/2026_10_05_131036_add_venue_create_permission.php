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
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        if ($adminRoleId === null) {
            throw new RuntimeException('Admin role not found.');
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => 'venues.create',
        ]);
        $permissionId = DB::table('permissions')
            ->where('name', 'venues.create')
            ->value('id');

        DB::table('permission_role')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $adminRoleId,
        ]);


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Автоматический откат не предусмотрен:
        // право и связь могли существовать до этой миграции.
    }
};
