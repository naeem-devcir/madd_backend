<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sharing', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_product_id')->constrained('vendor_products')->cascadeOnDelete();
            $table->foreignId('target_store_id')->constrained('vendor_stores')->cascadeOnDelete();
            $table->enum('sharing_type', ['full', 'partial', 'referral'])->default('full');
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->decimal('markup_percentage', 5, 2)->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['source_product_id', 'target_store_id'], 'product_sharing_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sharing');
    }
};
