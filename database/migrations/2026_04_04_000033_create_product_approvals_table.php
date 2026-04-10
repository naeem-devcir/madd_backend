<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // FIX: Add proper foreign keys
            $table->uuid('product_draft_id');
            $table->foreign('product_draft_id')->references('id')->on('product_drafts')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->references('uuid')->on('vendors')->cascadeOnDelete();
            
            $table->enum('approval_type', ['new', 'update', 'restore', 'delete'])->default('new');
            $table->enum('status', ['pending', 'approved', 'rejected', 'needs_modification'])->default('pending');
            $table->json('submitted_data');
            $table->text('admin_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->references('uuid')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('product_draft_id');
            $table->index(['vendor_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_approvals');
    }
};