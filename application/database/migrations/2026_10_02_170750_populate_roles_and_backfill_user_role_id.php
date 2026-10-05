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
        $roles = ['admin', 'organizer', 'participant'];

        foreach ($roles as $roleName) {
            DB::table('roles')->insertOrIgnore(['name' => $roleName]);
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            DB::table('users')
                ->where('role', $roleName)
                ->whereNull('role_id')
                ->update(['role_id' => $roleId]);

        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Автоматический откат не предусмотрен:
        // нельзя отличить ранее существовавшие данные от добавленных миграцией.
    }
};
