<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            $table->foreignUuid('user_id')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreignUuid('invited_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            $table->enum('role', ['admin', 'orders', 'products', 'marketing', 'seo']);
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->json('notification_prefs')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['vendor_id', 'user_id']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};