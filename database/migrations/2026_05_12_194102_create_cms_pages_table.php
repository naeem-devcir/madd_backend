<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCmsPagesTable extends Migration
{
    public function up()
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id(); // Internal FK reference
            $table->uuid('uuid')->unique(); // Public access
            $table->unsignedBigInteger('vendor_id'); // Vendor isolation
            
            // Magento references
            $table->string('magento_id')->nullable()->unique()->comment('Magento CMS page ID');
            $table->string('magento_store_id')->nullable();
            
            // CMS Page data
            $table->string('identifier')->unique();
            $table->string('title');
            $table->string('page_layout')->nullable(); // 1column, 2columns-left, etc.
            $table->longText('content')->nullable();
            $table->string('content_heading')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            
            // SEO fields
            $table->string('meta_title')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->text('meta_description')->nullable();
            
            // Theme & Layout
            $table->string('custom_theme')->nullable();
            $table->string('custom_root_template')->nullable();
            $table->text('layout_update_xml')->nullable();
            $table->text('custom_layout_update_xml')->nullable();
            $table->date('custom_theme_from')->nullable();
            $table->date('custom_theme_to')->nullable();
            
            // JSON data
            $table->json('meta_data')->nullable();
            $table->json('magento_data')->nullable();
            
            // Store assignments
            $table->json('store_ids')->nullable();
            
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
        Schema::dropIfExists('cms_pages');
    }
}