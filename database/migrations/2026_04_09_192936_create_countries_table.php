<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name'); // Pakistan
            $table->string('iso2', 2)->unique(); // PK
            $table->string('iso3', 3)->nullable(); // PAK

            // Codes
            $table->string('phone_code', 10)->nullable(); // +92
            $table->string('currency_code', 3)->nullable(); // PKR

            // Region Info
            $table->string('region')->nullable(); // Asia
            $table->string('subregion')->nullable(); // Southern Asia

            // Extra Metadata
            $table->string('capital')->nullable();
            $table->string('flag')->nullable(); // flag URL or emoji 🇵🇰

            // System Fields
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('iso2');
            $table->index('iso3');
        });
    }

    public function down()
    {
        Schema::dropIfExists('countries');
    }
};
