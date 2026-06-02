<?php
// database/migrations/2026_05_23_000001_create_attribute_sets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('attribute_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('magento_attr_set_id')->unique()->nullable();
            $table->string('attribute_set_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('entity_type_id')->default(4); // 4 = catalog_product
            $table->string('magento_entity_type_code')->default('catalog_product');

            // Attribute set metadata
            $table->text('description')->nullable();
            $table->json('assigned_attribute_ids')->nullable();
            $table->json('attribute_group_data')->nullable();

            // Sync tracking fields
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('pending'); // pending, synced, failed
            $table->text('sync_error_message')->nullable();
            $table->unsignedInteger('sync_attempts')->default(0);

            // Local customization fields
            $table->boolean('is_active')->default(true);
            $table->string('local_display_name')->nullable();
            $table->text('local_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for common queries
            $table->index(['vendor_id', 'sync_status']);
            $table->index(['vendor_id', 'is_active']);

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('magento_attribute_sets');
    }
};
