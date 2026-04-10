<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tracking', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('order_id')->references('uuid')->on('orders')->cascadeOnDelete();
            $table->foreignUuid('carrier_id')->nullable()->references('uuid')->on('couriers')->nullOnDelete();
            $table->string('tracking_number');
            $table->string('tracking_url', 500)->nullable();
            $table->string('label_url', 500)->nullable();
            $table->string('status', 50)->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_update')->nullable();
            $table->json('tracking_events')->nullable();
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('tracking_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking');
    }
};