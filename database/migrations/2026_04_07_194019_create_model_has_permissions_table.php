<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_permissions', function (Blueprint $table) {
            // Permission ID
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();
            
            // Polymorphic relationship
            $table->string('model_type');
            $table->unsignedBigInteger('model_id'); // Integer, not char
            
            // Who granted this permission - references users.id (bigint)
            $table->foreignUuid('granted_by')
                ->nullable()
                ->references('uuid')->on('users')
                ->nullOnDelete();
            
            // Timestamp
            $table->timestamp('granted_at')->useCurrent();
            
            // Primary key
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
            
            // Indexes for performance
            $table->index('model_id');
            $table->index('model_type');
            $table->index(['model_type', 'model_id']);
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};