<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            $table->foreignUuid('vendor_store_id')->references('uuid')->on('vendor_stores')->cascadeOnDelete();
            $table->uuid('vendor_product_id')->nullable();
            $table->foreign('vendor_product_id')->references('id')->on('vendor_products')->nullOnDelete();
            $table->uuid('parent_draft_id')->nullable();
            $table->foreign('parent_draft_id')->references('id')->on('product_drafts')->nullOnDelete();
            
            $table->integer('version')->default(1);
            $table->string('sku', 255);
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->decimal('price', 12, 4);
            $table->decimal('special_price', 12, 4)->nullable();
            $table->timestamp('special_price_from')->nullable();
            $table->timestamp('special_price_to')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('weight', 10, 4)->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'needs_modification'])->default('draft');
            $table->json('product_data');
            $table->json('media_gallery')->nullable();
            $table->json('categories')->nullable();
            $table->json('attributes')->nullable();
            $table->json('seo_data')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('auto_approve')->default(false);
            $table->timestamp('scheduled_publish_at')->nullable();
            $table->unsignedInteger('magento_product_id')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            
            $table->index(['vendor_id', 'status']);
            $table->index('sku');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_drafts');
    }
};