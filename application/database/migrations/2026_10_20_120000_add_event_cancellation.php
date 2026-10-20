<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->timestamp('event_cancellation_sent_at')->nullable());
        Schema::table('tickets', fn (Blueprint $table) => $table->boolean('refund_required')->default(false));
        foreach (['organizer' => ['events.cancel'], 'admin' => ['events.cancel', 'events.cancel-any']] as $role => $names) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
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
        $ids = DB::table('permissions')->whereIn('name', ['events.cancel', 'events.cancel-any'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('event_cancellation_sent_at'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('refund_required'));
    }
};
