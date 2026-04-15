<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('store_id')->nullable()->constrained('vendor_stores')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('query');
            $table->integer('results_count')->default(0);
            $table->json('filters_applied')->nullable();
            $table->string('session_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('clicked_product')->nullable()->constrained('vendor_products')->nullOnDelete();
            $table->timestamp('clicked_at')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->boolean('is_successful')->default(true);
            $table->timestamps();

            $table->index('store_id');
            $table->index('user_id');
            $table->index('query');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
