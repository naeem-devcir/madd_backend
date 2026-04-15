<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->integer('magento_item_id')->nullable();
            $table->foreignId('vendor_product_id')->nullable()->constrained('vendor_products')->nullOnDelete();
            $table->integer('magento_product_id')->index();
            $table->string('magento_sku');
            $table->string('vendor_sku', 100)->nullable();
            $table->string('product_sku');
            $table->string('product_name', 500);
            $table->string('product_type', 50)->default('simple');
            $table->decimal('weight', 10, 4)->default(0);
            $table->decimal('qty_ordered', 10, 4);
            $table->decimal('qty_shipped', 10, 4)->default(0);
            $table->decimal('qty_refunded', 10, 4)->default(0);
            $table->decimal('fulfilled_qty', 10, 4)->default(0);
            $table->decimal('price', 12, 4);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 4)->default(0);
            $table->decimal('row_total', 12, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('vendor_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
