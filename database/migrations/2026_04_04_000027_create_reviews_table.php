<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->integer('magento_review_id')->nullable();
            $table->integer('magento_product_id');
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('customer_id')->nullable()->references('uuid')->on('users')->nullOnDelete();
            $table->foreignUuid('vendor_id')->nullable()->references('uuid')->on('vendors')->nullOnDelete();
            $table->foreignUuid('vendor_store_id')->references('uuid')->on('vendor_stores')->cascadeOnDelete();
            $table->uuid('vendor_product_id')->nullable();
            $table->foreign('vendor_product_id')->references('id')->on('vendor_products')->nullOnDelete();
            $table->foreignUuid('moderated_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            $table->tinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('images')->nullable();
            $table->string('language_code', 10)->default('en');
            $table->boolean('verified_purchased')->default(false);
            $table->integer('helpful_count')->default(0);
            $table->integer('reported_count')->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('pending');
            $table->text('rejected_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->text('vendor_response')->nullable();
            $table->timestamp('vendor_response_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Simple indexes
            $table->index('magento_review_id');
            $table->index('magento_product_id');
            $table->index('status');
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};