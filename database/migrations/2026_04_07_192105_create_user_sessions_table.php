<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('token_jti')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('refresh_token_jti')->nullable();

            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('last_used_at')->nullable(); // ✅ added
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_revoked')->default(false); // ✅ added

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
