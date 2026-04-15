<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_stores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // foreignUuid: FK references uuid
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('store_name');
            $table->string('store_slug');
            $table->char('country_code', 2)->index();
            $table->string('language_code', 10)->default('en');
            $table->char('currency_code', 3)->default('EUR');
            $table->string('timezone', 100)->default('UTC');
            $table->unsignedBigInteger('domain_id')->nullable();
            $table->string('subdomain', 100)->nullable()->unique();
            $table->integer('magento_store_id')->nullable()->index();
            $table->integer('magento_store_group_id')->nullable()->index();
            $table->integer('magento_website_id')->nullable()->index();
            $table->unsignedBigInteger('theme_id')->nullable();
            $table->enum('status', ['inactive', 'active', 'suspended', 'maintenance'])->default('inactive');
            $table->unsignedBigInteger('sales_policy_id')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('seo_meta_title')->nullable();
            $table->text('seo_meta_description')->nullable();
            $table->json('seo_settings')->nullable();
            $table->json('payment_methods')->nullable();
            $table->json('shipping_methods')->nullable();
            $table->json('tax_settings')->nullable();
            $table->json('social_links')->nullable();
            $table->string('google_analytics_id', 50)->nullable();
            $table->string('facebook_pixel_id', 50)->nullable();
            $table->text('custom_css')->nullable();
            $table->text('custom_js')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->json('address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique(['vendor_id', 'store_slug']);
            $table->index(['status', 'country_code']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_stores');
    }
};
