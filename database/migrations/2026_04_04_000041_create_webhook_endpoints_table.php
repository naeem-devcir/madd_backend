<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // FIX: Add proper foreign key
            $table->foreignUuid('vendor_id')->nullable()->references('uuid')->on('vendors')->nullOnDelete();
            
            $table->string('url', 500);
            $table->string('secret');
            $table->json('events');
            $table->enum('format', ['json', 'xml'])->default('json');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->integer('failure_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};