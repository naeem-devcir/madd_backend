<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Use foreignId which matches users.id (BIGINT)
            $table->foreignUuid('user_id')
                ->references('uuid')->on('users')
                ->cascadeOnDelete();
            
            $table->string('provider', 50);
            $table->string('provider_id', 255);
            $table->string('provider_email', 190)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['provider', 'provider_id']);
            $table->index('user_id');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};