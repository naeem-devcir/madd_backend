<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->uuid('store_id');
            $table->uuid('user_id')->nullable();

            // Search Data
            $table->string('query');
            $table->integer('results_count')->default(0);

            // Filters & Context
            $table->json('filters_applied')->nullable();
            $table->string('session_id')->nullable();

            // Tracking
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // Conversion Tracking
            $table->uuid('clicked_product')->nullable(); // product id
            $table->timestamp('clicked_at')->nullable();

            // Extra Analytics (optional but powerful 🔥)
            $table->integer('response_time_ms')->nullable(); // API speed
            $table->boolean('is_successful')->default(true); // results > 0

            $table->timestamps();

            // Indexing for performance ⚡
            $table->index('store_id');
            $table->index('user_id');
            $table->index('query');
        });
    }

    public function down()
    {
        Schema::dropIfExists('search_logs');
    }
};
