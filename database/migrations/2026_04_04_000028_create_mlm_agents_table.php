<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // foreignUuid: FK references uuid
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->tinyInteger('level')->default(1);
            $table->enum('territory_type', ['country', 'region', 'city']);
            $table->string('territory_code', 50);
            $table->decimal('commission_rate', 5, 2);
            $table->integer('total_vendors_recruited')->default(0);
            $table->decimal('total_commissions_earned', 14, 4)->default(0);
            $table->string('rank', 50)->default('starter');
            $table->string('phone', 30)->nullable();
            $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->enum('status', ['active', 'inactive', 'suspended']);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_agents');
    }
};
