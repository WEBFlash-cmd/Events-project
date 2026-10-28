<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->uuid('checkout_reference')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_reference']);
            $table->dropColumn('checkout_reference');
        });
    }
};
