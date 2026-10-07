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
        DB::table('permissions')->insertOrIgnore([
            'name' => 'events.create',
        ]);

        $permissionId = DB::table('permissions')
            ->where('name', 'events.create')
            ->value('id');

        foreach (['admin', 'organizer'] as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->value('id');

            if ($roleId === null) {
                throw new RuntimeException("Role {$roleName} not found.");
            }

            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
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
