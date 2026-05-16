<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerAddressesTable extends Migration
{
    public function up()
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('vendor_id');
            
            // Magento reference
            $table->string('magento_id')->nullable()->unique();
            $table->string('magento_customer_id')->nullable();
            
            // Address fields
            $table->string('firstname');
            $table->string('lastname');
            $table->string('middlename')->nullable();
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            $table->string('company')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('region_id')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country_id', 2);
            $table->string('telephone');
            $table->string('fax')->nullable();
            $table->string('vat_id')->nullable();
            
            // Address type
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_active')->default(true);
            
            // Additional data
            $table->json('custom_attributes')->nullable();
            $table->json('magento_data')->nullable();
            
            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('customer_id');
            $table->index('vendor_id');
            $table->index('magento_id');
            $table->index(['customer_id', 'is_default_billing']);
            $table->index(['customer_id', 'is_default_shipping']);
            
            // Foreign keys
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_addresses');
    }
}