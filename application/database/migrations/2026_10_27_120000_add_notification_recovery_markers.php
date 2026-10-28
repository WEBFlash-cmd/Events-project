<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('verification_email_sent_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('event_cancellation_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('verification_email_sent_at'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('event_cancellation_requested_at'));
    }
};
