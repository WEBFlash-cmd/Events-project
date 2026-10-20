<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['organizer' => ['events.reports'], 'admin' => ['events.reports', 'events.reports-any']] as $role => $names) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            if ($roleId === null) {
                throw new RuntimeException("Role {$role} not found.");
            }
            foreach ($names as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name]);
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => DB::table('permissions')->where('name', $name)->value('id'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', ['events.reports', 'events.reports-any'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
