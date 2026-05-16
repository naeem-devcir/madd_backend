<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id(); // Internal FK reference
            $table->uuid('uuid')->unique(); // Public access
            $table->unsignedBigInteger('vendor_id'); // Vendor isolation
            
            // Magento references
            $table->string('magento_id')->nullable()->unique()->comment('Magento customer ID');
            $table->string('magento_store_id')->nullable();
            $table->string('magento_website_id')->nullable();
            
            // Customer personal info
            $table->string('email')->unique();
            $table->string('firstname');
            $table->string('lastname');
            $table->string('middlename')->nullable();
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            
            // Account status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_confirmed')->default(false);
            $table->boolean('is_subscribed')->default(false);
            
            // Contact info
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            
            // Date of birth
            $table->date('dob')->nullable();
            
            // Gender
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            
            // Tax/VAT
            $table->string('taxvat')->nullable();
            
            // Group info
            $table->string('group_id')->nullable();
            $table->string('default_billing')->nullable();
            $table->string('default_shipping')->nullable();
            
            // Password management
            $table->string('password_hash')->nullable();
            $table->timestamp('password_updated_at')->nullable();
            
            // Additional data
            $table->json('addresses')->nullable(); // Store customer addresses
            $table->json('custom_attributes')->nullable();
            $table->json('magento_data')->nullable();
            
            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('magento_updated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes(); // Soft delete support
            
            // Indexes
            $table->index('vendor_id');
            $table->index('magento_id');
            $table->index('email');
            $table->index('firstname');
            $table->index('lastname');
            $table->index('is_active');
            $table->unique(['vendor_id', 'email']);
            $table->unique(['vendor_id', 'magento_id']);
            
            // Foreign key
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customers');
    }
}