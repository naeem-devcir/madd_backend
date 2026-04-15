<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('magento_order_id')->unique();
            $table->string('magento_order_increment_id', 50)->unique();
            $table->foreignId('parent_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('vendor_store_id')->constrained('vendor_stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_email', 191);
            $table->string('customer_firstname', 100)->nullable();
            $table->string('customer_lastname', 100)->nullable();
            $table->string('customer_ip', 45)->nullable();
            $table->string('guest_token', 255)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('status', 50)->index();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'chargeback', 'failed'])->default('pending')->index();
            $table->enum('fulfillment_status', ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])->default('pending')->index();
            $table->char('currency_code', 3);
            $table->decimal('currency_rate', 10, 4)->default(1.0000);
            $table->decimal('subtotal', 12, 4);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('shipping_amount', 12, 4)->default(0);
            $table->decimal('discount_amount', 12, 4)->default(0);
            $table->decimal('grand_total', 12, 4);
            $table->decimal('commission_amount', 12, 4)->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('vendor_payout_amount', 12, 4)->nullable();
            $table->string('payment_method', 100);
            $table->decimal('payment_fee', 12, 4)->default(0);
            $table->string('shipping_method', 100)->nullable();
            $table->foreignId('carrier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('tracking_number', 100)->nullable();
            $table->string('coupon_code', 50)->nullable();
            $table->foreignId('coupon_id')->nullable()->index();
            $table->enum('source', ['web', 'mobile', 'marketplace', 'erp', 'pos'])->default('web');
            $table->json('shipping_address');
            $table->json('billing_address');
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settlement_id')->nullable()->index();
            $table->timestamp('synced_at')->useCurrent();
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status', 'created_at'], 'orders_vendor_status_created');
            $table->index(['customer_id', 'created_at'], 'orders_customer_created');
            $table->index(['payment_status', 'created_at'], 'orders_payment_created');
            $table->index('created_at');
            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
