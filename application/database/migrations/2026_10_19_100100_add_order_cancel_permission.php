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

        DB::table('permissions')->insertOrIgnore(['name' => 'orders.cancel-any']);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => DB::table('permissions')->where('name', 'orders.cancel-any')->value('id'),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'orders.cancel-any')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
