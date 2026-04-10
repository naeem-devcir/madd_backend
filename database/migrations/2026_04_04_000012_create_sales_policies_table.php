<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_policies', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2)->index();
            $table->string('name', 100);
            $table->json('payment_methods');
            $table->json('shipping_methods');
            $table->json('allowed_currencies')->nullable();
            $table->string('tax_class', 50);
            $table->decimal('min_order_amount', 10, 2)->default(0);
            $table->boolean('guest_checkout_allowed')->default(true);
            $table->integer('return_window_days')->default(14);
            $table->string('terms_url', 500)->nullable();
            $table->string('privacy_policy_url', 500)->nullable();
            $table->text('withdrawal_right_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_policies');
    }
};