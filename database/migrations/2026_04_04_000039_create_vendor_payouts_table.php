<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            $table->foreignUuid('settlement_id')->nullable()->references('uuid')->on('settlements')->nullOnDelete();
            
            $table->decimal('amount', 12, 4);
            $table->char('currency', 3);
            $table->enum('payout_method', ['paypal', 'stripe', 'bank_transfer', 'manual']);
            $table->json('payout_account_details')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->string('gateway_payout_id')->nullable();
            $table->json('gateway_response')->nullable();
            
            $table->foreignUuid('processed_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('status');
            $table->index('gateway_payout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payouts');
    }
};