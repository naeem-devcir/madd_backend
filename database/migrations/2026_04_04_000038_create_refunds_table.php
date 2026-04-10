<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('order_id')->references('uuid')->on('orders')->cascadeOnDelete();
            $table->foreignUuid('payment_transaction_id')->references('uuid')->on('payment_transactions')->cascadeOnDelete();
            $table->decimal('refund_amount', 12, 4);
            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->string('gateway_refund_id')->nullable();
            $table->json('gateway_response')->nullable();
            
            // FIX: Use foreignId() instead of foreignUuid() because users.id is BIGINT
            $table->foreignUuid('processed_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};