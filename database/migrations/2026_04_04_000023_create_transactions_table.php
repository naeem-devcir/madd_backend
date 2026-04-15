<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // foreignUuid: FK references uuid
            $table->foreignId('settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('payable_type')->index();
            $table->unsignedBigInteger('payable_id')->index();
            $table->enum('type', ['sale', 'refund', 'commission', 'adjustment', 'payout']);
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed']);
            $table->decimal('amount', 12, 4);
            $table->decimal('gateway_fee', 12, 4)->default(0);
            $table->char('currency_code', 3);
            $table->decimal('balance_after', 14, 4)->nullable();
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_transaction_id')->nullable()->index();
            $table->string('reference')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->softDeletes();

            // Indexes
            $table->index(['payable_type', 'payable_id']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
