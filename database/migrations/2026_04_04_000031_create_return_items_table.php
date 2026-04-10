<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('return_id')->references('uuid')->on('returns')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->references('uuid')->on('order_items')->cascadeOnDelete();
            $table->uuid('vendor_product_id')->nullable();
            $table->foreign('vendor_product_id')->references('id')->on('vendor_products')->nullOnDelete();
            
            $table->integer('quantity');
            $table->decimal('refund_amount', 12, 4)->nullable();
            $table->decimal('restocking_fee', 12, 4)->default(0);
            $table->enum('condition', ['new', 'like_new', 'used_good', 'used_fair', 'damaged', 'defective'])->default('new');
            $table->enum('reason', ['defective', 'wrong_size', 'wrong_color', 'not_as_described', 'damaged_in_shipping', 'no_longer_needed', 'better_price', 'wrong_item_shipped', 'other'])->nullable();
            $table->text('reason_notes')->nullable();
            $table->enum('inspection_status', ['pending', 'approved', 'rejected', 'partial'])->default('pending');
            $table->text('inspection_notes')->nullable();
            $table->json('customer_images')->nullable();
            $table->json('inspection_images')->nullable();
            $table->enum('resolution', ['refund', 'exchange', 'store_credit', 'repair', 'replacement', 'none'])->nullable();
            $table->uuid('exchange_product_id')->nullable();
            $table->string('exchange_sku', 255)->nullable();
            $table->enum('disposition', ['restock', 'refurbish', 'donate', 'recycle', 'destroy'])->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('inspection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};