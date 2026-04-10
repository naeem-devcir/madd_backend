<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            $table->foreignUuid('vendor_store_id')->references('uuid')->on('vendor_stores')->cascadeOnDelete();
            
            $table->unsignedInteger('magento_product_id');
            $table->string('magento_sku', 255);
            $table->string('sku', 255);
            $table->string('name', 500)->nullable();
            $table->string('type_id', 32)->default('simple');
            $table->unsignedInteger('attribute_set_id')->default(4);
            $table->decimal('price', 12, 4)->nullable();
            $table->integer('quantity')->default(0);
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active');
            $table->enum('sync_status', ['synced', 'pending', 'failed', 'deleted'])->default('synced');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_errors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('magento_product_id');
            $table->index('sku');
            $table->index('status');
            $table->index('sync_status');
            
            // Unique constraints
            $table->unique(['vendor_id', 'magento_product_id'], 'vendor_products_vendor_magento_unique');
            $table->unique(['vendor_id', 'sku'], 'vendor_products_vendor_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_products');
    }
};