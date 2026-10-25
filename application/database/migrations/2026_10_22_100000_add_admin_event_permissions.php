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
        foreach (['events.view-any', 'events.delete'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name]);
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('name', $name)->value('id'),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', ['events.view-any', 'events.delete'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
