<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_configs', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name', 100);
            $table->string('phone_code', 10);
            $table->boolean('eu_member')->default(false);
            $table->char('currency_code', 3);
            $table->string('currency_symbol', 10);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('timezone', 50)->default('UTC');
            $table->json('language_codes')->nullable();
            $table->foreignId('madd_company_id')->nullable()->constrained('madd_companies')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_configs');
    }
};
