<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_wishlists', function (Blueprint $table) {
            $table->uuid('id')->primary(); // only primary
            $table->uuid('customer_id');
            $table->uuid('product_id');
            $table->uuid('store_id')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('customer_id')->references('uuid')->on('users')->onDelete('cascade');
            $table->foreign('product_id')->references('uuid')->on('vendor_products')->onDelete('cascade');
            $table->foreign('store_id')->references('uuid')->on('vendor_stores')->onDelete('cascade');

            $table->unique(['customer_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_wishlists');
    }
};
