<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table) {
            // role_id references roles.id (BIGINT) - use foreignId
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            // Polymorphic relationship - this is correct
            $table->uuidMorphs('model');

            // foreignUuid: assigned_by references users.uuid
            $table->foreignUuid('assigned_by')
                ->nullable()
                ->references('uuid')->on('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Unique constraint
            $table->unique(['role_id', 'model_id', 'model_type'], 'model_has_roles_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};