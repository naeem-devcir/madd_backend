<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('rma_number', 50)->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('order_id')->references('uuid')->on('orders')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            $table->foreignUuid('courier_id')->nullable()->references('uuid')->on('couriers')->nullOnDelete();
            
            $table->enum('status', ['requested', 'approved', 'rejected', 'shipped', 'received', 'refunded', 'cancelled'])->default('requested')->index();
            $table->enum('return_type', ['full', 'partial', 'exchange', 'warranty', 'damaged', 'wrong_item'])->default('full');
            $table->enum('reason', ['defective', 'wrong_size', 'wrong_color', 'not_as_described', 'damaged_in_shipping', 'no_longer_needed', 'better_price', 'other'])->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('vendor_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->decimal('refund_amount', 12, 4)->nullable();
            $table->decimal('restocking_fee', 12, 4)->default(0);
            $table->char('currency_code', 3)->default('EUR');
            $table->string('tracking_number', 100)->nullable();
            $table->string('return_label_url', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->enum('inspection_result', ['pending', 'approved', 'partial_approved', 'rejected'])->default('pending');
            $table->text('inspection_notes')->nullable();
            $table->json('images')->nullable();
            $table->json('vendor_images')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_transaction_id', 255)->nullable();
            $table->enum('refund_method', ['original_payment', 'store_credit', 'bank_transfer', 'manual'])->nullable();
            $table->boolean('quality_check_passed')->default(false);
            $table->enum('disposition', ['restock', 'refurbish', 'donate', 'recycle', 'destroy'])->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['vendor_id', 'status']);
            $table->index('rma_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};