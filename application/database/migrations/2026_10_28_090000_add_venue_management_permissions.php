<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminId === null) {
            throw new RuntimeException('The admin role must exist before assigning permissions.');
        }

        foreach (['venues.update', 'venues.delete'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name]);
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => DB::table('permissions')->where('name', $name)->value('id'),
                'role_id' => $adminId,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve grants that may have existed before this migration.
    }
};
