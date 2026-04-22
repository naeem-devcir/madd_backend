<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // foreignUuid: FK references uuid
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Company Info
            $table->string('company_name');
            $table->string('company_slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('vat_number', 50)->nullable()->index();
            $table->string('registration_number', 100)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website', 255)->nullable();
            $table->char('country_code', 2)->index();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 20);
            $table->string('logo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->text('description')->nullable();

            // Plan reference - vendor_plans uses BIGINT id
            $table->foreignId('plan_id')->nullable()->constrained('vendor_plans')->nullOnDelete();
            $table->timestamp('plan_starts_at')->nullable();
            $table->timestamp('plan_ends_at')->nullable();
            
            // ADD MISSING COLUMNS HERE
            $table->integer('plan_duration_months')->nullable()->after('plan_expires_at');

            // Commission
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->enum('commission_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('commission_override', 5, 2)->nullable();

            // Status & Onboarding
            $table->enum('status', ['pending', 'active', 'suspended', 'terminated'])->default('pending');
            $table->tinyInteger('onboarding_step')->default(1);

            // Financials
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('total_commission_paid', 15, 2)->default(0);
            $table->decimal('total_earned', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->decimal('pending_balance', 15, 2)->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('total_reviews')->default(0);

            // foreignUuid: FK references uuid
            $table->foreignId('mlm_referrer_id')->nullable()->constrained('users')->nullOnDelete();

            // Integrations
            $table->integer('magento_website_id')->nullable()->index();

            // KYC
            $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->json('verification_documents')->nullable();

            // foreignUuid: FK references uuid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('timezone', 50)->default('UTC');
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'country_code']);
            $table->index('created_at');
            $table->index('company_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};