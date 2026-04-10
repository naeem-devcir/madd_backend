<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['platform', 'vendor']);
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->nullable()->references('uuid')->on('vendors')->nullOnDelete();
            
            $table->enum('discount_type', ['percentage', 'fixed_amount', 'free_shipping', 'buy_x_get_y']);
            $table->decimal('discount_value', 12, 4);
            $table->decimal('min_order_amount', 12, 4)->default(0);
            $table->integer('max_uses')->nullable();
            $table->integer('used_count')->default(0);
            $table->integer('usage_limit_per_transaction')->default(1);
            $table->integer('per_customer_limit')->default(1);
            $table->boolean('exclude_sale_items')->default(false);
            $table->json('allowed_emails')->nullable();
            $table->json('allowed_roles')->nullable();
            $table->json('combination_rules')->nullable();
            $table->decimal('budget_limit', 12, 4)->nullable();
            $table->decimal('spent_amount', 12, 4)->default(0);
            $table->enum('applicable_to', ['all', 'products', 'vendors', 'stores'])->default('all');
            $table->json('applicable_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('magento_rule_id')->nullable();
            $table->integer('magento_coupon_id')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('code');
            $table->index('is_active');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};