<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('order_id')->references('uuid')->on('orders')->cascadeOnDelete();
            $table->string('gateway', 50);
            $table->string('gateway_transaction_id');
            $table->string('parent_transaction_id')->nullable();
            $table->enum('transaction_type', ['authorize', 'capture', 'sale', 'refund', 'void']);
            $table->decimal('amount', 12, 4);
            $table->char('currency', 3);
            $table->enum('status', ['pending', 'authorized', 'captured', 'failed', 'refunded', 'voided'])->default('pending');
            $table->json('payment_method_details')->nullable();
            $table->string('customer_ip', 45)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand', 50)->nullable();
            $table->enum('fraud_status', ['clean', 'suspicious', 'fraud'])->default('clean');
            $table->decimal('fraud_score', 3, 2)->nullable();
            $table->json('gateway_request')->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            
            $table->unique(['gateway', 'gateway_transaction_id']);
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};