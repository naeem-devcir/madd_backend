<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // FIX: Add proper foreign key
            $table->foreignUuid('vendor_id')->unique()->references('uuid')->on('vendors')->cascadeOnDelete();
            
            $table->decimal('balance', 14, 4)->default(0.00);
            $table->decimal('reserved_balance', 14, 4)->default(0.00);
            $table->char('currency_code', 3)->default('EUR');
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_wallets');
    }
};