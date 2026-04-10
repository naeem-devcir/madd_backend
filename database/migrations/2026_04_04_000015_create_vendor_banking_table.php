<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_banking', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            
            $table->enum('account_type', ['bank', 'paypal', 'stripe']);
            $table->string('bank_name')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic_swift', 11)->nullable();
            $table->string('paypal_email')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->string('account_holder_name');
            $table->char('currency_code', 3)->default('EUR');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_doc_path', 500)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('is_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_banking');
    }
};