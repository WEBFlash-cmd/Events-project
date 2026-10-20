<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('cashbox_id');
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('created');
            $table->text('payment_url')->nullable();
            $table->string('error_code')->nullable();
            $table->boolean('refund_required')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('number')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('event_id')->constrained();
            $table->foreignId('ticket_type_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('order_item_id')->constrained();
            $table->unsignedInteger('sequence');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['order_item_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('payments');
    }
};
