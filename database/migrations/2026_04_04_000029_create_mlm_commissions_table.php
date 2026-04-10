<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_commissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // foreignUuid: FK references uuid
            $table->foreignUuid('agent_id')->references('uuid')->on('mlm_agents')->cascadeOnDelete();
            $table->foreignUuid('settlement_id')->nullable()->references('uuid')->on('settlements')->nullOnDelete();
            
            $table->enum('source_type', ['vendor_signup', 'vendor_sale']);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 12, 4);
            $table->char('currency_code', 3);
            $table->enum('status', ['pending', 'approved', 'paid', 'rejected']);
            $table->string('description', 255)->nullable();
            $table->json('calculation_snapshot')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['source_type', 'source_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commissions');
    }
};