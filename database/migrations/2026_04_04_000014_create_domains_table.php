<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('vendor_store_id')->nullable()->references('uuid')->on('vendor_stores')->nullOnDelete();
            
            $table->string('domain', 253)->unique();
            $table->enum('type', ['madd_subdomain', 'vendor_custom', 'marketplace']);
            $table->boolean('dns_verified')->default(false);
            $table->timestamp('dns_verified_at')->nullable();
            $table->string('verification_token')->nullable();
            $table->enum('ssl_status', ['pending', 'active', 'expired', 'failed'])->default('pending');
            $table->string('ssl_provider', 100)->nullable();
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->boolean('ssl_auto_renew')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->string('redirect_type', 10)->default('301');
            $table->boolean('www_redirect')->default(true);
            $table->string('registrar', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};