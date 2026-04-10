<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('order_id')->references('uuid')->on('orders')->cascadeOnDelete();
            $table->string('status', 50);
            $table->text('notes')->nullable();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('changed_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};