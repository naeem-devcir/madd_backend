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
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('vendor_store_id')->constrained('vendor_stores')->cascadeOnDelete();
            $table->foreignId('vendor_product_id')->nullable()->constrained('vendor_products')->nullOnDelete();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
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
