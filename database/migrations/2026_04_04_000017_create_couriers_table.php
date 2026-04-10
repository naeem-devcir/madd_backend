<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('code', 50)->unique();
            $table->string('api_type', 50);
            $table->json('credentials')->nullable();
            $table->json('countries');
            $table->json('service_levels')->nullable();
            $table->string('tracking_url_template')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->json('support_contact')->nullable();
            $table->json('settlement_contact')->nullable();
            $table->decimal('weight_limit_kg', 8, 2)->nullable();
            $table->json('insurance_options')->nullable();
            $table->boolean('data_processing_agreement')->default(false);
            $table->string('contract_reference')->nullable();
            $table->tinyInteger('settlement_due_day')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};