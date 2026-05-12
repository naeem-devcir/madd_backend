<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); // Internal FK reference
            $table->uuid('uuid')->unique(); // Public access
            $table->unsignedBigInteger('vendor_id'); // Vendor isolation
            
            // Magento references
            $table->string('magento_id')->nullable()->unique()->comment('Magento category ID');
            $table->string('magento_store_id')->nullable();
            
            // Category data
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('parent_path')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('include_in_menu')->default(true);
            $table->string('image_url')->nullable();
            
            // SEO
            $table->string('meta_title')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('meta_description')->nullable();
            
            // JSON data
            $table->json('meta_data')->nullable();
            $table->json('magento_data')->nullable();
            
            // Hierarchy
            $table->integer('level')->default(0);
            $table->integer('children_count')->default(0);
            
            // Sync tracking
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('magento_updated_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('vendor_id');
            $table->index('magento_id');
            $table->index('parent_id');
            $table->index('slug');
            $table->index('is_active');
            $table->index('level');
            $table->unique(['vendor_id', 'magento_id']);
            $table->unique(['vendor_id', 'slug']);
            
            // Foreign keys
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
}