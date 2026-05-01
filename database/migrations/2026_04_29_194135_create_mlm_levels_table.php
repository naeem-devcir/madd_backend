<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mlm_levels', function (Blueprint $table) {
            $table->id();
            $table->integer('level')->unique(); // Level number (1,2,3...)
            $table->decimal('commission_percentage', 5, 2); // Commission % for this level (0.00 - 100.00)
            $table->integer('required_downline')->default(0); // Minimum downline count required
            $table->decimal('required_volume', 10, 2)->default(0); // Minimum sales volume required
            $table->decimal('bonus_amount', 10, 2)->default(0); // Bonus amount for reaching this level
            $table->string('name')->nullable(); // Optional: Level name (e.g., "Bronze", "Silver", "Gold")
            $table->text('description')->nullable(); // Optional: Level description
            $table->json('benefits')->nullable(); // Optional: Additional benefits as JSON
            $table->boolean('is_active')->default(true); // Whether this level is active
            $table->timestamps();
            
            // Indexes for better performance
            $table->index('level');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlm_levels');
    }
};