<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->boolean('allows_ticket_cancellation')->default(false));
        Schema::table('tickets', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable());
        Schema::table('payments', fn (Blueprint $table) => $table->unsignedBigInteger('refund_amount')->default(0));
        DB::table('payments')->where('refund_required', true)->update(['refund_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('refund_amount'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('allows_ticket_cancellation'));
    }
};
