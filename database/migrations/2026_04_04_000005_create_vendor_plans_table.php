<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2);
            $table->decimal('setup_fee', 10, 2)->default(0);
            $table->decimal('transaction_fee_percentage', 5, 2)->default(0);
            $table->decimal('transaction_fee_fixed', 10, 2)->default(0);
            $table->decimal('commission_rate', 5, 2);
            $table->integer('max_products')->default(100);
            $table->integer('max_stores')->default(1);
            $table->integer('max_users')->default(1);
            $table->integer('bandwidth_limit_mb')->nullable();
            $table->integer('storage_limit_mb')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->integer('trial_period_days')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_plans');
    }
};
