<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCmsBlocksTable extends Migration
{
    public function up()
    {
        Schema::create('cms_blocks', function (Blueprint $table) {
            $table->id(); // Internal FK reference
            $table->uuid('uuid')->unique(); // Public access
            $table->unsignedBigInteger('vendor_id'); // Vendor isolation
            
            // Magento references
            $table->string('magento_id')->nullable()->unique()->comment('Magento CMS block ID');
            $table->string('magento_store_id')->nullable();
            
            // CMS Block data
            $table->string('identifier')->unique(); // Unique identifier for the block
            $table->string('title');
            $table->longText('content')->nullable(); // HTML content
            $table->boolean('is_active')->default(true);
            
            // Additional fields
            $table->json('meta_data')->nullable();
            $table->json('magento_data')->nullable();
            
            // Store assignments (if block is store-specific)
            $table->json('store_ids')->nullable(); // Array of store IDs where block is visible
            
            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('magento_updated_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('vendor_id');
            $table->index('magento_id');
            $table->index('identifier');
            $table->index('is_active');
            $table->unique(['vendor_id', 'identifier']);
            $table->unique(['vendor_id', 'magento_id']);
            
            // Foreign key
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cms_blocks');
    }
}